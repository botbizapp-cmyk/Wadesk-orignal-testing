<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A live Viber flow session parked on a node. One per conversation. The Node flow
 * engine keeps its own in-memory map; this row is the durable mirror. Mirrors
 * LineFlowSession.
 */
class ViberFlowSession extends Model
{
    protected $table = 'viber_flow_sessions';

    public const STATUS_RUNNING  = 'running';
    public const STATUS_WAITING  = 'waiting';
    public const STATUS_SLEEPING = 'sleeping';
    public const STATUS_DONE     = 'done';
    public const STATUS_FAILED   = 'failed';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'viber_channel_id', 'viber_user_id', 'flow_id',
        'node_id', 'status', 'await_var', 'await_options', 'vars', 'last_error', 'expires_at', 'last_message_id',
    ];

    protected $casts = [
        'vars'          => 'encrypted:array',
        'await_options' => 'array',
        'expires_at'    => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(ViberChannel::class, 'viber_channel_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_SLEEPING], true)
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
