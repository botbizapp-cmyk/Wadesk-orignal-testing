<?php

namespace App\Services\WeChat;

use App\Models\Conversation;
use App\Models\WeChatChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The reachable audience for a WeChat channel = the existing inbox threads it
 * carried (followers who have messaged the OA). Mirrors LineChats, keyed on the
 * raw_jid scheme 'wechat:<channelRowId>:<openid>'. Shared by the broadcast
 * composer's "pick from inbox" audience.
 */
class WeChatChats
{
    /** @return Collection<int, array{id:int,title:string,openid:string,last_at:mixed}> */
    public static function forChannel(WeChatChannel $channel, int $limit = 1000): Collection
    {
        try {
            $prefix = 'wechat:'.$channel->id.':';

            return Conversation::query()
                ->where('workspace_id', $channel->workspace_id)
                ->where('channel', 'wechat')
                ->where('raw_jid', 'like', $prefix.'%')
                ->orderByDesc('last_message_at')
                ->limit($limit)
                ->get(['id', 'title', 'raw_jid', 'last_message_at'])
                ->map(fn ($c) => [
                    'id'      => (int) $c->id,
                    'title'   => (string) ($c->title ?: 'WeChat user'),
                    'openid'  => self::openidFromJid((string) $c->raw_jid),
                    'last_at' => $c->last_message_at,
                ])
                ->filter(fn ($r) => $r['openid'] !== '')
                ->values();
        } catch (\Throwable $e) {
            Log::warning('[WECHAT] chat list failed for channel '.$channel->id.': '.$e->getMessage());

            return collect();
        }
    }

    /** openid is the 3rd colon-segment of 'wechat:<rowId>:<openid>'. */
    public static function openidFromJid(string $jid): string
    {
        $parts = explode(':', $jid, 3);

        return $parts[2] ?? '';
    }
}
