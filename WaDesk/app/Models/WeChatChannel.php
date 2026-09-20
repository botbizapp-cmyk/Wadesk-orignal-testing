<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A workspace's connected WeChat Official Account (certified Service Account).
 * Modelled on LineChannel; the WeChat-specific parts are the SHARED, cached
 * access_token (fetched from app_id+app_secret, 7200s TTL, refresh invalidates
 * the old one → cached + locked here) and the webhook verify_token / AES key.
 *
 * The channel that must send a reply for a thread is encoded in the
 * conversation's raw_jid ('wechat:<channelRowId>:<openid>'). app_secret,
 * encoding_aes_key and cached_access_token are encrypted at rest.
 */
class WeChatChannel extends Model
{
    protected $table = 'wechat_channels';

    protected $fillable = [
        'workspace_id', 'connected_by', 'app_id', 'app_secret', 'verify_token',
        'encoding_aes_key', 'enc_mode', 'webhook_token', 'wx_id', 'account_name',
        'avatar_url', 'cached_access_token', 'token_expires_at',
        'active', 'connected_at', 'last_inbound_at', 'last_error',
    ];

    protected $casts = [
        'app_secret'          => 'encrypted',
        'encoding_aes_key'    => 'encrypted',
        'cached_access_token' => 'encrypted',
        'token_expires_at'    => 'datetime',
        'active'              => 'boolean',
        'connected_at'        => 'datetime',
        'last_inbound_at'     => 'datetime',
    ];

    protected $hidden = ['app_secret', 'encoding_aes_key', 'cached_access_token'];

    /** Some active WeChat channel for a workspace — presence check. */
    public static function forWorkspace(int $workspaceId): ?self
    {
        return static::query()->where('workspace_id', $workspaceId)->where('active', true)->orderBy('id')->first();
    }

    /** Every WeChat channel a workspace has connected, stable order. */
    public static function allForWorkspace(int $workspaceId)
    {
        return static::query()->where('workspace_id', $workspaceId)->orderBy('id')->get();
    }

    /** Does this workspace have at least one connected WeChat channel? (request-cached) */
    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = static::where('workspace_id', $workspaceId)->where('active', true)->exists();
        }

        return $cache[$workspaceId];
    }

    /**
     * The WeChat channel that carried a thread — parses the row id from the
     * conversation's raw_jid ('wechat:<rowId>:<openid>'); falls back to
     * forWorkspace() for a legacy 2-part jid. Null when disconnected.
     */
    public static function forConversation(object $conversation): ?self
    {
        $wsId = (int) ($conversation->workspace_id ?? 0);
        if ($wsId <= 0) {
            return null;
        }
        $parts = explode(':', (string) ($conversation->raw_jid ?? ''));
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

    /** The URL WeChat must push to for this channel — FORCED https. */
    public function webhookUrl(): string
    {
        return preg_replace('#^http://#i', 'https://', url('/api/wechat/inbound/'.$this->webhook_token));
    }

    // ── access_token manager (shared token, cache + lock + refresh) ──────
    /**
     * A valid access_token for this OA. WeChat's token has a 7200s TTL and
     * fetching a NEW one invalidates the previous one, so we cache it on the row
     * and refresh under a lock — two concurrent webhooks must never both fetch
     * (they'd kill each other's token). Never throws; returns '' if unobtainable.
     */
    public function activeAccessToken(): string
    {
        if ($this->tokenFresh($this)) {
            return (string) $this->cached_access_token;
        }

        $lock = Cache::lock('wechat:token:'.$this->id, 15);
        try {
            if ($lock->block(10)) {
                // Re-read — another process may have refreshed while we waited.
                $fresh = $this->fresh();
                if ($fresh && $this->tokenFresh($fresh)) {
                    $this->cached_access_token = $fresh->cached_access_token;
                    $this->token_expires_at    = $fresh->token_expires_at;

                    return (string) $fresh->cached_access_token;
                }

                $res = \App\Services\WeChat\WeChatClient::fetchAccessToken((string) $this->app_id, (string) $this->app_secret);
                if ($res['ok'] ?? false) {
                    $this->forceFill([
                        'cached_access_token' => (string) $res['access_token'],
                        'token_expires_at'    => now()->addSeconds(max(60, (int) ($res['expires_in'] ?? 7200))),
                        'last_error'          => null,
                    ])->save();

                    return (string) $res['access_token'];
                }

                $this->forceFill(['last_error' => 'access_token: '.mb_substr((string) ($res['error'] ?? 'unknown'), 0, 200)])->save();
            }
        } catch (\Throwable $e) {
            // fall through to whatever token we have
        } finally {
            optional($lock)->release();
        }

        return (string) ($this->cached_access_token ?? '');
    }

    /** Force a refresh (used after a 40001/42001 token-invalid error mid-call). */
    public function refreshAccessToken(): string
    {
        $this->forceFill(['token_expires_at' => now()->subMinute()])->save();

        return $this->activeAccessToken();
    }

    private function tokenFresh(self $c): bool
    {
        return (string) $c->cached_access_token !== ''
            && $c->token_expires_at
            && $c->token_expires_at->gt(now()->addMinutes(5));
    }
}
