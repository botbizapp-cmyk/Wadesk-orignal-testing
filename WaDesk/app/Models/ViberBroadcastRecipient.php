<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One recipient of a Viber broadcast. viber_user_id stays plaintext (queryable). */
class ViberBroadcastRecipient extends Model
{
    protected $table = 'viber_broadcast_recipients';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'viber_broadcast_id', 'viber_user_id', 'title', 'conversation_id', 'status', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(ViberBroadcast::class);
    }
}
