<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A WeChat broadcast — a message sent to the OA's audience (a picked OpenID list,
 * a tag, or all followers) via WeChat's mass-send API. NOTE: WeChat caps Service
 * Accounts at ~4 mass sends per month — the composer surfaces that limit. Mirrors
 * LineBroadcast.
 */
class WeChatBroadcast extends Model
{
    protected $table = 'wechat_broadcasts';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'wechat_channel_id', 'user_id', 'name', 'template_id', 'body', 'buttons',
        'audience', 'tag_id', 'mass_msg_id', 'status',
        'total', 'sent', 'failed', 'last_error', 'started_at', 'finished_at',
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
        return $this->hasMany(WeChatBroadcastRecipient::class);
    }

    public function channel()
    {
        return $this->belongsTo(WeChatChannel::class, 'wechat_channel_id');
    }

    public function progress(): int
    {
        $total = max(1, (int) $this->total);

        return (int) round((($this->sent + $this->failed) / $total) * 100);
    }
}
