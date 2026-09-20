<?php

namespace App\Services\Sms\Transports;

use App\Services\Sms\Contracts\SmsTransport;
use App\Services\Sms\SmsSegments;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio SMS.
 *
 * SMS IS NOT WHATSAPP-OVER-TWILIO. Same account, same endpoint, but no
 * `whatsapp:` prefix, no 24-hour session window, no pre-approved templates and
 * no media on most international routes. The absent prefix is the entire
 * difference at the wire level, which is why this class is thin. The Twilio
 * credentials are the SAME ones the workspace already connected for WhatsApp —
 * SmsSender pulls them from the shared WaProviderConfig store.
 */
class TwilioTransport implements SmsTransport
{
    private const BASE = 'https://api.twilio.com/2010-04-01';

    private const TIMEOUT = 20;

    public function __construct(private SmsSender $sender)
    {
    }

    public function provider(): string
    {
        return 'twilio';
    }

    public function send(string $to, string $body): array
    {
        $sid   = trim($this->sender->account_sid);
        $token = trim($this->sender->auth_token);

        if ($sid === '' || $token === '') {
            return ['ok' => false, 'error' => 'This SMS number has no credentials — reconnect it on the devices page.'];
        }

        $to = self::e164($to);
        if ($to === '') {
            return ['ok' => false, 'error' => 'That recipient number could not be read as a phone number.'];
        }

        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Nothing to send — the message is empty.'];
        }

        $payload = [
            // An alphanumeric sender id must survive verbatim; only a numeric
            // FROM gets normalised to E.164.
            'From' => $this->sender->isAlphanumeric()
                ? trim($this->sender->from_number)
                : self::e164($this->sender->from_number),
            'To'   => $to,
            'Body' => $body,
        ];

        // Without a StatusCallback Twilio never reports delivered/failed and
        // every SMS sits at 'sent' forever. Points at OUR SMS status receiver.
        $base = rtrim((string) config('app.url'), '/');
        if ($base !== '') {
            $payload['StatusCallback'] = preg_replace('#^http://#i', 'https://', $base) . '/api/sms/status';
        }

        try {
            $res = Http::withBasicAuth($sid, $token)->asForm()->timeout(self::TIMEOUT)
                ->post(self::BASE . "/Accounts/{$sid}/Messages.json", $payload);
        } catch (\Throwable $e) {
            Log::warning('[SMS/Twilio] send threw: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not reach the SMS provider: ' . $e->getMessage()];
        }

        if ($res->successful()) {
            $json = (array) $res->json();

            return [
                'ok'         => true,
                'message_id' => (string) ($json['sid'] ?? ''),
                // Twilio's own count is authoritative over ours — it knows about
                // carrier transcoding we do not.
                'segments'   => (int) ($json['num_segments'] ?? SmsSegments::measure($body)['segments']),
            ];
        }

        $json = (array) $res->json();
        $code = (int) ($json['code'] ?? 0);

        return [
            'ok'              => false,
            'code'            => $code,
            'bad_credentials' => in_array($res->status(), [401, 403], true),
            'error'           => self::explain($code, (string) ($json['message'] ?? 'HTTP ' . $res->status())),
        ];
    }

    /**
     * Confirm a credential pair before storing it.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function verify(string $accountSid, string $authToken): array
    {
        $accountSid = trim($accountSid);

        if ($accountSid === '' || trim($authToken) === '') {
            return ['ok' => false, 'error' => 'Account SID and Auth Token are both required.'];
        }

        // The commonest paste error, worth its own sentence: an API Key (SK…)
        // cannot be used here, because the SID is also the URL path segment.
        if (! str_starts_with($accountSid, 'AC')) {
            return [
                'ok'    => false,
                'error' => 'That is not an Account SID. It must start with "AC" — an API Key starting "SK" will not work here. '
                    . 'Copy the Account SID from the Twilio console home page.',
            ];
        }

        try {
            $res = Http::withBasicAuth($accountSid, $authToken)->timeout(12)
                ->get(self::BASE . "/Accounts/{$accountSid}.json");
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Twilio: ' . $e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Twilio rejected those credentials: '
                . (string) (data_get($res->json(), 'message') ?: 'HTTP ' . $res->status())];
    }

    /**
     * Turn a provider error code into something an operator can act on.
     * Twilio's own text is accurate and unhelpful — "Invalid template name" on
     * a plain text message reads like a bug until you know that trial accounts
     * are template-only.
     */
    public static function explain(int $code, string $fallback): string
    {
        return match ($code) {
            572006 => 'Twilio trial accounts can only send their own preset templates, so this message was refused. '
                . 'Add billing to the Twilio account to send your own text.',
            21608 => 'Twilio trial accounts can only send to numbers you have verified. '
                . 'Verify this recipient in the Twilio console, or add billing to remove the restriction.',
            21211 => 'Twilio could not read that recipient number. It must be in full international format, e.g. +919876543210.',
            21606, 21212 => 'The sending number is not valid for SMS on this Twilio account. '
                . 'Check the number is SMS-capable in the Twilio console.',
            21610 => 'This recipient has replied STOP and is unsubscribed. Carriers require they text START before you can message them again.',
            21614 => 'That recipient number cannot receive SMS.',
            default => $fallback,
        };
    }

    /** Digits to +E164. Returns '' when there is nothing usable. */
    public static function e164(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);

        return $digits === '' ? '' : '+' . $digits;
    }
}
