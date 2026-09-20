<?php

namespace App\Services\Sms\Transports;

use App\Services\Sms\Contracts\SmsTransport;
use App\Services\Sms\SmsSegments;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MSG91 — the domestic Indian route.
 *
 * WHY THIS EXISTS ALONGSIDE TWILIO. Indian carriers filter international A2P
 * SMS heavily: a US long code texting an Indian mobile is routinely dropped, or
 * arrives with a scrambled sender, and Twilio still reports it as accepted. The
 * fix is a domestic provider with DLT registration, a real sender id (`WADESK`),
 * and template ids the regulator approved.
 *
 * DLT (India TRAI) shapes the class: every commercial SMS is pre-registered —
 * the business, the sender id, and the exact template text. So there are two
 * modes: `flow` (DLT template id + variables — the only mode that reliably
 * delivers into India) and `plain` (free text — international / non-DLT). The
 * mode is chosen by whether the sender carries a DLT template id.
 *
 * NOT VERIFIED AGAINST A LIVE MSG91 ACCOUNT — endpoint shapes/error codes come
 * from MSG91's published v5 API; treat the first real send as the test.
 */
class Msg91Transport implements SmsTransport
{
    private const BASE = 'https://control.msg91.com/api';

    private const TIMEOUT = 20;

    public function __construct(private SmsSender $sender)
    {
    }

    public function provider(): string
    {
        return 'msg91';
    }

    public function send(string $to, string $body): array
    {
        $authKey = trim($this->sender->auth_token);
        if ($authKey === '') {
            return ['ok' => false, 'error' => 'This MSG91 number has no auth key — reconnect it on the devices page.'];
        }

        // MSG91 wants digits WITH the country code and WITHOUT a plus.
        $mobile = preg_replace('/\D+/', '', $to);
        if ($mobile === '') {
            return ['ok' => false, 'error' => 'That recipient number could not be read as a phone number.'];
        }

        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Nothing to send — the message is empty.'];
        }

        $dltTemplate = trim($this->sender->dlt_template_id);

        return $dltTemplate !== ''
            ? $this->sendFlow($authKey, $dltTemplate, $mobile, $body)
            : $this->sendPlain($authKey, $mobile, $body);
    }

    /** DLT route: a registered template id plus variables. */
    private function sendFlow(string $authKey, string $templateId, string $mobile, string $body): array
    {
        $payload = [
            'template_id' => $templateId,
            'short_url'   => '0',
            'recipients'  => [[
                'mobiles' => $mobile,
                // MSG91 flow templates substitute variables BY THEIR REGISTERED
                // NAME. MSG91's documented default first-variable name is VAR1, so
                // we fill that; we also pass `body` for templates registered with a
                // ##body## variable. MSG91 ignores any key the template doesn't
                // declare, so sending both fills whichever name the DLT template
                // actually uses — without the message text silently dropping out.
                'VAR1'    => $body,
                'body'    => $body,
            ]],
        ];

        if (($sid = trim($this->sender->sender_id)) !== '') {
            $payload['sender'] = $sid;
        }

        return $this->post('/v5/flow/', $authKey, $payload, $body);
    }

    /** Free-text route. Fine internationally; unreliable into India. */
    private function sendPlain(string $authKey, string $mobile, string $body): array
    {
        $payload = [
            'sender'  => trim($this->sender->sender_id) ?: 'MSGIND',
            // 4 = transactional. Promotional traffic on route 1 is refused
            // outright for Indian numbers without DLT.
            'route'   => '4',
            'country' => '0',
            'sms'     => [[
                'message' => $body,
                'to'      => [$mobile],
            ]],
        ];

        return $this->post('/v2/sendsms', $authKey, $payload, $body);
    }

    /**
     * One request, reduced to the shared shape. MSG91 answers 200 with
     * `{"type":"error"}` for real failures, so HTTP status alone is not the
     * outcome — reading only successful() would file every rejection as sent.
     */
    private function post(string $path, string $authKey, array $payload, string $body): array
    {
        try {
            $res = Http::withHeaders([
                'authkey'      => $authKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->timeout(self::TIMEOUT)->post(self::BASE . $path, $payload);
        } catch (\Throwable $e) {
            Log::warning('[SMS/MSG91] send threw: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not reach MSG91: ' . $e->getMessage()];
        }

        $json = (array) $res->json();
        $type = strtolower((string) ($json['type'] ?? ''));

        if ($res->successful() && $type !== 'error') {
            return [
                'ok'         => true,
                'message_id' => (string) ($json['request_id'] ?? $json['message'] ?? ''),
                'segments'   => SmsSegments::measure($body)['segments'],
            ];
        }

        $message = (string) ($json['message'] ?? $json['msg'] ?? ('HTTP ' . $res->status()));

        return [
            'ok'              => false,
            'code'            => (string) ($json['code'] ?? $res->status()),
            'bad_credentials' => $res->status() === 401
                || str_contains(strtolower($message), 'authkey')
                || str_contains(strtolower($message), 'authentication'),
            'error'           => $this->explain($message),
        ];
    }

    /** MSG91's wording, translated into something an operator can act on. */
    private function explain(string $message): string
    {
        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'authkey'), str_contains($m, 'authentication')
                => 'MSG91 rejected the auth key — check it on the devices page.',
            str_contains($m, 'dlt'), str_contains($m, 'template')
                => 'MSG91 rejected the DLT template id. The template must be approved on the DLT portal and linked to this sender id. (' . $message . ')',
            str_contains($m, 'sender')
                => 'MSG91 rejected the sender id. It must be exactly 6 letters and DLT-registered. (' . $message . ')',
            str_contains($m, 'balance'), str_contains($m, 'credit')
                => 'The MSG91 account is out of credit.',
            default => $message,
        };
    }

    /**
     * Confirm an auth key before storing it.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function verify(string $authKey): array
    {
        $authKey = trim($authKey);
        if ($authKey === '') {
            return ['ok' => false, 'error' => 'An MSG91 auth key is required.'];
        }

        try {
            // Balance is the cheapest authenticated read MSG91 exposes.
            $res = Http::withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
                ->timeout(12)->get(self::BASE . '/balance.php', ['type' => '4']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach MSG91: ' . $e->getMessage()];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'error' => 'MSG91 rejected that auth key (HTTP ' . $res->status() . ').'];
        }

        // A wrong key returns 200 with an error body rather than a 4xx.
        $text = strtolower(trim((string) $res->body()));
        if (str_contains($text, 'error') || str_contains($text, 'invalid')) {
            return ['ok' => false, 'error' => 'MSG91 rejected that auth key.'];
        }

        return ['ok' => true];
    }
}
