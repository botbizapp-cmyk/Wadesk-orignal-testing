<?php

namespace App\Http\Controllers\Telegram;

use App\Events\Inbox\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\TelegramBot;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Telegram Bot API webhook — one signed callback URL per bot
 * (/api/telegram/inbound/{token}). Verifies the per-bot secret header, turns a
 * message update into a channel='telegram' Conversation + InboxMessage
 * (raw_jid 'tg:<botId>:<chatId>' — the bot id routes the reply), and fires
 * MessageReceived so the unified inbox live-updates like any channel.
 *
 * Phase 1 handles the `message` family. The flow-runner-dependent updates
 * (callback_query, poll_answer, chat_join_request, payments) are acked here and
 * wired in Phase 2 with the ported TelegramFlowRunner.
 */
class TelegramWebhookController extends Controller
{
    /** POST /api/telegram/inbound/{token} — always ack 200 (Telegram retries non-2xx). */
    public function ingest(Request $request, string $token): JsonResponse
    {
        $bot = TelegramBot::byWebhookToken($token);
        if (! $bot) {
            return $this->ack('unknown bot');
        }

        // Verify the secret header Telegram echoes back (set on setWebhook).
        $sent   = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $stored = (string) ($bot->secret_token ?? '');
        if ($stored !== '' && ! hash_equals($stored, $sent)) {
            Log::warning('[TELEGRAM] inbound secret mismatch', ['bot' => $bot->id]);

            return $this->ack('bad secret');
        }
        if (! $bot->active) {
            return $this->ack('bot disabled');
        }

        $update = (array) $request->all();

        // Payments — a pre_checkout_query MUST be answered within 10s or Telegram
        // fails the charge. We approve (the invoice was built by us; there is no
        // stock/price to re-validate here). Do this before anything else.
        if (! empty($update['pre_checkout_query'])) {
            $bot->forceFill(['last_inbound_at' => now()])->saveQuietly();
            try {
                (new TelegramClient((string) $bot->bot_token))
                    ->answerPreCheckoutQuery((string) data_get($update, 'pre_checkout_query.id', ''), true);
            } catch (\Throwable $e) {
                Log::warning('[TELEGRAM] pre_checkout answer failed: '.$e->getMessage(), ['bot' => $bot->id]);
            }

            return $this->ack('pre_checkout_query');
        }

        // Inline-button tap (callback_query) — answer it (or the button spins),
        // then, for our template quick-reply buttons ('tqr:<label>'), replay the
        // label as a normal inbound so flows / keyword auto-replies fire exactly
        // as if the customer had typed it.
        if (! empty($update['callback_query'])) {
            $bot->forceFill(['last_inbound_at' => now()])->saveQuietly();
            $cq   = (array) $update['callback_query'];
            $data = (string) ($cq['data'] ?? '');
            try {
                (new TelegramClient((string) $bot->bot_token))->answerCallbackQuery((string) ($cq['id'] ?? ''));
            } catch (\Throwable $e) {
                Log::warning('[TELEGRAM] answerCallbackQuery failed: '.$e->getMessage());
            }
            if (str_starts_with($data, 'tqr:')) {
                $label = substr($data, 4);
                $synthetic = [
                    'message_id' => data_get($cq, 'message.message_id'),
                    'chat'       => (array) data_get($cq, 'message.chat', []),
                    'from'       => (array) ($cq['from'] ?? []),
                    'text'       => $label,
                ];
                if (! empty($synthetic['chat'])) {
                    try {
                        $this->store($bot, $synthetic);
                    } catch (\Throwable $e) {
                        Log::error('[TELEGRAM] callback replay failed: '.$e->getMessage(), ['bot' => $bot->id]);
                    }
                }
            }

            return $this->ack('callback_query');
        }

        // Flow-runner updates — Phase 2. Ack for now so Telegram doesn't retry.
        foreach (['chat_join_request', 'poll_answer'] as $k) {
            if (! empty($update[$k])) {
                $bot->forceFill(['last_inbound_at' => now()])->saveQuietly();

                return $this->ack($k);
            }
        }

        // message | edited_message | channel_post | edited_channel_post all carry
        // the same shape. v1 ingests the fresh message families.
        $m = (array) ($update['message'] ?? $update['channel_post'] ?? $update['edited_message'] ?? $update['edited_channel_post'] ?? []);
        if (empty($m)) {
            return $this->ack('no message');
        }

        $bot->forceFill(['last_inbound_at' => now(), 'last_error' => null])->saveQuietly();

        try {
            $this->store($bot, $m);
        } catch (\Throwable $e) {
            Log::error('[TELEGRAM] ingest failed: '.$e->getMessage(), ['bot' => $bot->id]);
        }

        return $this->ack('ok');
    }

