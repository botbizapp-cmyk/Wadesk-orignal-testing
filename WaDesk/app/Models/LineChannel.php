<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A workspace's connected LINE Official Account (Messaging API channel). Modelled
 * on TelegramBot: a workspace may connect several channels; the channel that must
 * send a reply for a thread is encoded in the conversation's raw_jid
 * ('line:<lineChannelRowId>:<userId|groupId|roomId>'). channel_access_token +
 * channel_secret are encrypted at rest — channel_secret is the HMAC key we use to
 * verify the inbound X-Line-Signature.
 */
class LineChannel extends Model
{
    protected $fillable = [
        'workspace_id', 'connected_by', 'channel_access_token', 'channel_secret',
        'assertion_kid', 'assertion_private_key', 'rotating_token', 'rotating_token_key_id',
        'rotating_token_expires_at',
        'webhook_token', 'line_channel_id', 'basic_id', 'display_name', 'picture_url',
        'active', 'connected_at', 'last_inbound_at', 'last_error',
    ];

    protected $casts = [
        'channel_access_token'      => 'encrypted',
        'channel_secret'            => 'encrypted',
        'assertion_private_key'     => 'encrypted',
        'rotating_token'            => 'encrypted',
        'rotating_token_expires_at' => 'datetime',
        'active'                    => 'boolean',
        'connected_at'              => 'datetime',
        'last_inbound_at'           => 'datetime',
    ];

    protected $hidden = ['channel_access_token', 'channel_secret', 'assertion_private_key', 'rotating_token'];

    public function scopeForWorkspaceScope($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    /** Some active LINE channel for a workspace — presence check. */
    public static function forWorkspace(int $workspaceId): ?self
    {
        return static::query()->where('workspace_id', $workspaceId)->where('active', true)->orderBy('id')->first();
    }

    /** Every LINE channel a workspace has connected, stable order. */
    public static function allForWorkspace(int $workspaceId)
    {
        return static::query()->where('workspace_id', $workspaceId)->orderBy('id')->get();
    }

    /** Does this workspace have at least one connected LINE channel? (request-cached) */
    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = static::where('workspace_id', $workspaceId)->where('active', true)->exists();
        }

        return $cache[$workspaceId];
    }

    /**
     * The LINE channel that carried a thread — the one whose token must send the
     * reply. Parses the row id from the conversation's raw_jid
     * ('line:<rowId>:<userId>'); falls back to forWorkspace() for any legacy
     * 2-part jid. Returns null when the stamped channel was disconnected.
     */
    public static function forConversation(object $conversation): ?self
    {
        $wsId = (int) ($conversation->workspace_id ?? 0);
        if ($wsId <= 0) {
            return null;
        }
        $parts = explode(':', (string) ($conversation->raw_jid ?? ''));
        // line : rowId : userId  → parts[1] is the row id (numeric).
        if (count($parts) >= 3 && ctype_digit((string) $parts[1])) {
            return static::query()->where('workspace_id', $wsId)->where('id', (int) $parts[1])->first();
        }

        return static::forWorkspace($wsId);
    }

    /** Resolve an inbound push by its URL routing token (no active filter). */
    public static function byWebhookToken(string $token): ?self
    {
        return $token === '' ? null : static::query()->where('webhook_token', $token)->first();
    }

    /** Fresh, URL-safe routing token for the inbound webhook path. */
    public static function freshWebhookToken(): string
    {
        return Str::random(48);
    }

    public function connector()
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** The URL LINE must push to for this channel — FORCED https. */
    public function webhookUrl(): string
    {
        return preg_replace('#^http://#i', 'https://', url('/api/line/inbound/'.$this->webhook_token));
    }

    // ── Token rotation (v2.1 JWT) ────────────────────────────────────────
    /** True when this channel is set up for auto-rotating v2.1 tokens. */
    public function rotationEnabled(): bool
    {
        return trim((string) $this->assertion_kid) !== '' && trim((string) $this->assertion_private_key) !== '';
    }

    /**
     * The access token every LINE call should use. When rotation is enabled it
     * returns the cached v2.1 token, minting a fresh one lazily when it is
     * missing or within a day of expiry (self-healing, no cron). Otherwise it
     * returns the pasted long-lived token. Never throws — falls back to the
     * long-lived token if a rotation attempt fails.
     */
    public function activeAccessToken(): string
    {
        if (! $this->rotationEnabled()) {
            return (string) $this->channel_access_token;
        }

        $fresh = $this->rotating_token
            && $this->rotating_token_expires_at
            && $this->rotating_token_expires_at->isFuture()
            && $this->rotating_token_expires_at->diffInHours(now()) > 24;

        if ($fresh) {
            return (string) $this->rotating_token;
        }

        return $this->rotateToken() ?: (string) ($this->rotating_token ?: $this->channel_access_token);
    }

    /**
     * Mint a fresh v2.1 token from the stored assertion key and cache it. Returns
     * the new token, or '' on failure (the caller then falls back). Requires
     * `line_channel_id` (the numeric channel id) as the JWT iss/sub.
     */
    public function rotateToken(): string
    {
        $channelId = trim((string) $this->line_channel_id);
        if ($channelId === '' || ! $this->rotationEnabled()) {
            return '';
        }

        $jwt = \App\Services\Line\LineClient::buildAssertionJwt(
            $channelId,
            (string) $this->assertion_kid,
            (string) $this->assertion_private_key,
        );
        if ($jwt === '') {
            $this->forceFill(['last_error' => 'Token rotation: could not sign the assertion JWT (bad key).'])->save();

            return '';
        }

        $res = \App\Services\Line\LineClient::issueChannelAccessTokenV21($jwt);
        if (! ($res['ok'] ?? false) || ($res['access_token'] ?? '') === '') {
            $this->forceFill(['last_error' => 'Token rotation failed: '.mb_substr((string) ($res['error'] ?? 'unknown'), 0, 200)])->save();

            return '';
        }

        $this->forceFill([
            'rotating_token'            => (string) $res['access_token'],
            'rotating_token_key_id'     => (string) ($res['key_id'] ?? ''),
            'rotating_token_expires_at' => now()->addSeconds(max(60, (int) ($res['expires_in'] ?? 2592000))),
            'last_error'                => null,
        ])->save();

        return (string) $res['access_token'];
    }
}
