<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A workspace's connected Viber Public Account / Bot. Modelled on LineChannel; the
 * auth token is STATIC (no exchange/expiry) and doubles as the HMAC key for the
 * webhook X-Viber-Content-Signature. The channel that must send a reply for a
 * thread is encoded in the conversation's raw_jid
 * ('viber:<channelRowId>:<viberUserId>').
 */
class ViberChannel extends Model
{
    protected $table = 'viber_channels';

    protected $fillable = [
        'workspace_id', 'connected_by', 'auth_token', 'webhook_token', 'viber_id',
        'bot_name', 'bot_uri', 'bot_avatar', 'active', 'connected_at', 'last_inbound_at', 'last_error',
    ];

    protected $casts = [
        'auth_token'      => 'encrypted',
        'active'          => 'boolean',
        'connected_at'    => 'datetime',
        'last_inbound_at' => 'datetime',
    ];

    protected $hidden = ['auth_token'];

    public static function forWorkspace(int $workspaceId): ?self
    {
        return static::query()->where('workspace_id', $workspaceId)->where('active', true)->orderBy('id')->first();
    }

    public static function allForWorkspace(int $workspaceId)
    {
        return static::query()->where('workspace_id', $workspaceId)->orderBy('id')->get();
    }

    /** Does this workspace have at least one connected Viber channel? (request-cached) */
    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = static::where('workspace_id', $workspaceId)->where('active', true)->exists();
        }

        return $cache[$workspaceId];
    }

    /**
     * The Viber channel that carried a thread — parses the row id from the
     * conversation's raw_jid ('viber:<rowId>:<userId>'); falls back to
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

    public static function byWebhookToken(string $token): ?self
    {
        return $token === '' ? null : static::query()->where('webhook_token', $token)->first();
    }

    public static function freshWebhookToken(): string
    {
        return Str::random(48);
    }

    public function connector()
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** The URL Viber must push to for this channel — FORCED https (Viber needs a valid CA cert). */
    public function webhookUrl(): string
    {
        return preg_replace('#^http://#i', 'https://', url('/api/viber/inbound/'.$this->webhook_token));
    }

    /** The sender object Viber requires on every send. */
    public function senderObject(): array
    {
        return array_filter([
            'name'   => $this->bot_name ?: 'Bot',
            'avatar' => $this->bot_avatar ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