    /** Write a message update into the inbox. */
    private function store(TelegramBot $bot, array $m): ?InboxMessage
    {
        $chatId = (string) data_get($m, 'chat.id', '');
        if ($chatId === '') {
            return null;
        }
        $wsId = (int) $bot->workspace_id;
        $key  = 'tg:'.$bot->id.':'.$chatId;   // bot id encoded → routes the reply
        $chatType = strtolower((string) data_get($m, 'chat.type', 'private'));

        $convo = $this->thread($bot, $wsId, $key, $m);

        // Successful payment — record it as its own inbox row and stop. It carries
        // no user text to match against automation, and the confirmation the
        // customer already saw is Telegram's own receipt, so we don't run flows on
        // it (that would double-reply). The payment is queryable in meta.
        if (! empty($m['successful_payment'])) {
            return $this->storePayment($bot, $convo, $m, $chatId, $chatType);
        }

        [$text, $mediaType, $fileId] = $this->extract($m);
        $mediaPath = $fileId !== null ? $this->storeMedia($bot, $fileId, $wsId) : null;

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider'        => 'telegram',
            'direction'       => 'in',
            'body'            => $text,
            'media_path'      => $mediaPath,
            'media_type'      => $mediaPath !== null ? $mediaType : null,
            'from_number'     => (string) data_get($m, 'from.id', '') ?: null,
            'status'          => 'received',
            'meta'            => ['telegram' => array_filter([
                'chat_id'    => $chatId,
                'message_id' => data_get($m, 'message_id'),
                'chat_type'  => $chatType,
                'chat_title' => data_get($m, 'chat.title'),
                'from_id'    => data_get($m, 'from.id'),
                'username'   => data_get($m, 'from.username'),
                'first_name' => data_get($m, 'from.first_name'),
                'last_name'  => data_get($m, 'from.last_name'),
                'file_id'    => $fileId,
            ], fn ($v) => $v !== null && $v !== '')],
            'sent_at'      => now(),
            'delivered_at' => now(),
        ]);

        $convo->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'title'           => $this->title($m),
            'provider'        => 'telegram',
            'preview'         => Str::limit($text, 120),
            'inbox_status'    => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ])->save();

        if (Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        try {
            event(new MessageReceived($inbox->id, $convo->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[TELEGRAM] MessageReceived failed: '.$e->getMessage());
        }

        // Automation — FLOW FIRST (on the Node runtime), then routing → AI → keyword.
        // Mirrors the Facebook/TikTok ingest so a customer never gets a double reply.
        try {
            $consumedByFlow = false;
            $startFlow = $this->resolveTelegramKeywordFlow($bot, (string) $text);
            $consumedByFlow = \App\Services\Telegram\TgFlowBridge::handoff(
                $bot, $chatId, (string) $text,
                $startFlow ? $startFlow->decoded_flow_data : null,
                $startFlow?->id
            );

            if (! $consumedByFlow) {
                app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                    $convo->fresh() ?: $convo,
                    ['message_text' => (string) $text, 'contact_phone' => (string) data_get($m, 'from.id', '')],
                    isFollowUp: ! $convo->wasRecentlyCreated,
                );
                $convo = $convo->fresh() ?: $convo;

                if ($convo->assignee_agent_id) {
                    app(\App\Services\AiAgentService::class)->respondIfAssigned($convo->fresh() ?: $convo);
                } else {
                    app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                        $convo->fresh() ?: $convo, (string) $text, (string) data_get($m, 'from.id', ''), null, null,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[TELEGRAM] automation failed: '.$e->getMessage());
        }

        return $inbox;
    }

    /** Record a successful_payment update as an inbox row (no automation). */
    private function storePayment(TelegramBot $bot, Conversation $convo, array $m, string $chatId, string $chatType): InboxMessage
    {
        $wsId    = (int) $bot->workspace_id;
        $pay     = (array) ($m['successful_payment'] ?? []);
        $currency = strtoupper((string) ($pay['currency'] ?? ''));
        // Telegram sends the total in the currency's smallest unit. Zero-decimal
        // currencies (JPY, IDR, …) carry no minor unit — show them whole.
        $zeroDecimal = in_array($currency, ['JPY', 'KRW', 'VND', 'CLP', 'PYG', 'UGX', 'RWF', 'XOF', 'XAF', 'IDR'], true);
        $raw     = (int) ($pay['total_amount'] ?? 0);
        $amount  = $zeroDecimal ? (string) $raw : number_format($raw / 100, 2);
        $body    = trim(__('Payment received').': '.$currency.' '.$amount);

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider'        => 'telegram',
            'direction'       => 'in',
            'body'            => $body,
            'from_number'     => (string) data_get($m, 'from.id', '') ?: null,
            'status'          => 'received',
            'meta'            => ['telegram' => array_filter([
                'chat_id'    => $chatId,
                'message_id' => data_get($m, 'message_id'),
                'chat_type'  => $chatType,
                'from_id'    => data_get($m, 'from.id'),
                'username'   => data_get($m, 'from.username'),
                'event'      => 'successful_payment',
            ], fn ($v) => $v !== null && $v !== ''), 'payment' => array_filter([
                'currency'                  => $currency,
                'total_amount'              => $raw,
                'amount_display'            => $amount,
                'invoice_payload'           => (string) ($pay['invoice_payload'] ?? ''),
                'telegram_payment_charge_id'=> (string) ($pay['telegram_payment_charge_id'] ?? ''),
                'provider_payment_charge_id'=> (string) ($pay['provider_payment_charge_id'] ?? ''),
                'order_info'                => $pay['order_info'] ?? null,
            ], fn ($v) => $v !== null && $v !== '')],
            'sent_at'      => now(),
            'delivered_at' => now(),
        ]);

        $convo->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'provider'        => 'telegram',
            'preview'         => Str::limit($body, 120),
            'inbox_status'    => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ])->save();

        if (Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        try {
            event(new MessageReceived($inbox->id, $convo->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[TELEGRAM] payment MessageReceived failed: '.$e->getMessage());
        }

        Log::info('[TELEGRAM] payment received', [
            'bot' => $bot->id, 'ws' => $wsId, 'currency' => $currency, 'amount' => $amount,
            'payload' => (string) ($pay['invoice_payload'] ?? ''),
        ]);

        // Resume a flow parked at a block-until-paid Payment node. No-op (returns
        // false) when nothing is parked for this chat — a plain invoice send.
        try {
            \App\Services\Telegram\TgFlowBridge::resumePaid($bot, $chatId, [
                'amount_display'             => $amount,
                'currency'                   => $currency,
                'telegram_payment_charge_id' => (string) ($pay['telegram_payment_charge_id'] ?? ''),
                'invoice_payload'            => (string) ($pay['invoice_payload'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TELEGRAM] payment flow-resume failed: '.$e->getMessage());
        }

        return $inbox;
    }

    /**
     * A PUBLISHED, active Telegram flow bound to this bot whose keyword trigger
     * matches — the START path (mirrors resolveTiktokKeywordFlow). The flow's
     * trigger_device_id carries the telegram_bots row id.
     */
    private function resolveTelegramKeywordFlow(TelegramBot $bot, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '' || ! class_exists(\App\Models\Flow::class)) {
            return null;
        }
        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $bot->workspace_id)
            ->where('flow_type', 'telegram')
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('trigger_device_id', $bot->id)
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

    /** Find or create the channel='telegram' thread keyed by bot+chat. */
    private function thread(TelegramBot $bot, int $wsId, string $key, array $m): Conversation
    {
        $conv = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'telegram', 'raw_jid' => $key],
            [
                'title'           => $this->title($m),
                'provider'        => 'telegram',
                'origin'          => 'telegram',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
                'contact_digits'  => null,
            ]
        );

        // DM sender → Contact (no phone; keyed by the Telegram user/chat id). Links
        // the thread so the inbox contact panel and the /contacts CRM surface it.
        $uid = (string) (data_get($m, 'from.id') ?: data_get($m, 'chat.id') ?: '');
        if ($uid !== '') {
            $contact = \App\Models\Contact::forSocialSender($wsId, 'telegram', $uid, $this->title($m), null, 'Source: Telegram DM');
            if ($contact && ! $conv->contact_id) {
                $conv->forceFill(['contact_id' => $contact->id])->save();
            }
            // Best-effort avatar — only for a brand-new contact, only for a private
            // chat (groups have no per-user photo here). Downloaded + stored locally
            // (never the tokened Telegram URL). A failure is silently fine.
            if ($contact && $contact->wasRecentlyCreated && (string) data_get($m, 'chat.type') === 'private') {
                try {
                    $fileId = (new TelegramClient((string) $bot->bot_token))->getUserProfilePhotos($uid);
                    if ($fileId && ($path = $this->storeMedia($bot, $fileId, $wsId))) {
                        $contact->forceFill(['image' => $path])->save();
                    }
                } catch (\Throwable $e) { /* avatar is optional */ }
            }
        }

        return $conv;
    }

    /** [text, mediaType, fileId] from a message update. */
    private function extract(array $m): array
    {
        if ($photos = (array) data_get($m, 'photo', [])) {
            $largest = end($photos);

            return [(string) data_get($m, 'caption', ''), 'image', (string) data_get($largest, 'file_id', '')];
        }
        foreach ([['video', 'video'], ['voice', 'voice'], ['audio', 'audio'], ['animation', 'video'], ['document', 'document']] as [$field, $type]) {
            if ($fileId = (string) data_get($m, $field.'.file_id', '')) {
                return [(string) data_get($m, 'caption', ''), $type, $fileId];
            }
        }
        if ($sticker = (string) data_get($m, 'sticker.file_id', '')) {
            return [(string) data_get($m, 'sticker.emoji', ''), 'image', $sticker];
        }
        if (data_get($m, 'location.latitude') !== null) {
            return [sprintf('📍 %s, %s', data_get($m, 'location.latitude'), data_get($m, 'location.longitude')), null, null];
        }
        if ($phone = (string) data_get($m, 'contact.phone_number', '')) {
            return [trim('👤 '.data_get($m, 'contact.first_name', '').' '.$phone), null, null];
        }
        $text = (string) data_get($m, 'text', '');
        if ($text !== '') {
            return [$text, null, null];
        }

        return [$this->serviceText($m), null, null];
    }

    /** Describe a service message (bot added, member joined, …) instead of an empty bubble. */
    private function serviceText(array $m): string
    {
        if (data_get($m, 'new_chat_members')) {
            return '👋 '.__('New member joined');
        }
        if (data_get($m, 'left_chat_member')) {
            return '👋 '.__('Member left');
        }
        if (data_get($m, 'group_chat_created') || data_get($m, 'new_chat_title')) {
            return '⚙️ '.__('Group updated');
        }

        return '['.__('message').']';
    }

    /** Download the file bytes into our media storage (never persist Telegram's tokened URL). */
    private function storeMedia(TelegramBot $bot, string $fileId, int $wsId): ?string
    {
        try {
            $client = new TelegramClient((string) $bot->bot_token);
            $url = $client->fileUrl($fileId);
            if ($url === null) {
                return null;
            }
            $bytes = $client->download($fileId);
            if ($bytes === null || $bytes === '') {
                return null;
            }
            $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'bin';
            $path = sprintf('telegram/%d/%s.%s', $wsId, Str::random(32), $ext);
            media_storage()->put($path, $bytes);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[TELEGRAM] media download failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function title(array $m): string
    {
        foreach (['chat.title', 'chat.username', 'from.username'] as $path) {
            if ($v = trim((string) data_get($m, $path, ''))) {
                return $v;
            }
        }
        $name = trim(data_get($m, 'chat.first_name', '').' '.data_get($m, 'chat.last_name', ''));

        return $name !== '' ? $name : 'Telegram';
    }

    private function ack(string $note): JsonResponse
    {
        return response()->json(['ok' => true, 'note' => $note]);
    }
}
