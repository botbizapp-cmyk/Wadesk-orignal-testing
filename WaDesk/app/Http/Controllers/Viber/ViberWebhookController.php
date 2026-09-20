<?php

namespace App\Http\Controllers\Viber;

use App\Events\Inbox\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\ViberChannel;
use App\Services\Viber\ViberClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Viber REST Bot API webhook. Verifies the X-Viber-Content-Signature HMAC over the
 * RAW body, then converges every event onto the SHARED inbox pipeline (the same
 * conversations / inbox_messages / MessageReceived / RoutingEngine → AI → keyword
 * path LINE/WeChat use). Thread key: 'viber:<channelRowId>:<viberUserId>'.
 *
 * Events: message (store+automate), subscribed / conversation_started (welcome),
 * unsubscribed (opt-out), delivered / seen / failed (acked), webhook (validation).
 * We ACK 200 fast and reply async via InboxDispatcher::dispatchViber.
 */
class ViberWebhookController extends Controller
{
    public function ingest(Request $request, string $token): JsonResponse
    {
        $channel = ViberChannel::byWebhookToken($token);
        if (! $channel) {
            return response()->json(['status' => 0]);   // ack unknown quietly
        }

        // SECURITY: X-Viber-Content-Signature = hex(HMAC-SHA256(rawBody, auth_token)).
        $raw = (string) $request->getContent();
        $sig = (string) $request->header('X-Viber-Content-Signature', '');
        if (! ViberClient::verifySignature($raw, $sig, (string) $channel->auth_token)) {
            Log::warning('[VIBER] inbound signature mismatch', ['channel' => $channel->id]);

            return response()->json(['status' => 1, 'status_message' => 'bad signature'], 403);
        }

        $payload = json_decode($raw, true) ?: [];
        $event   = (string) ($payload['event'] ?? '');

        // Validation ping fired by set_webhook — just ack.
        if ($event === 'webhook') {
            return response()->json(['status' => 0]);
        }
        if (! $channel->active) {
            return response()->json(['status' => 0]);
        }
        $channel->forceFill(['last_inbound_at' => now(), 'last_error' => null])->saveQuietly();

        try {
            match ($event) {
                'message'              => $this->onMessage($channel, $payload),
                'subscribed'           => $this->onSubscribe($channel, (array) ($payload['user'] ?? []), true),
                'conversation_started' => $this->onSubscribe($channel, (array) ($payload['user'] ?? []), false),
                'unsubscribed'         => $this->onUnsubscribe($channel, (string) ($payload['user_id'] ?? '')),
                'delivered', 'seen', 'failed' => $this->onReceipt($event, (string) ($payload['message_token'] ?? ''), (string) ($payload['desc'] ?? '')),
                default                => null,
            };
        } catch (\Throwable $e) {
            Log::error('[VIBER] event ingest failed: '.$e->getMessage(), ['channel' => $channel->id, 'event' => $event]);
        }

        return response()->json(['status' => 0]);
    }

