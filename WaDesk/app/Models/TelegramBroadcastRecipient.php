<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of a Telegram broadcast. chat_id stays plaintext (must be
 * queryable). isUnreachable() classifies a Telegram refusal as blocked vs failed.
 */
class TelegramBroadcastRecipient extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';
    public const KIND_PRIVATE = 'private';
    public const KIND_GROUP = 'group';

    protected $fillable = [
        'telegram_broadcast_id', 'chat_id', 'title', 'kind', 'conversation_id',
        'status', 'provider_message_id', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(TelegramBroadcast::class);
    }

    /** True when Telegram's error means the user is unreachable (blocked/deleted). */
    public static function isUnreachable(string $description): bool
    {
        $d = strtolower($description);
        foreach (['bot was blocked', 'user is deactivated', 'chat not found', 'peer_id_invalid',
                  'bot was kicked', 'have no rights', 'not enough rights', 'user is deleted'] as $needle) {
            if (str_contains($d, $needle)) {
                return true;
            }
        }

        return false;
    }
}
