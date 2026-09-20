<?php

namespace App\Services\Facebook;

use App\Events\Inbox\MessageReceived;
use App\Models\Conversation;
use App\Models\FacebookPage;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Writes inbound Facebook Page events (Messenger DMs + feed comments) into the
 * unified inbox as channel='facebook' Conversations + InboxMessages. Mirrors the
 * Instagram ingest carve-out (Instaflow\InstaflowIngestService): one thread per
 * DM sender, one thread per post for comments; dedups on the Meta id; bumps
 * preview/window/unread; fires MessageReceived so realtime + automations run.
 */
class FacebookIngestService
{
    /** A Messenger `messaging` event → a DM thread (one per sender PSID). */
    public static function messengerEvent(FacebookPage $page, array $ev): void
    {
        // Messenger POSTBACK (button-template tap, Get Started, ice-breaker). These
        // arrive with a `postback` key and NO `message` key, so the guard below
        // would drop them — and a flow parked on a `buttons`/`fb_buttons` node
        // (1–3 options render as postback buttons) would stall forever on the
        // customer's tap. Forward the payload to the Node flow engine FIRST so it
        // RESUMES the parked session (Node's resumeFlow matches the button payload),
        // or STARTS a keyword flow when the payload text matches a trigger — the
        // same handoff the text-DM path does. Must run before the message guard.
        if (isset($ev['postback']) && ! empty($ev['sender']['id'])) {
            $pbPsid = (string) $ev['sender']['id'];
            // resumeFlow matches on the button PAYLOAD first; fall back to the
            // visible title so a payload-less quick action still routes.
            $payload = (string) ($ev['postback']['payload'] ?? $ev['postback']['title'] ?? '');
            if ($payload !== '') {
                try {
                    $startFlow = self::resolveFbKeywordFlow($page, $payload);
                    \App\Services\Facebook\FbFlowBridge::handoff(
                        $page,
                        $pbPsid,
                        $payload,
                        $startFlow ? $startFlow->decoded_flow_data : null,
                        $startFlow?->id
                    );
                } catch (\Throwable $e) {
                    Log::warning('[FB-INGEST] postback handoff failed: '.$e->getMessage());
                }
            }

            return;
        }

        // Ignore delivery/read receipts and our own echoes (our sends are
        // already stored by InboxDispatcher::dispatchFacebook).
        if (! isset($ev['message']) || ! empty($ev['message']['is_echo'])) {
            return;
        }
        $psid = (string) ($ev['sender']['id'] ?? '');
        $mid = (string) ($ev['message']['mid'] ?? '');
        if ($psid === '' || $mid === '') {
            return;
        }

        $text = (string) ($ev['message']['text'] ?? '');
        $mediaType = null;
        $mediaPath = null;
        foreach ((array) ($ev['message']['attachments'] ?? []) as $att) {
            $mediaType = (string) ($att['type'] ?? 'file');
            $mediaPath = (string) ($att['payload']['url'] ?? '');
            break;
        }

        self::write($page, [
            'raw_jid'    => 'fb:'.$page->page_id.':'.$psid,
            'kind'       => 'dm',
            'sender_id'  => $psid,
            'title'      => null, // resolved from the sender profile on create
            'body'       => $text,
            'media_type' => $mediaType,
            'media_path' => $mediaPath,
            'dedup'      => $mid,
            'ts'         => isset($ev['timestamp']) ? (int) $ev['timestamp'] : null,
            'meta'       => ['message_id' => $mid, 'psid' => $psid, 'kind' => 'dm'],
        ]);
    }

