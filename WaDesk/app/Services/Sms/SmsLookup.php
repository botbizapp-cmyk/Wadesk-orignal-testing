<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Is this number worth texting?
 *
 * SMS bills on SUBMISSION, not delivery — a text to a landline or a dead number
 * is charged exactly like one that arrives. On a list built from years of web
 * forms, the few percent that are landlines/typos are money burnt every
 * campaign. Twilio's Lookup answers "mobile / landline / invalid + carrier" for
 * a fraction of a send. Cached hard (a line type effectively never changes and
 * Lookup is billed per call).
 */
class SmsLookup
{
    private const BASE = 'https://lookups.twilio.com/v2/PhoneNumbers/';

    private const TIMEOUT = 12;

    private const CACHE_DAYS = 90;

    public function __construct(private SmsSender $sender)
    {
    }

    /**
     * @return array{ok:bool, valid:bool, type:?string, carrier:?string, country:?string, textable:bool, reason:?string, cached:bool}
     */
    public function check(string $phone, bool $withCarrier = true): array
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return $this->result(false, false, null, null, null, 'That is not a phone number.');
        }

        $key = 'sms.lookup.' . $digits . ($withCarrier ? '.c' : '');
        if (($hit = Cache::get($key)) !== null) {
            return array_merge($hit, ['cached' => true]);
        }

        // Lookup authenticates on the Twilio ACCOUNT credentials — MSG91 senders
        // can't use it, so say so rather than failing with a confusing 401.
        if (strtolower($this->sender->provider) !== 'twilio') {
            return $this->result(false, false, null, null, null,
                'Number checking needs a Twilio account; this number is on ' . $this->sender->provider . '.');
        }

        try {
            $res = Http::withBasicAuth($this->sender->account_sid, $this->sender->auth_token)
                ->timeout(self::TIMEOUT)
                ->get(self::BASE . '+' . $digits, $withCarrier ? ['Fields' => 'line_type_intelligence'] : []);
        } catch (\Throwable $e) {
            Log::warning('[SMS-LOOKUP] failed: ' . $e->getMessage());

            return $this->result(false, false, null, null, null, 'Could not reach the lookup service.');
        }

        if (! $res->successful()) {
            $msg = (string) (data_get($res->json(), 'message') ?: 'HTTP ' . $res->status());
            if ($res->status() === 404) {
                $out = $this->result(true, false, null, null, null, 'Twilio does not recognise this number.');
                Cache::put($key, $out, now()->addDays(self::CACHE_DAYS));

                return $out;
            }

            return $this->result(false, false, null, null, null, $msg);
        }

        $j    = (array) $res->json();
        $type = data_get($j, 'line_type_intelligence.type');

        $out = $this->result(
            true,
            (bool) ($j['valid'] ?? false),
            $type ? (string) $type : null,
            data_get($j, 'line_type_intelligence.carrier_name'),
            $j['country_code'] ?? null,
            null
        );

        Cache::put($key, $out, now()->addDays(self::CACHE_DAYS));

        return $out;
    }

    private function result(bool $ok, bool $valid, ?string $type, ?string $carrier, ?string $country, ?string $reason): array
    {
        return [
            'ok'       => $ok,
            'valid'    => $valid,
            'type'     => $type,
            'carrier'  => $carrier ? (string) $carrier : null,
            'country'  => $country ? (string) $country : null,
            // An unknown type counts as textable: Lookup doesn't always return a
            // line type, and refusing everyone it can't classify would gut a
            // list. Only a POSITIVE landline/voip finding (or invalid) is "no".
            'textable' => $ok && $valid && ! in_array((string) $type, ['landline', 'voip'], true),
            'reason'   => $reason,
            'cached'   => false,
        ];
    }

    /** A sentence for the UI. */
    public static function describe(array $r): string
    {
        if (! ($r['ok'] ?? false)) {
            return (string) ($r['reason'] ?? 'Could not check this number.');
        }
        if (! ($r['valid'] ?? false)) {
            return 'Not a valid number — texting it would be charged and never arrive.';
        }

        $type    = (string) ($r['type'] ?? '');
        $carrier = (string) ($r['carrier'] ?? '');
        $head = match ($type) {
            'mobile'   => 'Mobile — can receive SMS.',
            'landline' => 'Landline — cannot receive SMS. Texting it is charged and lost.',
            'voip'     => 'VoIP — may not receive SMS reliably.',
            ''         => 'Valid number. Twilio did not report a line type.',
            default    => 'Valid ' . $type . ' number.',
        };

        return $carrier !== '' ? $head . ' Carrier: ' . $carrier . '.' : $head;
    }
}
