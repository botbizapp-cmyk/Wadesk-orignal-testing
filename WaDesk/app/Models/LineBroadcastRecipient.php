<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of a LINE broadcast. line_user_id stays plaintext (must be
 * queryable). isUnreachable() classifies a LINE refusal (the user blocked the OA
 * or the userId is invalid) as blocked vs a transient failure.
 */
class LineBroadcastRecipient extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'line_broadcast_id', 'line_user_id', 'title', 'conversation_id',
        'status', 'provider_message_id', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(LineBroadcast::class);
    }

    /**
     * True when LINE's error means the recipient is permanently unreachable —
     * the user blocked the OA, or the userId no longer belongs to a friend.
     * Retrying those is the same refusal again, so they are parked as blocked.
     */
    public static function isUnreachable(string $description): bool
    {
        $d = strtolower($description);
        foreach (['blocked', 'not a friend', 'invalid user', 'the user hasn', 'forbidden',
                  'not found', 'no longer', 'cannot send messages'] as $needle) {
            if (str_contains($d, $needle)) {
                return true;
            }
        }

        return false;
    }
}