    /** A `feed` change with item=comment, verb=add → a per-post comment thread. */
    public static function feedComment(FacebookPage $page, array $value): void
    {
        if (($value['item'] ?? '') !== 'comment' || ($value['verb'] ?? '') !== 'add') {
            return;
        }
        $commentId = (string) ($value['comment_id'] ?? '');
        $postId = (string) ($value['post_id'] ?? ($value['parent_id'] ?? ''));
        $fromId = (string) ($value['from']['id'] ?? '');
        if ($commentId === '' || $postId === '') {
            return;
        }
        // Skip comments the Page itself made (our own replies).
        if ($fromId !== '' && $fromId === (string) $page->page_id) {
            return;
        }

        // Dedup BEFORE any Graph round-trip: at-least-once redelivery of an
        // empty-body comment must not trigger a redundant getComment() call.
        if (self::alreadyStored((int) $page->workspace_id, $commentId)) {
            return;
        }

        $body = (string) ($value['message'] ?? '');
        // Webhook is a notification — backfill the text if Meta omitted it.
        if ($body === '') {
            $c = (new FacebookPageClient($page))->getComment($commentId);
            $body = (string) ($c['message'] ?? '');
        }

        $fromName = (string) ($value['from']['name'] ?? '');

        self::write($page, [
            // One thread per (post, top-level commenter) so an operator's reply
            // targets the right person's comment, not "whoever commented last".
            'raw_jid'    => 'fb:'.$page->page_id.':post:'.$postId.':'.($fromId !== '' ? $fromId : 'anon'),
            'kind'       => 'comment',
            'sender_id'  => $fromId,
            'title'      => $fromName !== '' ? $fromName : __('Facebook commenter'),
            'body'       => $body !== '' ? $body : '[comment]',
            'media_type' => null,
            'media_path' => null,
            'dedup'      => $commentId,
            'ts'         => null,
            'meta'       => array_filter([
                'message_id' => $commentId,
                'comment_id' => $commentId,
                'post_id'    => $postId,
                'parent_id'  => (string) ($value['parent_id'] ?? ''),
                'from_id'    => $fromId,
                'from_name'  => (string) ($value['from']['name'] ?? ''),
                'kind'       => 'comment',
            ]),
        ]);
    }

