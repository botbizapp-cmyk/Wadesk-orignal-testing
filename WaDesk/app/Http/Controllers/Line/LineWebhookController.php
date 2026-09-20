<?php

namespace App\Http\Controllers\Line;

use App\Events\Inbox\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\LineChannel;
use App\Services\Line\LineClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * LINE Messaging API inbound webhook. Verifies the X-Line-Signature HMAC over the
 * RAW body, then converges every event onto the SHARED inbox pipeline (the same
 * conversations / inbox_messages / MessageReceived / RoutingEngine → AI → keyword
 * path Telegram/Facebook/TikTok use). Thread key: 'line:<channelRowId>:<userId>'.
 *
 * Phase 1: message (text + media), follow/unfollow, postback replay. Flows (the
 * flow-runtime bridge) land in Phase 2 — until then keyword/welcome/away replies
 * fire via KeywordReplyDispatcher exactly like every other channel.
 */
class LineWebhookController extends Controller
{
    public function ingest(Request $request, string $token): JsonResponse
    {
        $channel = LineChannel::byWebhookToken($token);
        if (! $channel) {
            return $this->ack('unknown channel');
        }

        // SECURITY: verify X-Line-Signature = base64(HMAC-SHA256(rawBody, channel_secret)).
        // Must run on the UNPARSED body — read getContent() before touching JSON.
        $raw    = (string) $request->getContent();
        $secret = (string) ($channel->channel_secret ?? '');
        $given  = (string) $request->header('X-Line-Signature', '');
        $expected = $secret !== '' ? base64_encode(hash_hmac('sha256', $raw, $secret, true)) : '';
        if ($secret === '' || $given === '' || ! hash_equals($expected, $given)) {
            Log::warning('[LINE] inbound signature mismatch', ['channel' => $channel->id]);

            return response()->json(['ok' => false, 'error' => 'bad signature'], 401);
        }
        if (! $channel->active) {
            return $this->ack('channel disabled');
        }

        $channel->forceFill(['last_inbound_at' => now(), 'last_error' => null])->saveQuietly();

        $events = (array) data_get(json_decode($raw, true) ?: [], 'events', []);
        foreach ($events as $event) {
            try {
                $this->handleEvent($channel, (array) $event);
            } catch (\Throwable $e) {
                Log::error('[LINE] event ingest failed: '.$e->getMessage(), ['channel' => $channel->id]);
            }
        }

        return $this->ack('ok');
    }

    private function handleEvent(LineChannel $channel, array $event): void
    {
        $type = (string) ($event['type'] ?? '');

        // Postback (button/flow answer) — replay postback.data as a normal inbound so
        // keyword/flow automation fires exactly as a typed message would.
        if ($type === 'postback') {
            $data = (string) data_get($event, 'postback.data', '');
            if ($data !== '') {
                $this->store($channel, $event, $data, null, null);
            }

            return;
        }

        if ($type === 'message') {
            [$text, $mediaType, $messageId] = $this->extract($event);
            $mediaPath = $messageId !== null ? $this->storeMedia($channel, $messageId, (int) $channel->workspace_id) : null;
            $this->store($channel, $event, $text, $mediaType, $mediaPath);

            return;
        }

        // follow/unfollow update the contact opt-in state; the rest (join/leave/…)
        // are acked. Contact resolution happens in thread() on the next message.
        // (Phase 2 can expand join/leave into group threads + membership events.)
    }

