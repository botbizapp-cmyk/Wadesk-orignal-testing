<?php

namespace App\Services\Sms;

use App\Models\WaProviderConfig;

/**
 * A connected SMS number — a thin value object over a `WaProviderConfig`
 * row with provider='sms'.
 *
 * WHY A VALUE OBJECT, NOT AN ELOQUENT MODEL. SMS reuses the SAME device/provider
 * store every other channel uses (WaProviderConfig), so there is no `sms_senders`
 * table. The transports (TwilioTransport / Msg91Transport) only ever read a
 * handful of fields off "the sender", so this wraps a config row and exposes
 * exactly those — keeping the transports provider-store-agnostic.
 *
 * STORAGE SPLIT on the WaProviderConfig row:
 *   - phone_number       → the FROM number (queryable, NOT encrypted, so an
 *                          inbound webhook can find the row by it)
 *   - credentials_json   → { account_sid, auth_token }  (encrypted at rest)
 *   - meta_json          → { sms_provider, sender_id, dlt_template_id,
 *                            rate_per_segment, currency }  (non-secret)
 */
class SmsSender
{
    public function __construct(
        public string $provider,          // 'twilio' | 'msg91'
        public string $from_number,
        public string $account_sid,
        public string $auth_token,
        public string $sender_id = '',
        public string $dlt_template_id = '',
        public ?string $rate_per_segment = null,
        public string $currency = 'USD',
        public bool $active = true,
        public ?int $config_id = null,
        public ?int $workspace_id = null,
        public ?WaProviderConfig $config = null,
    ) {}

    /** Build from a provider='sms' WaProviderConfig row (null-safe). */
    public static function fromConfig(?WaProviderConfig $cfg): ?self
    {
        if (! $cfg || $cfg->provider !== 'sms') {
            return null;
        }

        $creds = $cfg->creds();
        $meta  = is_array($cfg->meta_json) ? $cfg->meta_json : [];

        return new self(
            provider:        strtolower(trim((string) ($meta['sms_provider'] ?? 'twilio'))) ?: 'twilio',
            from_number:     (string) ($cfg->phone_number ?: ($creds['from_number'] ?? '')),
            account_sid:     (string) ($creds['account_sid'] ?? ''),
            auth_token:      (string) ($creds['auth_token'] ?? ''),
            sender_id:       (string) ($meta['sender_id'] ?? ''),
            dlt_template_id: (string) ($meta['dlt_template_id'] ?? ''),
            rate_per_segment:isset($meta['rate_per_segment']) && $meta['rate_per_segment'] !== '' ? (string) $meta['rate_per_segment'] : null,
            currency:        (string) ($meta['currency'] ?? 'USD'),
            active:          $cfg->status === WaProviderConfig::STATUS_CONNECTED,
            config_id:       (int) $cfg->id,
            workspace_id:    (int) $cfg->workspace_id,
            config:          $cfg,
        );
    }

    /** The workspace's active SMS sender (its primary, else the first connected). */
    public static function firstForWorkspace(int $workspaceId): ?self
    {
        if ($workspaceId <= 0) {
            return null;
        }
        $cfg = WaProviderConfig::query()->forWorkspace($workspaceId)->connected()
            ->where('provider', 'sms')->orderByDesc('is_primary')->orderBy('id')->first();

        return self::fromConfig($cfg);
    }

    /**
     * Resolve the SMS sender an inbound/status webhook is addressed to.
     *
     * Matched on DIGITS: a provider reports `To` as `+17372508034` while an
     * operator may have typed `1 737 250 8034`. `phone_number` is deliberately
     * NOT encrypted, which is exactly why we can find the row by it here.
     */
    public static function byNumber(string $number, ?int $workspaceId = null): ?self
    {
        $digits = preg_replace('/\D+/', '', $number);
        if ($digits === '') {
            return null;
        }

        $cfg = WaProviderConfig::query()
            ->where('provider', 'sms')
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get()
            ->first(function (WaProviderConfig $c) use ($digits) {
                $mine = preg_replace('/\D+/', '', (string) $c->phone_number);

                return $mine !== ''
                    && ($mine === $digits || str_ends_with($mine, $digits) || str_ends_with($digits, $mine));
            });

        return self::fromConfig($cfg);
    }

    /** Is there enough here to actually call the provider? */
    public function isSendable(): bool
    {
        // MSG91 authenticates on a single auth key (carried in auth_token); it
        // has no account_sid, so only Twilio requires the SID.
        $hasCreds = $this->provider === 'msg91'
            ? trim($this->auth_token) !== ''
            : (trim($this->account_sid) !== '' && trim($this->auth_token) !== '');

        return $this->active && trim($this->from_number) !== '' && $hasCreds;
    }

    /**
     * Is the sending identity an alphanumeric sender id rather than a number?
     * Those are one-way — carriers that accept 'WADESK' as a FROM give the
     * recipient no number to reply to, so inbound never arrives.
     */
    public function isAlphanumeric(): bool
    {
        return preg_match('/[A-Za-z]/', $this->from_number) === 1;
    }

    public function label(): string
    {
        return $this->from_number !== '' ? $this->from_number : ('SMS #' . ($this->config_id ?? 0));
    }

    /** Where the provider should POST inbound messages (forced https). */
    public function webhookUrl(): string
    {
        return preg_replace('#^http://#i', 'https://', url('/api/sms/inbound'));
    }

    /** Delivery-report (DLR) webhook — MSG91 is configured with this in its panel;
     *  Twilio sets it per-message automatically. */
    public function statusUrl(): string
    {
        return preg_replace('#^http://#i', 'https://', url('/api/sms/status'));
    }
}
