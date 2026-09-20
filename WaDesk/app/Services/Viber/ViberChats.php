<?php

namespace App\Services\Viber;

use App\Models\Conversation;
use App\Models\ViberChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The reachable audience for a Viber channel = the existing inbox threads it
 * carried (subscribed users). Mirrors LineChats, keyed on the raw_jid scheme
 * 'viber:<channelRowId>:<userId>'. Shared by the broadcast composer.
 */
class ViberChats
{
    /** @return Collection<int, array{id:int,title:string,user_id:string,last_at:mixed}> */
    public static function forChannel(ViberChannel $channel, int $limit = 1000): Collection
    {
        try {
            $prefix = 'viber:'.$channel->id.':';

            return Conversation::query()
                ->where('workspace_id', $channel->workspace_id)
                ->where('channel', 'viber')
                ->where('raw_jid', 'like', $prefix.'%')
                ->orderByDesc('last_message_at')
                ->limit($limit)
                ->get(['id', 'title', 'raw_jid', 'last_message_at'])
                ->map(fn ($c) => [
                    'id'      => (int) $c->id,
                    'title'   => (string) ($c->title ?: 'Viber user'),
                    'user_id' => self::userIdFromJid((string) $c->raw_jid),
                    'last_at' => $c->last_message_at,
                ])
                ->filter(fn ($r) => $r['user_id'] !== '')
                ->values();
        } catch (\Throwable $e) {
            Log::warning('[VIBER] chat list failed for channel '.$channel->id.': '.$e->getMessage());

            return collect();
        }
    }

    public static function userIdFromJid(string $jid): string
    {
        $parts = explode(':', $jid, 3);

        return $parts[2] ?? '';
    }
}
