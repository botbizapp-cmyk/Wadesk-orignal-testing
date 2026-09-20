<?php

namespace App\Services\Tiktok;

use App\Events\Inbox\MessageReceived;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\TiktokAccount;
use Illuminate\Support\Facades\Log;

/**
 * Writes inbound TikTok Business-Messaging DMs into the unified inbox as
 * channel='tiktok' Conversations + InboxMessages — mirrors FacebookIngestService.
 * One thread per conversation (raw_jid = tt:<open_id>:<conversation_id>); dedups
 * on the TikTok message id; fires MessageReceived so realtime + automations run.
 *
 * ⚠️ Partner-gated + region-locked (US/EEA/CH/UK have no DM API). Only reached
 * for accounts where {@see TiktokAvailability::messagingAvailable()} is true.
 */
class TiktokIngestService
{
    /**
     * A single inbound DM event from the Business Messaging webhook.
     *
     * @param array $ev normalised: conversation_id, message_id, sender_id, text,
     *                  media_url?, media_type?, name?, ts?
     */
    public static function inboundMessage(TiktokAccount $account, array $ev): void
    {
        $convId = (string) ($ev['conversation_id'] ?? '');
        $msgId  = (string) ($ev['message_id'] ?? '');
        $sender = (string) ($ev['sender_id'] ?? '');
        if ($convId === '' || $msgId === '') {
            return;
        }

        self::write($account, [
            'raw_jid'    => 'tt:'.$account->open_id.':'.$convId,
            'sender_id'  => $sender,
            'title'      => (string) ($ev['name'] ?? '') ?: null,
            'avatar'     => (string) ($ev['avatar'] ?? '') ?: null,
            'body'       => (string) ($ev['text'] ?? ''),
            'media_type' => $ev['media_type'] ?? null,
            'media_path' => $ev['media_url'] ?? null,
            'dedup'      => $msgId,
            'ts'         => isset($ev['ts']) ? (int) $ev['ts'] : null,
            'meta'       => ['message_id' => $msgId, 'conversation_id' => $convId, 'sender_id' => $sender],
        ]);
    }

