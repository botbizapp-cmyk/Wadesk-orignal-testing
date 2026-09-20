<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A live LINE flow session parked on a node awaiting the customer's reply or a
 * Delay wake-up. One session per conversation. The Node flow engine keeps its
 * own in-memory session map for the live walk (like the Telegram engine); this
 * row is the durable mirror used by PHP-side resume/diagnostics. Mirrors
 * TelegramFlowSession.
 */
class LineFlowSession extends Model
{
    public const STATUS_RUNNING  = 'running';
    public const STATUS_WAITING  = 'waiting';
    public const STATUS_SLEEPING = 'sleeping';
    public const STATUS_DONE     = 'done';
    public const STATUS_FAILED   = 'failed';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'line_channel_id', 'line_user_id', 'flow_id',
        'node_id', 'status', 'await_var', 'await_options', 'vars', 'last_error',
        'expires_at', 'last_message_id',
    ];

    protected $casts = [
        'vars'          => 'encrypted:array',
        'await_options' => 'array',
        'expires_at'    => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(LineChannel::class, 'line_channel_id');
    }

    /** Live = waiting or sleeping and not expired. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_SLEEPING], true)
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
