<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A logged-in Telegram USER account (MTProto). Used only to create bots via
 * @BotFather (Telegram has no API to create a bot) — the session is the most
 * sensitive credential in the app, encrypted AND hidden from serialization.
 */
class TelegramAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'connected_by', 'session', 'tg_user_id', 'username',
        'first_name', 'phone', 'last_error', 'last_checked_at', 'connected_at',
    ];

    protected $casts = [
        'session'         => 'encrypted',
        'last_checked_at' => 'datetime',
        'connected_at'    => 'datetime',
    ];

    protected $hidden = ['session'];

    public static function forWorkspace(int $workspaceId): ?self
    {
        return static::query()->where('workspace_id', $workspaceId)->orderByDesc('id')->first();
    }

    public static function allForWorkspace(int $workspaceId)
    {
        return static::query()->where('workspace_id', $workspaceId)->orderBy('id')->get();
    }

    /** Tenant-scoped find. Null/blank id falls back to the workspace's account. */
    public static function scopedFind(int $workspaceId, $id): ?self
    {
        if ($id === null || $id === '') {
            return static::forWorkspace($workspaceId);
        }

        return static::query()->where('workspace_id', $workspaceId)->whereKey((int) $id)->first();
    }

    public function connector()
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function hasSession(): bool
    {
        return trim((string) $this->session) !== '';
    }

    public function label(): string
    {
        return $this->username ? '@'.ltrim($this->username, '@') : ($this->first_name ?: ($this->phone ?: 'TG account'));
    }
}
