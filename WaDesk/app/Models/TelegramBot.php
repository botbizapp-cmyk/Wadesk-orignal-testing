<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A workspace's connected Telegram bot (Bot API). Merged from the standalone
 * WaDesk Telegram integration, adapted to our core-channel pattern. A workspace
 * may connect several bots; the reply-bot for a thread is encoded in the
 * conversation's raw_jid ('tg:<botId>:<chatId>'). Tokens encrypted at rest.
 */
class TelegramBot extends Model
{
    protected $fillable = [
        'workspace_id', 'connected_by', 'bot_token', 'bot_username', 'bot_name', 'bot_id',
        'webhook_token', 'secret_token', 'active', 'connected_at', 'last_inbound_at', 'last_error',
        'payment_provider_token', 'payment_provider',
    ];

    protected $casts = [
        'bot_token'              => 'encrypted',
        'payment_provider_token' => 'encrypted',
        'active'                 => 'boolean',
        'connected_at'           => 'datetime',
        'last_inbound_at'        => 'datetime',
    ];

    protected $hidden = ['bot_token', 'secret_token', 'payment_provider_token'];

    public function scopeForWorkspaceScope($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    /** Some active bot for a workspace — a presence check ("is Telegram usable here?"). */
    public static function forWorkspace(int $workspaceId): ?self
    {
        return static::query()->where('workspace_id', $workspaceId)->where('active', true)->orderBy('id')->first();
    }

    /** Every bot a workspace has connected, stable order. */
    public static function allForWorkspace(int $workspaceId)
    {
        return static::query()->where('workspace_id', $workspaceId)->orderBy('id')->get();
    }

    /** Does this workspace have at least one connected Telegram bot? */
    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = static::where('workspace_id', $workspaceId)->where('active', true)->exists();
        }

        return $cache[$workspaceId];
    }

    /**
     * The bot that carried a thread — the one whose token must send the reply.
     * Parses the bot DB id from the conversation's raw_jid ('tg:<botId>:<chatId>');
     * falls back to forWorkspace() for pre-multi-bot threads keyed 'tg:<chatId>'.
     * Returns null when the stamped bot has been disconnected (caller surfaces it).
     */
    public static function forConversation(object $conversation): ?self
    {
        $wsId = (int) ($conversation->workspace_id ?? 0);
        if ($wsId <= 0) {
            return null;
        }
        $parts = explode(':', (string) ($conversation->raw_jid ?? ''));
        // tg : botId : chatId  → parts[1] is the bot id (numeric). A 2-part
        // 'tg:<chat>' jid predates multi-bot → forWorkspace fallback.
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

    /** Fresh, URL-safe routing + header secrets. */
    public static function freshTokens(): array
    {
        return [Str::random(48), Str::random(48)];
    }

    public function connector()
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** The URL Telegram must push to for this bot — FORCED https (Telegram rejects http). */
    public function webhookUrl(): string
    {
        return preg_replace('#^http://#i', 'https://', url('/api/telegram/inbound/'.$this->webhook_token));
    }
}
