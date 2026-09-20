<?php

namespace App\Http\Controllers\WeChat;

use App\Events\Inbox\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WeChatChannel;
use App\Services\WeChat\WeChatClient;
use App\Services\WeChat\WeChatCrypto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WeChat Official Account webhook. ONE URL per channel handles both:
 *   GET  /api/wechat/inbound/{token} → URL-validation handshake (echo echostr)
 *   POST /api/wechat/inbound/{token} → inbound XML message/event
 *
 * Signature = sha1(sort(verify_token, timestamp, nonce)) on the query params.
 * The POST body is XML; we converge every message onto the SHARED inbox pipeline
 * (the same conversations / inbox_messages / MessageReceived / RoutingEngine → AI
 * → keyword path Telegram/LINE use). Thread key: 'wechat:<channelRowId>:<openid>'.
 *
 * We ALWAYS ACK 'success' (no passive reply) and let automation reply async via
 * InboxDispatcher::dispatchWeChat — WeChat's 5-second passive-reply window is too
 * short for an AI/keyword round-trip. Phase 1 runs plaintext/compatible encoding;
 * AES safe-mode decryption is Phase 2.
 */
class WeChatWebhookController extends Controller
{
    public function handle(Request $request, string $token): Response
    {
        $channel = WeChatChannel::byWebhookToken($token);
        if (! $channel) {
            return response('success', 200);
        }

        // SECURITY: verify sha1(sort(token,timestamp,nonce)) on BOTH GET and POST.
        $sig  = (string) $request->query('signature', '');
        $ts   = (string) $request->query('timestamp', '');
        $non  = (string) $request->query('nonce', '');
        if (! WeChatCrypto::signatureMatches((string) $channel->verify_token, $sig, $ts, $non)) {
            Log::warning('[WECHAT] inbound signature mismatch', ['channel' => $channel->id]);

            return response('', 403);
        }

        // GET → the one-time URL-validation handshake: echo echostr back verbatim.
        if ($request->isMethod('get')) {
            return response((string) $request->query('echostr', ''), 200)->header('Content-Type', 'text/plain');
        }

        if (! $channel->active) {
            return response('success', 200);
        }
        $channel->forceFill(['last_inbound_at' => now(), 'last_error' => null])->saveQuietly();

        $data = WeChatCrypto::xmlToArray((string) $request->getContent());

        // Safe mode — the body carries <Encrypt>; verify msg_signature and decrypt
        // to the inner XML before we parse the message.
        if ($channel->enc_mode === 'safe' && ! empty($data['Encrypt'])) {
            $encrypt = (string) $data['Encrypt'];
            $msgSig  = (string) $request->query('msg_signature', '');
            if (! WeChatCrypto::msgSignatureMatches((string) $channel->verify_token, $msgSig, $ts, $non, $encrypt)) {
                Log::warning('[WECHAT] msg_signature mismatch', ['channel' => $channel->id]);

                return response('', 403);
            }
            $inner = WeChatCrypto::decryptSafeMode((string) $channel->encoding_aes_key, $encrypt, (string) $channel->app_id);
            if ($inner === null) {
                Log::warning('[WECHAT] safe-mode decrypt failed', ['channel' => $channel->id]);

                return response('success', 200);
            }
            $data = WeChatCrypto::xmlToArray($inner);
        }

        try {
            $this->handleEvent($channel, $data);
        } catch (\Throwable $e) {
            Log::error('[WECHAT] event ingest failed: '.$e->getMessage(), ['channel' => $channel->id]);
        }

        // Always ACK 'success' → tells WeChat "no passive reply"; we reply async.
        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    private function handleEvent(WeChatChannel $channel, array $data): void
    {
        $msgType = strtolower((string) ($data['MsgType'] ?? ''));

        if ($msgType === 'event') {
            $event = strtolower((string) ($data['Event'] ?? ''));
            if ($event === 'unsubscribe') {
                $this->optOut($channel, $data);

                return;
            }
            if ($event === 'subscribe') {
                // Follow → create the thread + let automation fire a welcome.
                $this->store($channel, $data, '', null, null);

                return;
            }
            if ($event === 'click') {
                // Menu tap → replay EventKey as a message so keyword/flow answer it.
                $key = (string) ($data['EventKey'] ?? '');
                if ($key !== '') {
                    $this->store($channel, $data, $key, null, null);
                }

                return;
            }
            // scan / view / location / other events → ack only.
            return;
        }

        [$text, $mediaType, $mediaId] = $this->extract($data);
        $mediaPath = $mediaId ? $this->storeMedia($channel, $mediaId, (int) $channel->workspace_id, (string) $mediaType) : null;
        $this->store($channel, $data, $text, $mediaType, $mediaPath);
    }

    /** Inbound XML → [displayText, mediaType|null, mediaId|null]. */
    private function extract(array $data): array
    {
        return match (strtolower((string) ($data['MsgType'] ?? ''))) {
            'text'      => [(string) ($data['Content'] ?? ''), null, null],
            'image'     => ['[image]', 'image', (string) ($data['MediaId'] ?? '')],
            'voice'     => [trim((string) ($data['Recognition'] ?? '')) ?: '[voice]', 'audio', (string) ($data['MediaId'] ?? '')],
            'video',
            'shortvideo'=> ['[video]', 'video', (string) ($data['MediaId'] ?? '')],
            'location'  => [trim('[location] '.(string) ($data['Label'] ?? '')), null, null],
            'link'      => [trim((string) ($data['Title'] ?? '').' '.(string) ($data['Url'] ?? '')), null, null],
            default     => ['['.strtolower((string) ($data['MsgType'] ?? 'message')).']', null, null],
        };
    }

    /** Write an inbound event into the shared inbox + hand off to automation. */
    private function store(WeChatChannel $channel, array $data, string $text, ?string $mediaType, ?string $mediaPath): ?InboxMessage
    {
        $openid = (string) ($data['FromUserName'] ?? '');
        if ($openid === '') {
            return null;
        }
        $wsId = (int) $channel->workspace_id;
        $key  = 'wechat:'.$channel->id.':'.$openid;   // row id encoded → routes the reply

        $convo = $this->thread($channel, $wsId, $key, $openid);

        $isLocation = strtolower((string) ($data['MsgType'] ?? '')) === 'location';

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider'        => 'wechat',
            'direction'       => 'in',
            'body'            => $text,
            'media_path'      => $mediaPath,
            'media_type'      => $mediaPath !== null ? $mediaType : null,
            'latitude'        => $isLocation && isset($data['Location_X']) ? (float) $data['Location_X'] : null,
            'longitude'       => $isLocation && isset($data['Location_Y']) ? (float) $data['Location_Y'] : null,
            'from_number'     => $openid ?: null,
            'status'          => 'received',
            'meta'            => ['wechat' => array_filter([
                'openid'     => $openid,
                'msg_id'     => (string) ($data['MsgId'] ?? ''),
                'msg_type'   => (string) ($data['MsgType'] ?? ''),
                'event'      => (string) ($data['Event'] ?? ''),
                'event_key'  => (string) ($data['EventKey'] ?? ''),
            ], fn ($v) => $v !== null && $v !== '')],
            'sent_at'      => now(),
            'delivered_at' => now(),
        ]);

