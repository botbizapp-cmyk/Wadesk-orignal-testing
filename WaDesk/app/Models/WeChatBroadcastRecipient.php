<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of a WeChat broadcast. openid stays plaintext (must be
 * queryable). Mirrors LineBroadcastRecipient.
 */
class WeChatBroadcastRecipient extends Model
{
    protected $table = 'wechat_broadcast_recipients';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'wechat_broadcast_id', 'openid', 'title', 'conversation_id',
        'status', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(WeChatBroadcast::class);
    }
}
