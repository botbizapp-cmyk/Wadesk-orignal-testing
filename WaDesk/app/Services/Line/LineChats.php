<?php

namespace App\Services\Line;

use App\Models\Conversation;
use App\Models\LineChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The reachable audience for a LINE channel = the existing inbox threads it
 * carried. A LINE OA can push to any user who has added it as a friend (every
 * such user already has a thread once they message, and 'follow' events create
 * one), but not to arbitrary userIds. Mirrors TelegramChats, adapted to the
 * raw_jid scheme 'line:<channelRowId>:<userId>'. Shared by the broadcast
 * composer.
 */
class LineChats
{
    /** @return Collection<int, array{id:int,title:string,user_id:string,last_at:mixed,unread:int}> */
    public static function forChannel(LineChannel $channel, int $limit = 500): Collection
    {
        try {
            $prefix = 'line:'.$channel->id.':';

            return Conversation::query()
                ->where('workspace_id', $channel->workspace_id)
                ->where('channel', 'line')
                ->where('raw_jid', 'like', $prefix.'%')
                // Only direct-user threads are broadcastable (userId starts with 'U';
                // group/room ids start with 'C'/'R' and are excluded from a blast).
                ->where('raw_jid', 'like', $prefix.'U%')
                ->orderByDesc('last_message_at')
                ->limit($limit)
                ->get(['id', 'title', 'raw_jid', 'last_message_at', 'unread_count'])
                ->map(fn ($c) => self::describe($c))
                ->filter(fn ($r) => $r['user_id'] !== '')
                ->values();
        } catch (\Throwable $e) {
            Log::warning('[LINE] chat list failed for channel '.$channel->id.': '.$e->getMessage());

            return collect();
        }
    }

    /** userId is the 3rd colon-segment of 'line:<rowId>:<userId>'. */
    public static function userIdFromJid(string $jid): string
    {
        $parts = explode(':', $jid, 3);

        return $parts[2] ?? '';
    }

    private static function describe(object $c): array
    {
        return [
            'id'      => (int) $c->id,
            'title'   => (string) ($c->title ?: 'LINE user'),
            'user_id' => self::userIdFromJid((string) $c->raw_jid),
            'last_at' => $c->last_message_at,
            'unread'  => (int) ($c->unread_count ?? 0),
        ];
    }
}
