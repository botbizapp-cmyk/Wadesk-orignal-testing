<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mobile push registration (FCM/APNs token) for a signed-in user's device.
 * Uniqueness + upserts key on token_hash = sha256(fcm_token), so the raw token
 * (which can exceed a safe index length) lives in a TEXT column.
 */
class UserDeviceToken extends Model
{
    protected $table = 'user_device_tokens';

    protected $fillable = [
        'user_id', 'workspace_id', 'device_id',
        'fcm_token', 'token_hash', 'platform', 'device_info', 'last_used_at',
    ];

    protected $casts = [
        'device_info' => 'array',
        'last_used_at' => 'datetime',
    ];

    /** Canonical hash for a token — the dedupe/upsert key. */
    public static function hashFor(string $token): string
    {
        return hash('sha256', trim($token));
    }
}