    /** Has this exact Meta id (mid / comment_id) already been ingested here? */
    private static function alreadyStored(int $wsId, string $dedup): bool
    {
        if ($dedup === '') {
            return false;
        }

        return InboxMessage::query()
            ->where('meta->facebook->message_id', $dedup)
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $wsId)->where('channel', 'facebook'))
            ->exists();
    }

    /**
     * Backfill one HISTORICAL Messenger message into the inbox — same thread key
     * + dedup as the live webhook, but WITHOUT firing events or the automation
     * engine (never auto-reply / flow / AI on an old message). Mirrors the
     * Instagram ConversationBackfillService `is_backfill` behaviour.
     */
    public static function backfillMessage(FacebookPage $page, array $p): void
    {
        self::write($page, $p, true);
    }

    /** Shared writer: upsert the thread, dedup, append the bubble, fire events. */
    private static function write(FacebookPage $page, array $p, bool $isBackfill = false): void
    {
        $wsId = (int) $page->workspace_id;

        // Dedup: this exact Meta id already stored?
        if (self::alreadyStored($wsId, (string) $p['dedup'])) {
            return;
        }

        $title = $p['title'];
        $conv = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'facebook', 'raw_jid' => $p['raw_jid']],
            [
                // Never fall back to the raw PSID — a real name is resolved from the
                // Graph profile just below; until then show a friendly placeholder.
                'title'        => $title ?: __('Facebook user'),
                'provider'     => 'facebook',
                'origin'       => 'facebook',
                'status'       => 'pending',
                'inbox_status' => 'open',
                'last_message_at' => now(),
                'contact_digits'  => null,
            ]
        );

        // Resolve a friendly DM sender name + avatar once, on thread creation.
        if ($conv->wasRecentlyCreated && $p['kind'] === 'dm' && $p['sender_id'] !== '') {
            try {
                $prof = Cache::remember('fb_sender:'.$page->id.':'.$p['sender_id'], 21600,
                    fn () => (new FacebookPageClient($page))->getSenderProfile($p['sender_id']));
                $name = trim((string) ($prof['name'] ?? '')) ?: trim(((string) ($prof['first_name'] ?? '')).' '.((string) ($prof['last_name'] ?? '')));
                if ($name !== '') {
                    $conv->forceFill([
                        'title'      => $name,
                        'routing_meta' => array_merge((array) $conv->routing_meta, ['fb_avatar' => (string) ($prof['profile_pic'] ?? '')]),
                    ])->save();
                }
            } catch (\Throwable $e) { /* best effort */ }
        }

        // DM sender → Contact (no phone; keyed by PSID). Comment authors are not
        // captured — only real DM leads. Links the thread for the CRM + inbox panel.
        if ($p['kind'] === 'dm' && (string) $p['sender_id'] !== '') {
            $avatar  = (string) data_get($conv->routing_meta, 'fb_avatar', '');
            $contact = \App\Models\Contact::forSocialSender($wsId, 'facebook', (string) $p['sender_id'], (string) $conv->title, $avatar ?: null, 'Source: Facebook DM');
            if ($contact && ! $conv->contact_id) {
                $conv->forceFill(['contact_id' => $contact->id])->save();
            }
        }

        // Pass app tz — createFromTimestampMs() returns the instant in UTC
        // regardless of APP_TIMEZONE, while now() (and Eloquent's created_at)
        // use the app timezone. Both land in the same naive DATETIME column, so
        // on a non-UTC server every Meta-stamped inbound row was stored hours
        // BEHIND the platform-sent rows next to it — which sorted all of
        // Messenger's messages above all of the team's replies in the thread
        // (COALESCE(sent_at, created_at)) and showed the wrong bubble time.
        // Same fix already applied to WABA in WaWebhookController/WaInboundController.
        $ts = $p['ts']
            ? \Illuminate\Support\Carbon::createFromTimestampMs($p['ts'], config('app.timezone'))
            : now();

        $inbox = InboxMessage::create([
            'conversation_id' => $conv->id,
            'provider'        => 'facebook',
            'direction'       => 'in',
            'body'            => $p['body'],
            'media_type'      => $p['media_type'],
            'media_path'      => $p['media_path'],
            'from_number'     => $p['sender_id'] ?: null,
            'status'          => 'received',
            'meta'            => ['facebook' => $p['meta']],
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

        // Backfill of historical messages: store only — never fire real-time
        // events or the automation engine (no auto-reply to an old message).
        if ($isBackfill) {
            return;
        }

        try {
            event(new MessageReceived($inbox->id, $conv->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[FB-INGEST] MessageReceived failed: '.$e->getMessage());
        }

        // Run the channel-agnostic automation engine for genuine inbound DMs.
        // Comments are moderation, not conversational auto-reply, so excluded.
        //
        // Precedence — flow > AI agent > keyword — so a customer never gets two
        // replies to one message. Each layer's OUTBOUND ships through
        // InboxDispatcher::dispatchFacebook (Messenger Send API on this same
        // facebook conversation), which is already channel-aware.
        if ($p['kind'] === 'dm') {
            // 1) FLOW FIRST. Facebook flows run on the SEPARATE ported Node runtime
            // (node/services/facebookFlowService.js). Give it a chance to RESUME a
            // parked session (customer answering an Ask / tapping a button) OR
            // START a keyword-triggered flow. When Node reports the message was
            // consumed we skip everything below — exactly like the WhatsApp and
            // Instagram flow paths behave.
            $consumedByFlow = false;
            try {
                $startFlow = self::resolveFbKeywordFlow($page, (string) $p['body']);
                $consumedByFlow = \App\Services\Facebook\FbFlowBridge::handoff(
                    $page,
                    (string) $p['sender_id'],
                    (string) $p['body'],
                    $startFlow ? $startFlow->decoded_flow_data : null,
                    $startFlow?->id
                );
            } catch (\Throwable $e) {
                Log::warning('[FB-INGEST] flow handoff failed: '.$e->getMessage());
            }

            if (! $consumedByFlow) {
                // 2) ROUTING RULES. Fire the workspace's inbound routing rules —
                // add_tag / auto_reply / trigger_flow, and crucially assign_agent,
                // which is how an AI agent gets attached to a Facebook thread. New
                // conversations get the full action set; follow-ups only the
                // per-message ones (RoutingEngine splits internally).
                try {
                    app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                        $conv->fresh() ?: $conv,
                        ['message_text' => (string) $p['body'], 'contact_phone' => (string) $p['sender_id']],
                        isFollowUp: ! $conv->wasRecentlyCreated,
                    );
                    $conv = $conv->fresh() ?: $conv; // routing may have set assignee_agent_id
                } catch (\Throwable $e) {
                    Log::warning('[FB-INGEST] routing engine failed: '.$e->getMessage());
                }

                // 3) AI AGENT. If an agent is assigned (by the routing rule above or
                // by an operator), let it answer — respondIfAssigned's plan /
                // coexistence / handoff guards are already channel-agnostic and its
                // reply dispatches via dispatchFacebook. When the AI is handling the
                // thread the keyword layer is skipped so the bot never double-replies.
                if ($conv->assignee_agent_id) {
                    // Channel-specific plan gate: the FB AI agent must be included in
                    // the plan (facebook_ai_agent) on top of the generic access_ai_agents
                    // guard inside respondIfAssigned. Off by default → skip the AI reply
                    // (non-throwing; the thread stays assigned and waits for a human — we
                    // deliberately do NOT fall through to the keyword layer here, matching
                    // the "assigned = AI owns it, no double-reply" intent). This is what
                    // makes the facebook_ai_agent plan toggle actually do something.
                    $fbAiOk = \App\Services\PlanLimitGuard::hasFeature(
                        \App\Models\Workspace::find($page->workspace_id),
                        'facebook_ai_agent'
                    );
                    if ($fbAiOk) {
                        try {
                            app(\App\Services\AiAgentService::class)->respondIfAssigned($conv->fresh() ?: $conv);
                        } catch (\Throwable $e) {
                            Log::warning('[FB-INGEST] AI agent respond failed: '.$e->getMessage());
                        }
                    }
                } else {
                    // 4) KEYWORD / WELCOME / AWAY / OUT-OF-HOURS auto-reply.
                    try {
                        app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                            $conv->fresh() ?: $conv,
                            (string) $p['body'],
                            (string) $p['sender_id'], // PSID = stable contact key (digits only)
                            null,                       // no device self-number on Facebook
                            null                        // igTrigger=null → plain keyword/welcome/away/OOH path
                        );
                    } catch (\Throwable $e) {
                        Log::warning('[FB-INGEST] auto-reply dispatch failed: '.$e->getMessage());
                    }
                }
            }
        }

        Log::info('[FB-INGEST] stored', ['page' => $page->id, 'kind' => $p['kind'], 'conv' => $conv->id, 'msg' => $inbox->id]);
    }

    /**
     * Find a PUBLISHED, active Facebook flow bound to this Page whose keyword
     * Trigger matches the inbound text — the START path for native FB flows
     * (mirrors resolveIgKeywordFlow). Returns null when nothing matches, in which
     * case the handoff falls back to resume-only (a parked session may still
     * consume the message).
     *
     * The flow's trigger_device_id carries the facebook_pages ROW id (set by the
     * builder's apiPicker `facebook:<row-id>` key), never the 17-digit Meta
     * page_id — so the match is against $page->id.
     *
     * Keyword rules (same semantics as the WhatsApp / IG keyword matcher):
     *   - trigger_keywords is a comma-separated list; ANY token may match.
     *   - a catch-all token ('any', '*', '.*') matches every message.
     *   - otherwise a case-insensitive "contains" test per token.
     */
    private static function resolveFbKeywordFlow(FacebookPage $page, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '') {
            return null;
        }

        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $page->workspace_id)
            ->where('flow_type', 'facebook')
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('trigger_device_id', $page->id)
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
                    return $flow; // catch-all
                }
                if (str_contains($text, $kw)) {
                    return $flow;
                }
            }
        }

        return null;
    }
}