    /** Write an inbound event into the shared inbox. */
    private function store(LineChannel $channel, array $event, string $text, ?string $mediaType, ?string $mediaPath): ?InboxMessage
    {
        $srcType = (string) data_get($event, 'source.type', 'user');
        $srcId   = (string) (data_get($event, 'source.userId')
            ?: data_get($event, 'source.groupId')
            ?: data_get($event, 'source.roomId') ?: '');
        if ($srcId === '') {
            return null;
        }
        $wsId = (int) $channel->workspace_id;
        $key  = 'line:'.$channel->id.':'.$srcId;   // row id encoded → routes the reply
        $replyToken = (string) data_get($event, 'replyToken', '');

        $convo = $this->thread($channel, $wsId, $key, $srcId, $srcType);

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider'        => 'line',
            'direction'       => 'in',
            'body'            => $text,
            'media_path'      => $mediaPath,
            'media_type'      => $mediaPath !== null ? $mediaType : null,
            'from_number'     => $srcId ?: null,
            'status'          => 'received',
            'meta'            => ['line' => array_filter([
                'source_type' => $srcType,
                'source_id'   => $srcId,
                'message_id'  => data_get($event, 'message.id'),
                // replyToken powers the FREE reply path (~1 min TTL) — dispatchLine
                // uses it when fresh, else falls back to (billable) push.
                'reply_token'    => $replyToken ?: null,
                'reply_token_at' => $replyToken ? now()->timestamp : null,
                'webhook_event_id' => data_get($event, 'webhookEventId'),
            ], fn ($v) => $v !== null && $v !== '')],
            'sent_at'      => now(),
            'delivered_at' => now(),
        ]);

        $convo->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'provider'        => 'line',
            'preview'         => Str::limit($text, 120),
            'inbox_status'    => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ])->save();

        if (Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        try {
            event(new MessageReceived($inbox->id, $convo->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[LINE] MessageReceived failed: '.$e->getMessage());
        }

        // Automation — FLOW FIRST (on the Node runtime), then routing → AI →
        // keyword. Mirrors the Telegram/Facebook ingest so a customer never gets
        // a double reply. Flow node sends via the FREE reply path when the
        // replyToken is still fresh; PHP replies leave via
        // InboxDispatcher::dispatchLine, which reads the reply_token above.
        try {
            $consumedByFlow = false;
            // Groups/rooms don't carry a userId profile the flow keys on — only
            // direct-user threads start flows (parity with Telegram's private key).
            if ($srcType === 'user') {
                $startFlow = $this->resolveLineKeywordFlow($channel, $text);
                $consumedByFlow = \App\Services\Line\LineFlowBridge::handoff(
                    $channel, $srcId, $text,
                    $startFlow ? $startFlow->decoded_flow_data : null,
                    $startFlow?->id,
                    $replyToken ?: null,
                );
            }

            if (! $consumedByFlow) {
                app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                    $convo->fresh() ?: $convo,
                    ['message_text' => $text, 'contact_phone' => $srcId],
                    isFollowUp: ! $convo->wasRecentlyCreated,
                );
                $convo = $convo->fresh() ?: $convo;

                if ($convo->assignee_agent_id) {
                    app(\App\Services\AiAgentService::class)->respondIfAssigned($convo->fresh() ?: $convo);
                } else {
                    app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                        $convo->fresh() ?: $convo, $text, $srcId, null, null,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[LINE] automation failed: '.$e->getMessage());
        }

        return $inbox;
    }

    /**
     * Match the inbound text to a published LINE flow bound to THIS channel.
     * Rules are stored flow_type='line' + trigger_device_id=<line_channels row
     * id> (the convention keyword rules also use). Mirrors
     * resolveTelegramKeywordFlow. Returns the flow to START, or null to RESUME /
     * fall through to keyword/AI.
     */
    private function resolveLineKeywordFlow(LineChannel $channel, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '' || ! class_exists(\App\Models\Flow::class)) {
            return null;
        }
        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('flow_type', 'line')
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('trigger_device_id', $channel->id)
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
                if (in_array($kw, ['any', '*', '.*', '.+'], true) || str_contains($text, $kw)) {
                    return $flow;
                }
            }
        }

        return null;
    }

    /** Find or create the channel='line' thread; link a Contact via the shared helper. */
    private function thread(LineChannel $channel, int $wsId, string $key, string $srcId, string $srcType): Conversation
    {
        $conv = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'line', 'raw_jid' => $key],
            [
                'title'           => 'LINE',
                'provider'        => 'line',
                'origin'          => 'line',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
                'contact_digits'  => null,
            ]
        );

        // Resolve the sender's LINE profile (name + avatar) — 1:1 only (groups/rooms
        // have no per-user profile without extra calls). Stored via the shared
        // social-contact helper so the inbox panel + /contacts CRM light up.
        if ($srcType === 'user') {
            $displayName = 'LINE user';
            $avatarUrl   = null;
            try {
                $p = (new LineClient((string) $channel->activeAccessToken()))->getProfile($srcId);
                if (($p['ok'] ?? false)) {
                    $displayName = (string) (data_get($p, 'data.displayName') ?: $displayName);
                    $avatarUrl   = data_get($p, 'data.pictureUrl');
                }
            } catch (\Throwable $e) { /* profile is best-effort */ }

            $contact = \App\Models\Contact::forSocialSender($wsId, 'line', $srcId, $displayName, null, 'Source: LINE');
            if ($contact && ! $conv->contact_id) {
                $conv->forceFill(['contact_id' => $contact->id, 'title' => $displayName])->save();
            }
            if ($contact && $conv->wasRecentlyCreated && $displayName !== 'LINE user') {
                $conv->forceFill(['title' => $displayName])->save();
            }
        }

        return $conv;
    }

    /** [text, mediaType, messageId] from a LINE message event. */
    private function extract(array $event): array
    {
        $mt = (string) data_get($event, 'message.type', '');
        $messageId = (string) data_get($event, 'message.id', '');

        return match ($mt) {
            'text'     => [(string) data_get($event, 'message.text', ''), null, null],
            'image'    => ['', 'image', $messageId ?: null],
            'video'    => ['', 'video', $messageId ?: null],
            'audio'    => ['', 'audio', $messageId ?: null],
            'file'     => [(string) data_get($event, 'message.fileName', '['.__('file').']'), 'document', $messageId ?: null],
            'location' => [sprintf('📍 %s, %s', data_get($event, 'message.latitude'), data_get($event, 'message.longitude')), null, null],
            'sticker'  => [(string) (data_get($event, 'message.text') ?: '['.__('sticker').']'), null, null],
            default    => ['['.__('message').']', null, null],
        };
    }

    /** Download inbound media bytes into our storage (never persist LINE's URL). */
    private function storeMedia(LineChannel $channel, string $messageId, int $wsId): ?string
    {
        try {
            $bytes = (new LineClient((string) $channel->activeAccessToken()))->getMessageContent($messageId);
            if ($bytes === null || $bytes === '') {
                return null;
            }
            $path = sprintf('line/%d/%s.bin', $wsId, Str::random(32));
            media_storage()->put($path, $bytes);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[LINE] media download failed', ['message_id' => $messageId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function ack(string $note): JsonResponse
    {
        return response()->json(['ok' => true, 'note' => $note]);
    }
}