    /** Has this exact TikTok message id already been ingested here? */
    private static function alreadyStored(int $wsId, string $dedup): bool
    {
        if ($dedup === '') {
            return false;
        }

        return InboxMessage::query()
            ->where('meta->tiktok->message_id', $dedup)
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $wsId)->where('channel', 'tiktok'))
            ->exists();
    }

    private static function write(TiktokAccount $account, array $p): void
    {
        $wsId = (int) $account->workspace_id;
        if (self::alreadyStored($wsId, (string) $p['dedup'])) {
            return;
        }

        $conv = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'tiktok', 'raw_jid' => $p['raw_jid']],
            [
                // Never fall back to the raw open_id — use the sender's display name,
                // or a friendly placeholder until one arrives.
                'title'           => $p['title'] ?: __('TikTok user'),
                'provider'        => 'tiktok',
                'origin'          => 'tiktok',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
                'contact_digits'  => null,
            ]
        );

        // DM sender → Contact (no phone; keyed by open_id). Link the thread so the
        // inbox contact panel and the /contacts CRM both surface this social lead.
        if ((string) $p['sender_id'] !== '') {
            $contact = \App\Models\Contact::forSocialSender($wsId, 'tiktok', (string) $p['sender_id'], $p['title'] ?? null, $p['avatar'] ?? null, 'Source: TikTok DM');
            if ($contact && ! $conv->contact_id) {
                $conv->forceFill(['contact_id' => $contact->id])->save();
            }
        }

        // Pass app tz — createFromTimestamp() returns the instant in UTC while
        // now() (and Eloquent's created_at) use APP_TIMEZONE. Both land in the
        // same naive DATETIME column, so without this a non-UTC server stored
        // every vendor-stamped inbound row hours behind the platform-sent rows
        // beside it, breaking the thread's COALESCE(sent_at, created_at) sort.
        $ts = $p['ts']
            ? \Illuminate\Support\Carbon::createFromTimestamp($p['ts'], config('app.timezone'))
            : now();

        $inbox = InboxMessage::create([
            'conversation_id' => $conv->id,
            'provider'        => 'tiktok',
            'direction'       => 'in',
            'body'            => $p['body'],
            'media_type'      => $p['media_type'],
            'media_path'      => $p['media_path'],
            'from_number'     => $p['sender_id'] ?: null,
            'status'          => 'received',
            'meta'            => ['tiktok' => $p['meta']],
            'sent_at'         => $ts,
            'delivered_at'    => $ts,
        ]);

        $conv->forceFill([
            'preview'         => \Illuminate\Support\Str::limit($p['body'], 120),
            'last_message_at' => $ts,
            'last_inbound_at' => $ts,
            'unread_count'    => (int) $conv->unread_count + 1,
            'inbox_status'    => $conv->inbox_status === 'resolved' ? 'open' : $conv->inbox_status,
        ])->save();

        try {
            event(new MessageReceived($inbox->id, $conv->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[TT-INGEST] MessageReceived failed: '.$e->getMessage());
        }

        $convId = (string) ($p['meta']['conversation_id'] ?? '');

        // 1) FLOW FIRST. TikTok flows run on the SEPARATE ported Node runtime
        // (node/services/tiktokFlowService.js) via TtFlowBridge — exactly like the
        // Facebook path. Node RESUMES a parked session or STARTS a keyword flow and
        // answers synchronously whether it consumed the message; when it did we
        // skip everything below so the customer never gets a double reply.
        $consumedByFlow = false;
        try {
            $startFlow = self::resolveTiktokKeywordFlow($account, (string) $p['body']);
            $consumedByFlow = \App\Services\Tiktok\TtFlowBridge::handoff(
                $account,
                $convId,
                (string) $p['body'],
                $startFlow ? $startFlow->decoded_flow_data : null,
                $startFlow?->id
            );
        } catch (\Throwable $e) {
            Log::warning('[TT-INGEST] flow handoff failed: '.$e->getMessage());
        }

        if (! $consumedByFlow) {
            // 2) ROUTING → 3) AI AGENT → 4) KEYWORD auto-reply. Precedence matches
            // the Facebook DM path so a customer never gets two replies. Each
            // layer's OUTBOUND ships through InboxDispatcher::dispatchTiktok, which
            // enforces the 48h/10-message window + region gate.
            try {
                app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                    $conv->fresh() ?: $conv,
                    ['message_text' => (string) $p['body'], 'contact_phone' => (string) $p['sender_id']],
                    isFollowUp: ! $conv->wasRecentlyCreated,
                );
                $conv = $conv->fresh() ?: $conv;
            } catch (\Throwable $e) {
                Log::warning('[TT-INGEST] routing failed: '.$e->getMessage());
            }

            if ($conv->assignee_agent_id) {
                try {
                    app(\App\Services\AiAgentService::class)->respondIfAssigned($conv->fresh() ?: $conv);
                } catch (\Throwable $e) {
                    Log::warning('[TT-INGEST] AI respond failed: '.$e->getMessage());
                }
            } else {
                try {
                    app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                        $conv->fresh() ?: $conv,
                        (string) $p['body'],
                        (string) $p['sender_id'],
                        null,
                        null,
                    );
                } catch (\Throwable $e) {
                    Log::warning('[TT-INGEST] auto-reply failed: '.$e->getMessage());
                }
            }
        }

        Log::info('[TT-INGEST] stored', ['account' => $account->id, 'conv' => $conv->id, 'msg' => $inbox->id]);
    }

    /**
     * Find a PUBLISHED, active TikTok flow bound to this account whose keyword
     * trigger matches the inbound text — mirrors resolveFbKeywordFlow. The flow's
     * trigger_device_id carries the tiktok_accounts row id (builder key
     * `tiktok:<row-id>`). Returns null when nothing matches.
     */
    private static function resolveTiktokKeywordFlow(TiktokAccount $account, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '' || ! class_exists(\App\Models\Flow::class)) {
            return null;
        }

        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('flow_type', 'tiktok')
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('trigger_device_id', $account->id)
            ->orderByDesc('updated_at')
            ->get();

        foreach ($flows as $flow) {
            $raw = trim((string) $flow->trigger_keywords);
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', mb_strtolower($raw)) as $kw) {
                $kw = trim($kw);
                if ($kw === '') {
                    continue;
                }
                if (in_array($kw, ['any', '*', '.*', '.+'], true)) {
                    return $flow;
                }
                if (str_contains($text, $kw)) {
                    return $flow;
                }
            }
        }

        return null;
    }
}