        $convo->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'provider'        => 'wechat',
            'preview'         => Str::limit($text, 120),
            'inbox_status'    => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ])->save();

        if (Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        try {
            event(new MessageReceived($inbox->id, $convo->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[WECHAT] MessageReceived failed: '.$e->getMessage());
        }

        // Automation — FLOW FIRST (on the Node runtime), then routing → AI →
        // keyword. Mirrors the LINE/Telegram ingest so a user never gets a double
        // reply. Flow sends leave via PHP flow-send (managed token); PHP replies
        // leave via InboxDispatcher::dispatchWeChat (custom send, 48h-gated).
        try {
            $startFlow = $this->resolveWeChatKeywordFlow($channel, $text);
            $consumedByFlow = \App\Services\WeChat\WeChatFlowBridge::handoff(
                $channel, $openid, $text,
                $startFlow ? $startFlow->decoded_flow_data : null,
                $startFlow?->id,
            );

            if (! $consumedByFlow) {
                app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                    $convo->fresh() ?: $convo,
                    ['message_text' => $text, 'contact_phone' => $openid],
                    isFollowUp: ! $convo->wasRecentlyCreated,
                );
                $convo = $convo->fresh() ?: $convo;

                if ($convo->assignee_agent_id) {
                    app(\App\Services\AiAgentService::class)->respondIfAssigned($convo->fresh() ?: $convo);
                } else {
                    app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                        $convo->fresh() ?: $convo, $text, $openid, null, null,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[WECHAT] automation failed: '.$e->getMessage());
        }

        return $inbox;
    }

    /**
     * Match the inbound text to a published WeChat flow bound to THIS channel.
     * Rules are stored flow_type='wechat' + trigger_device_id=<wechat_channels row
     * id>. Mirrors resolveLineKeywordFlow. Returns the flow to START, or null to
     * RESUME / fall through to keyword/AI.
     */
    private function resolveWeChatKeywordFlow(WeChatChannel $channel, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '' || ! class_exists(\App\Models\Flow::class)) {
            return null;
        }
        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('flow_type', 'wechat')
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

    /** Find or create the channel='wechat' thread; link a Contact via the shared helper. */
    private function thread(WeChatChannel $channel, int $wsId, string $key, string $openid): Conversation
    {
        $conv = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'wechat', 'raw_jid' => $key],
            [
                'title'           => 'WeChat',
                'provider'        => 'wechat',
                'origin'          => 'wechat',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
                'contact_digits'  => null,
            ]
        );

        // WeChat user → Contact (no phone; keyed by OpenID). Best-effort profile
        // for a brand-new contact only (nickname/avatar may be empty by WeChat's
        // 2021 privacy change — fail soft, fall back to the OpenID).
        if ($conv->wasRecentlyCreated || ! $conv->contact_id) {
            $name = null;
            $avatar = null;
            try {
                $p = (new WeChatClient($channel))->getUserInfo($openid);
                if ($p['ok'] ?? false) {
                    $name   = trim((string) ($p['data']['nickname'] ?? '')) ?: null;
                    $avatar = trim((string) ($p['data']['headimgurl'] ?? '')) ?: null;
                }
            } catch (\Throwable $e) {
                // best-effort
            }
            $contact = \App\Models\Contact::forSocialSender($wsId, 'wechat', $openid, $name, $avatar, 'Source: WeChat');
            $upd = [];
            if ($contact && ! $conv->contact_id) {
                $upd['contact_id'] = $contact->id;
            }
            if ($name && ($conv->title === 'WeChat' || $conv->title === '')) {
                $upd['title'] = $name;
            }
            if ($upd) {
                $conv->forceFill($upd)->save();
            }
        }

        return $conv;
    }

    /** Download inbound media to local storage; returns the stored path or null. */
    private function storeMedia(WeChatChannel $channel, string $mediaId, int $wsId, string $mediaType): ?string
    {
        try {
            $bytes = (new WeChatClient($channel))->getMedia($mediaId);
            if ($bytes === null || $bytes === '') {
                return null;
            }
            $ext = match ($mediaType) {
                'image' => 'jpg',
                'audio' => 'amr',
                'video' => 'mp4',
                default => 'bin',
            };
            $path = 'wechat/'.$wsId.'/'.date('Y/m').'/'.Str::random(24).'.'.$ext;
            Storage::disk(media_disk())->put($path, $bytes);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[WECHAT] media download failed: '.$e->getMessage());

            return null;
        }
    }

    /** unsubscribe event → mark the contact opted out (best-effort). */
    private function optOut(WeChatChannel $channel, array $data): void
    {
        $openid = (string) ($data['FromUserName'] ?? '');
        if ($openid === '') {
            return;
        }
        Log::info('[WECHAT] unsubscribe', ['channel' => $channel->id, 'openid' => $openid]);
        // Contact opt-out state is managed by the shared contact layer; nothing
        // else to do here (no thread write — the user left).
    }
}
