<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A LINE broadcast — one message blasted to the OA's reachable audience (every
 * user already in an inbox thread with it). A LINE OA has no phone-number
 * audience, so it ships its own broadcast pipeline separate from core WA
 * campaigns (mirrors TelegramBroadcast). Sends go out via push per recipient so
 * every row gets its own delivery state + inbox mirror.
 */
class LineBroadcast extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'line_channel_id', 'user_id', 'name', 'template_id', 'body', 'buttons',
        'media_path', 'media_kind', 'status',
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
        return $this->hasMany(LineBroadcastRecipient::class);
    }

    public function channel()
    {
        return $this->belongsTo(LineChannel::class, 'line_channel_id');
    }

    public function hasPending(): bool
    {
        return $this->recipients()->where('status', LineBroadcastRecipient::STATUS_PENDING)->exists();
    }

    /** Progress % (blocked counts as done — nothing more to do for them). */
    public function progress(): int
    {
        $total = max(1, (int) $this->total);

        return (int) round((($this->sent + $this->failed + $this->blocked) / $total) * 100);
    }
}
