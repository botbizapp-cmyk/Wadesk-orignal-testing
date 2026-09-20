<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Telegram broadcast — a one-off message blasted to the bot's reachable
 * audience (existing inbox threads). Telegram has no phone-number audience, so
 * it ships its own broadcast pipeline separate from core campaigns.
 */
class TelegramBroadcast extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'telegram_bot_id', 'user_id', 'name', 'template_id', 'body', 'buttons',
        'media_path', 'media_kind', 'media_file_id', 'status',
        'total', 'sent', 'failed', 'blocked', 'last_error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'body'        => 'encrypted',
        'buttons'     => 'encrypted:array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = ['body'];

    public function recipients()
    {
        return $this->hasMany(TelegramBroadcastRecipient::class);
    }

    public function bot()
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function hasPending(): bool
    {
        return $this->recipients()->where('status', TelegramBroadcastRecipient::STATUS_PENDING)->exists();
    }

    /** Progress % (blocked counts as done — nothing more to do for them). */
    public function progress(): int
    {
        $total = max(1, (int) $this->total);

        return (int) round((($this->sent + $this->failed + $this->blocked) / $total) * 100);
    }
}
