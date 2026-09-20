<?php

namespace App\Services\Telegram;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\TelegramBot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The reachable audience for a Telegram bot = the existing inbox threads it
 * carried (a bot cannot message someone who has never messaged it first).
 * Ported from the source, adapted to our raw_jid scheme 'tg:<botId>:<chatId>'
 * (the bot is encoded in the jid — no channel_connection columns here). Shared
 * by the broadcast composer.
 */
class TelegramChats
{
    /** @return Collection<int, array{id:int,title:string,chat_id:string,type:string,last_at:mixed,unread:int}> */
    public static function forBot(TelegramBot $bot, bool $roomsOnly = false, int $limit = 500): Collection
    {
        try {
            $prefix = 'tg:'.$bot->id.':';

            return Conversation::query()
                ->where('workspace_id', $bot->workspace_id)
                ->where('channel', 'telegram')
                ->where('raw_jid', 'like', $prefix.'%')
                ->when($roomsOnly, fn ($q) => $q->where('raw_jid', 'like', $prefix.'-%'))
                ->orderByDesc('last_message_at')
                ->limit($limit)
                ->get(['id', 'title', 'raw_jid', 'last_message_at', 'unread_count'])
                ->map(fn ($c) => self::describe($c));
        } catch (\Throwable $e) {
            Log::warning('[TELEGRAM] chat list failed for bot '.$bot->id.': '.$e->getMessage());

            return collect();
        }
    }

    private static function describe(object $c): array
    {
        $rows = InboxMessage::query()
            ->where('conversation_id', $c->id)
            ->where('direction', 'in')
            ->whereNotNull('meta')
            ->orderByDesc('id')->limit(25)->get(['meta']);

        $chatId = TelegramMedia::chatIdFromJid((string) $c->raw_jid);
        $isRoom = str_starts_with($chatId, '-');

        if (! $isRoom) {
            $type = 'private';
        } else {
            $type = $rows->map(fn ($m) => data_get($m->meta, 'telegram.chat_type'))->filter()->first();
            if (! $type) {
                $type = $rows->contains(fn ($m) => data_get($m->meta, 'telegram.from_id') !== null) ? 'group' : 'channel';
            }
            if ($type === 'supergroup') {
                $type = 'group';
            }
        }

        return [
            'id'      => $c->id,
            'title'   => (string) ($c->title ?: 'Telegram chat'),
            'chat_id' => $chatId,
            'type'    => (string) $type,
            'last_at' => $c->last_message_at,
            'unread'  => (int) ($c->unread_count ?? 0),
        ];
    }
}