    /** A user→bot message → shared inbox + automation. */
    private function onMessage(ViberChannel $channel, array $payload): void
    {
        $userId = (string) data_get($payload, 'sender.id', '');
        if ($userId === '') {
            return;
        }
        $name   = (string) data_get($payload, 'sender.name', '');
        $avatar = (string) data_get($payload, 'sender.avatar', '');
        $m      = (array) ($payload['message'] ?? []);

        [$text, $mediaType, $mediaUrl] = $this->extract($m);
        $mediaPath = $mediaUrl ? $this->storeMedia($mediaUrl, (int) $channel->workspace_id, (string) $mediaType) : null;

        $wsId  = (int) $channel->workspace_id;
        $key   = 'viber:'.$channel->id.':'.$userId;
        $convo = $this->thread($channel, $wsId, $key, $userId, $name ?: null, $avatar ?: null);

        $isLocation = strtolower((string) ($m['type'] ?? '')) === 'location';

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider'        => 'viber',
            'direction'       => 'in',
            'body'            => $text,
            'media_path'      => $mediaPath,
            'media_type'      => $mediaPath !== null ? $mediaType : null,
            'latitude'        => $isLocation ? (float) data_get($m, 'location.lat', 0) : null,
            'longitude'       => $isLocation ? (float) data_get($m, 'location.lon', 0) : null,
            'from_number'     => $userId ?: null,
            'status'          => 'received',
            'meta'            => ['viber' => array_filter([
                'user_id'       => $userId,
                'message_token' => (string) ($payload['message_token'] ?? ''),
                'msg_type'      => (string) ($m['type'] ?? ''),
                'sticker_id'    => (string) ($m['sticker_id'] ?? ''),
            ], fn ($v) => $v !== null && $v !== '')],
            'sent_at'      => now(),
            'delivered_at' => now(),
        ]);

        $convo->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'provider'        => 'viber',
            'preview'         => Str::limit($text, 120),
            'inbox_status'    => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ])->save();

        if (Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        try {
            event(new MessageReceived($inbox->id, $convo->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[VIBER] MessageReceived failed: '.$e->getMessage());
        }

        // Automation — FLOW FIRST (on the Node runtime), then routing → AI →
        // keyword. Mirrors the LINE ingest so a user never gets a double reply.
        // Node sends directly (static token); PHP replies leave via dispatchViber.
        try {
            $startFlow = $this->resolveViberKeywordFlow($channel, $text);
            $consumedByFlow = \App\Services\Viber\ViberFlowBridge::handoff(
                $channel, $userId, $text,
                $startFlow ? $startFlow->decoded_flow_data : null,
                $startFlow?->id,
            );

            if (! $consumedByFlow) {
                app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                    $convo->fresh() ?: $convo,
                    ['message_text' => $text, 'contact_phone' => $userId],
                    isFollowUp: ! $convo->wasRecentlyCreated,
                );
                $convo = $convo->fresh() ?: $convo;

                if ($convo->assignee_agent_id) {
                    app(\App\Services\AiAgentService::class)->respondIfAssigned($convo->fresh() ?: $convo);
                } else {
                    app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                        $convo->fresh() ?: $convo, $text, $userId, null, null,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[VIBER] automation failed: '.$e->getMessage());
        }
    }

    /**
     * Match the inbound text to a published Viber flow bound to THIS channel.
     * Rules: flow_type='viber' + trigger_device_id=<viber_channels row id>. Mirrors
     * resolveLineKeywordFlow.
     */
    private function resolveViberKeywordFlow(ViberChannel $channel, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '' || ! class_exists(\App\Models\Flow::class)) {
            return null;
        }
        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('flow_type', 'viber')
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

    /** subscribed / conversation_started → ensure the thread + fire a welcome. */
    private function onSubscribe(ViberChannel $channel, array $user, bool $subscribed): void
    {
        $userId = (string) ($user['id'] ?? '');
        if ($userId === '') {
            return;
        }
        $wsId  = (int) $channel->workspace_id;
        $key   = 'viber:'.$channel->id.':'.$userId;
        $convo = $this->thread($channel, $wsId, $key, $userId, (string) ($user['name'] ?? '') ?: null, (string) ($user['avatar'] ?? '') ?: null);

        // Only a confirmed subscribe fires the welcome auto-responder (a bare
        // conversation_started means the user has NOT subscribed yet — Viber would
        // reject a proactive send). New-thread welcome via the shared routing engine.
        if ($subscribed) {
            try {
                app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                    $convo->fresh() ?: $convo,
                    ['message_text' => '', 'contact_phone' => $userId],
                    isFollowUp: false,
                );
            } catch (\Throwable $e) {
                Log::warning('[VIBER] welcome failed: '.$e->getMessage());
            }
        }
    }

    private function onUnsubscribe(ViberChannel $channel, string $userId): void
    {
        if ($userId !== '') {
            Log::info('[VIBER] unsubscribe', ['channel' => $channel->id, 'user' => $userId]);
        }
    }

    /**
     * delivered / seen / failed → flip the matching outbound message's
     * delivered_at / read_at / status (Viber read receipts). Matched on the
     * message_token dispatchViber stamped into the message meta.
     */
    private function onReceipt(string $event, string $token, string $desc): void
    {
        if ($token === '') {
            return;
        }
        $msg = InboxMessage::where('provider', 'viber')->where('direction', 'out')
            ->where('meta->viber->message_token', $token)->orderByDesc('id')->first();
        if (! $msg) {
            return;
        }
        $upd = match ($event) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => $msg->delivered_at ?: now()],
            'seen'      => ['status' => 'read', 'read_at' => $msg->read_at ?: now(), 'delivered_at' => $msg->delivered_at ?: now()],
            'failed'    => ['status' => 'failed', 'failure_reason' => mb_substr($desc ?: 'Viber delivery failed', 0, 255)],
            default     => [],
        };
        if ($upd) {
            $msg->forceFill($upd)->saveQuietly();
        }
    }

    /** Inbound Viber message → [displayText, mediaType|null, mediaUrl|null]. */
    private function extract(array $m): array
    {
        return match (strtolower((string) ($m['type'] ?? ''))) {
            'text'     => [(string) ($m['text'] ?? ''), null, null],
            'picture'  => [trim((string) ($m['text'] ?? '')) ?: '[image]', 'image', (string) ($m['media'] ?? '')],
            'video'    => ['[video]', 'video', (string) ($m['media'] ?? '')],
            'file'     => [trim((string) ($m['file_name'] ?? '')) ?: '[file]', 'document', (string) ($m['media'] ?? '')],
            'location' => ['[location]', null, null],
            'contact'  => [trim('[contact] '.(string) data_get($m, 'contact.name', '').' '.(string) data_get($m, 'contact.phone_number', '')), null, null],
            'sticker'  => ['[sticker]', null, null],
            'url'      => [(string) ($m['media'] ?? ''), null, null],
            default    => ['['.strtolower((string) ($m['type'] ?? 'message')).']', null, null],
        };
    }

    /** Find or create the channel='viber' thread; link a Contact via the shared helper. */
    private function thread(ViberChannel $channel, int $wsId, string $key, string $userId, ?string $name, ?string $avatar): Conversation
    {
        $conv = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'viber', 'raw_jid' => $key],
            [
                'title'           => $name ?: 'Viber',
                'provider'        => 'viber',
                'origin'          => 'viber',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
                'contact_digits'  => null,
            ]
        );

        $contact = \App\Models\Contact::forSocialSender($wsId, 'viber', $userId, $name, $avatar, 'Source: Viber');
        $upd = [];
        if ($contact && ! $conv->contact_id) {
            $upd['contact_id'] = $contact->id;
        }
        if ($name && ($conv->title === 'Viber' || $conv->title === '')) {
            $upd['title'] = $name;
        }
        if ($upd) {
            $conv->forceFill($upd)->save();
        }

        return $conv;
    }

    /** Download inbound media (public TTL ~1h URL) to local storage; path or null. */
    private function storeMedia(string $url, int $wsId, string $mediaType): ?string
    {
        try {
            $res = Http::timeout(30)->get($url);
            if (! $res->successful()) {
                return null;
            }
            $ext = match ($mediaType) {
                'image' => 'jpg', 'video' => 'mp4', 'document' => pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'bin',
                default => 'bin',
            };
            $path = 'viber/'.$wsId.'/'.date('Y/m').'/'.Str::random(24).'.'.$ext;
            Storage::disk(media_disk())->put($path, $res->body());

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[VIBER] media download failed: '.$e->getMessage());

            return null;
        }
    }
}
