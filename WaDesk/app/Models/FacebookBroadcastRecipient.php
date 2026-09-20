<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of a Facebook broadcast. psid stays plaintext (must be
 * queryable). isUnreachable() classifies a Send-API refusal as blocked
 * (permanent — user unreachable / outside window) vs failed (transient).
 */
class FacebookBroadcastRecipient extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'facebook_broadcast_id', 'psid', 'title', 'conversation_id',
        'status', 'provider_message_id', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(FacebookBroadcast::class);
    }

    /**
     * True when the Send-API error means the recipient is permanently
     * unreachable for this broadcast — the person is outside the 24h standard
     * messaging window, has blocked the Page, or the PSID is no longer valid.
     * Retrying those is the same refusal again, so they are marked blocked.
     */
    public static function isUnreachable(string $description): bool
    {
        $d = strtolower($description);
        foreach ([
            'outside of the allowed window',      // #10 — 24h standard messaging window closed
            'outside the allowed window',
            'this person isn\'t available',       // #551 — user unavailable / blocked
            'not available right now',
            'no matching user',                   // #100 subcode 2018001 — invalid/expired PSID
            'invalid user id',
            'cannot message users who are not admins', // dev-mode page, non-role user
            'policy',                              // #200 permission/policy blocks
        ] as $needle) {
            if (str_contains($d, $needle)) {
                return true;
            }
        }

        return false;
    }
}
