<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Viber broadcast — a message blasted to subscribed users via Viber's
 * broadcast_message API (≤300 receivers per call, drained in batches). Mirrors
 * LineBroadcast.
 */
class ViberBroadcast extends Model
{
    protected $table = 'viber_broadcasts';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'viber_channel_id', 'user_id', 'name', 'template_id', 'body', 'buttons',
        'media_path', 'media_kind', 'status', 'total', 'sent', 'failed', 'last_error', 'started_at', 'finished_at',
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
        return $this->hasMany(ViberBroadcastRecipient::class);
    }

    public function channel()
    {
        return $this->belongsTo(ViberChannel::class, 'viber_channel_id');
    }

    public function hasPending(): bool
    {
        return $this->recipients()->where('status', ViberBroadcastRecipient::STATUS_PENDING)->exists();
    }

    public function progress(): int
    {
        $total = max(1, (int) $this->total);

        return (int) round((($this->sent + $this->failed) / $total) * 100);
    }
}
