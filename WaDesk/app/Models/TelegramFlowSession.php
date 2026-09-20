<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A live Telegram flow session parked on a node awaiting the customer's reply,
 * a Delay wake-up, a poll answer, or a payment. One session per conversation.
 */
class TelegramFlowSession extends Model
{
    public const STATUS_RUNNING  = 'running';
    public const STATUS_WAITING  = 'waiting';
    public const STATUS_SLEEPING = 'sleeping';
    public const STATUS_DONE     = 'done';
    public const STATUS_FAILED   = 'failed';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'telegram_bot_id', 'chat_id', 'flow_id',
        'node_id', 'status', 'await_var', 'await_options', 'vars', 'last_error',
        'expires_at', 'last_message_id', 'poll_id', 'invoice_payload',
        'payment_link_id', 'payment_ref',
    ];

    protected $casts = [
        'vars'          => 'encrypted:array',
        'await_options' => 'array',
        'expires_at'    => 'datetime',
    ];

    public function bot()
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    /** Live = waiting or sleeping and not expired. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_SLEEPING], true)
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
