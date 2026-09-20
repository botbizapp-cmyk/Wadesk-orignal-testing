<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pull a Facebook Page's EXISTING Messenger threads + their message history into
 * the WaDesk unified inbox — the "your past chats are already here" behaviour
 * Instagram already has (ConversationBackfillService). Without this, only NEW
 * inbound DMs (arriving via webhook after connect) ever appear; every thread
 * that existed before connect was invisible until the customer messaged again.
 *
 * Consistency with live webhooks:
 *   - Threads are keyed EXACTLY like FacebookIngestService::messengerEvent
 *     (raw_jid = "fb:<page_id>:<psid>"), so backfilled + future live messages
 *     share ONE conversation, never a duplicate.
 *   - Messages go through FacebookIngestService::backfillMessage(), which dedups
 *     on the Meta message id — so re-running (on every connect / manual re-sync)
 *     never double-renders — and which STORES ONLY: no events, no auto-reply /
 *     flow / AI on a historical message.
 *
 * Best-effort throughout; a failed thread/message is logged and skipped.
 */
class FacebookConversationBackfillService
{
    /**
     * @return array{threads:int, messages:int, skipped:int}
     */
    public static function run(FacebookPage $page, int $maxThreads = 25, int $perThread = 25): array
    {
        $client  = new FacebookPageClient($page);
        $pageId  = (string) $page->page_id;

        $res = $client->listConversations(['fields' => 'id,participants,updated_time', 'limit' => $maxThreads]);
        $conversations = (array) ($res['data'] ?? []);
        if (empty($conversations)) {
            return ['threads' => 0, 'messages' => 0, 'skipped' => 0];
        }

        $threads = 0; $messages = 0; $skipped = 0;

        foreach ($conversations as $conv) {
            $convId = (string) ($conv['id'] ?? '');
            if ($convId === '') { $skipped++; continue; }

            // The customer PSID = the participant that isn't the Page.
            $psid = '';
            foreach ((array) data_get($conv, 'participants.data', []) as $part) {
                $pid = (string) ($part['id'] ?? '');
                if ($pid !== '' && $pid !== $pageId) { $psid = $pid; break; }
            }

            $msgRes = $client->getConversationMessages($convId, $perThread);
            $rows = (array) ($msgRes['data'] ?? []);
            if (empty($rows)) { continue; }

            // Meta returns newest-first; store oldest-first so the thread reads
            // naturally and last_message_at ends on the newest.
            $rows = array_reverse($rows);
            $threadHad = false;

            foreach ($rows as $msg) {
                $mid    = (string) ($msg['id'] ?? '');
                $fromId = (string) data_get($msg, 'from.id', '');
                $text   = (string) ($msg['message'] ?? '');
                if ($mid === '') { $skipped++; continue; }

                // Only the CUSTOMER's messages create the inbound thread; the Page's
                // own past replies aren't re-imported (write() is inbound-only).
                if ($fromId === '' || $fromId === $pageId) { continue; }

                // Learn the psid from the message when participants didn't give it.
                if ($psid === '') { $psid = $fromId; }

                [$mediaType, $mediaPath] = self::mediaFrom($msg);
                if ($text === '' && $mediaPath === '') { continue; }

                $ts = null;
                if (($ct = (string) ($msg['created_time'] ?? '')) !== '') {
                    try { $ts = Carbon::parse($ct)->getTimestampMs(); } catch (\Throwable $e) { $ts = null; }
                }

                try {
                    FacebookIngestService::backfillMessage($page, [
                        'raw_jid'    => 'fb:' . $pageId . ':' . $psid,
                        'kind'       => 'dm',
                        'sender_id'  => $psid,
                        'title'      => null,
                        'body'       => $text,
                        'media_type' => $mediaType,
                        'media_path' => $mediaPath,
                        'dedup'      => $mid,
                        'ts'         => $ts,
                        'meta'       => ['message_id' => $mid, 'psid' => $psid, 'kind' => 'dm', 'backfill' => true],
                    ]);
                    $messages++; $threadHad = true;
                } catch (\Throwable $e) {
                    Log::info('[FB-BACKFILL] ingest failed', ['page' => $page->id, 'conv' => $convId, 'mid' => $mid, 'error' => $e->getMessage()]);
                    $skipped++;
                }
            }

            if ($threadHad) { $threads++; }
        }

        Log::info('[FB-BACKFILL] done', ['page' => $page->id, 'workspace' => $page->workspace_id, 'threads' => $threads, 'messages' => $messages, 'skipped' => $skipped]);

        return ['threads' => $threads, 'messages' => $messages, 'skipped' => $skipped];
    }

    /** Best-effort media type + url from a Messenger message's attachments. */
    private static function mediaFrom(array $msg): array
    {
        $att = data_get($msg, 'attachments.data.0');
        if (! is_array($att)) { return [null, null]; }
        $type = (string) ($att['mime_type'] ?? '');
        $url  = trim((string) (data_get($att, 'image_data.url') ?: data_get($att, 'video_data.url') ?: data_get($att, 'file_url') ?: ''));
        if ($url === '') { return [null, null]; }
        $kind = str_starts_with($type, 'image') ? 'image' : (str_starts_with($type, 'video') ? 'video' : 'file');

        return [$kind, $url];
    }
}
