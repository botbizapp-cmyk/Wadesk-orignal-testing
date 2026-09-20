<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Telegram Bot API (plain HTTPS, no OAuth/review). Merged
 * from the standalone WaDesk Telegram integration. Every method returns a plain
 * array and never throws — a channel being down is an expected reply-path
 * outcome. Rich flow-node methods (polls/invoices/inline) are added with the
 * flow runner in Phase 2; this is the connect + inbox send/receive surface.
 */
class TelegramClient
{
    private const BASE = 'https://api.telegram.org';

    public const TEXT_LIMIT = 4096;
    public const CAPTION_LIMIT = 1024;

    public function __construct(private readonly string $token) {}

    /** Identify the bot behind a token — validates a token on connect. */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /** Point the bot at this install. drop_pending_updates avoids a stale-backlog flood. */
    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secretToken,
            'drop_pending_updates' => true,
            'allowed_updates'      => json_encode([
                'message', 'edited_message', 'channel_post', 'edited_channel_post',
                'callback_query', 'poll_answer', 'message_reaction', 'pre_checkout_query',
                'chat_join_request',
            ]),
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => true]);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /** Send text. parse_mode OFF by default; when a mode is rejected, resend unparsed. */
    public function sendMessage(string $chatId, string $text, string $parseMode = '', ?string $replyMarkupJson = null): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $this->clip($text, self::TEXT_LIMIT), 'disable_web_page_preview' => false];
        if ($replyMarkupJson !== null && $replyMarkupJson !== '') {
            $payload['reply_markup'] = $replyMarkupJson;
        }
        if ($parseMode === '') {
            return $this->call('sendMessage', $payload);
        }
        $res = $this->call('sendMessage', $payload + ['parse_mode' => $parseMode]);

        return ($res['ok'] ?? false) ? $res : $this->call('sendMessage', $payload);
    }

    /**
     * Turn a WABA/local template's `buttons` array into a Telegram reply_markup
     * plus any text to append. Telegram allows only ONE markup per message, so:
     * if the template has any URL/phone buttons we use an INLINE keyboard (URLs as
     * link buttons, quick-replies as callback buttons the webhook turns back into
     * a normal inbound); otherwise a REPLY keyboard whose taps send the label back
     * as a message. Returns ['reply_markup'=>?string json, 'append'=>string].
     */
    public static function templateButtonsMarkup(array $buttons): array
    {
        $url = []; $quick = []; $phone = [];
        foreach ($buttons as $b) {
            $type = strtolower((string) ($b['type'] ?? ($b['sub_type'] ?? 'quick_reply')));
            $text = trim((string) ($b['text'] ?? ($b['title'] ?? '')));
            if ($text === '') { continue; }
            if (in_array($type, ['url', 'quick_reply_url'], true) && trim((string) ($b['url'] ?? '')) !== '') {
                $url[] = ['text' => mb_substr($text, 0, 64), 'url' => trim((string) $b['url'])];
            } elseif (in_array($type, ['phone_number', 'call', 'phone'], true) && trim((string) ($b['phone_number'] ?? ($b['phone'] ?? '')) ) !== '') {
                $phone[] = $text.': '.trim((string) ($b['phone_number'] ?? $b['phone']));
            } else {
                $quick[] = mb_substr($text, 0, 64);
            }
        }

        $append = $phone ? "\n".implode("\n", array_map(fn ($p) => '📞 '.$p, $phone)) : '';

        // Any real link → inline keyboard (URLs + quick-replies as callbacks).
        if ($url) {
            $rows = array_map(fn ($u) => [$u], $url);
            foreach ($quick as $q) {
                $rows[] = [['text' => $q, 'callback_data' => 'tqr:'.mb_substr($q, 0, 60)]];
            }

            return ['reply_markup' => json_encode(['inline_keyboard' => $rows]), 'append' => $append];
        }

        // Quick-replies only → reply keyboard (taps come back as normal messages).
        if ($quick) {
            $rows = array_map(fn ($q) => [['text' => $q]], $quick);

            return ['reply_markup' => json_encode(['keyboard' => $rows, 'one_time_keyboard' => true, 'resize_keyboard' => true]), 'append' => $append];
        }

        return ['reply_markup' => null, 'append' => $append];
    }

    /** Answer a callback_query (inline-button tap) — required or the button spins. */
    public function answerCallbackQuery(string $queryId, string $text = ''): array
    {
        return $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $queryId,
            'text'              => $text !== '' ? $this->clip($text, 200) : null,
        ], fn ($v) => $v !== null));
    }

    /** Send text with tappable REPLY-keyboard choices (labels come back as messages). */
    public function sendChoices(string $chatId, string $text, array $choices, string $parseMode = ''): array
    {
        $rows = array_values(array_map(
            fn ($c) => [['text' => $this->clip((string) $c, 64)]],
            array_filter($choices, fn ($c) => trim((string) $c) !== '')
        ));
        if ($rows === []) {
            return $this->sendMessage($chatId, $text, $parseMode);
        }
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $this->clip($text, self::TEXT_LIMIT),
            'reply_markup' => json_encode(['keyboard' => $rows, 'one_time_keyboard' => true, 'resize_keyboard' => true]),
        ];
        if ($parseMode === '') {
            return $this->call('sendMessage', $payload);
        }
        $res = $this->call('sendMessage', $payload + ['parse_mode' => $parseMode]);

        return ($res['ok'] ?? false) ? $res : $this->call('sendMessage', $payload);
    }

    /** Send media by public URL (Telegram fetches it). */
    public function sendMedia(string $chatId, string $kind, string $url, ?string $caption = null, array $opts = []): array
    {
        [$method, $field] = self::methodFor($kind);
        $hasCaption = $caption !== null && $caption !== '' && self::supportsCaption($kind);

        return $this->call($method, array_filter(array_merge([
            'chat_id'      => $chatId,
            $field         => $url,
            'caption'      => $hasCaption ? $this->clip($caption, self::CAPTION_LIMIT) : null,
            'parse_mode'   => $hasCaption ? (trim((string) ($opts['parse_mode'] ?? '')) ?: null) : null,
            'reply_markup' => trim((string) ($opts['reply_markup'] ?? '')) ?: null,
        ], self::playerOptions($kind, $opts)), fn ($v) => $v !== null));
    }

    /** Send media by uploading raw bytes (multipart) — for local files / private URLs. */
    public function uploadMedia(string $chatId, string $kind, string $contents, string $filename, ?string $caption = null, array $opts = []): array
    {
        [$method, $field] = self::methodFor($kind);
        if ($this->token === '') {
            return ['ok' => false, 'error' => 'No Telegram bot token configured.'];
        }
        try {
            $req = Http::baseUrl(self::BASE)->acceptJson()->timeout(120)
                ->attach($field, $contents, $filename !== '' ? $filename : 'file');
            $payload = ['chat_id' => $chatId];
            if ($caption !== null && $caption !== '' && self::supportsCaption($kind)) {
                $payload['caption'] = $this->clip($caption, self::CAPTION_LIMIT);
                if (trim((string) ($opts['parse_mode'] ?? '')) !== '') {
                    $payload['parse_mode'] = trim((string) $opts['parse_mode']);
                }
            }
            foreach (self::playerOptions($kind, $opts) as $k => $v) {
                if ($v !== null) {
                    $payload[$k] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
                }
            }
            if (trim((string) ($opts['reply_markup'] ?? '')) !== '') {
                $payload['reply_markup'] = trim((string) $opts['reply_markup']);
            }
            $r = $req->post('/bot'.$this->token.'/'.$method, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $body = $r->json();
        if (! is_array($body)) {
            return ['ok' => false, 'error' => 'Telegram returned a non-JSON response (HTTP '.$r->status().').'];
        }

        return ($body['ok'] ?? false)
            ? ['ok' => true, 'result' => $body['result'] ?? null]
            : ['ok' => false, 'error' => (string) ($body['description'] ?? 'Telegram rejected the upload.')];
    }

    /** Publish the bot's / command menu. */
    public function setMyCommands(array $commands): array
    {
        return $this->call('setMyCommands', ['commands' => json_encode(array_values($commands))]);
    }

    public function sendChatAction(string $chatId, string $action = 'typing'): array
    {
        return $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    /**
     * Send a Telegram invoice (native in-chat payment). `$prices` is a list of
     * ['label'=>string,'amount'=>int] where amount is in the currency's SMALLEST
     * unit (cents; JPY/IDR etc. carry no decimals). Needs the bot's
     * payment_provider_token (from @BotFather → /mybots → Payments).
     */
    public function sendInvoice(string $chatId, string $providerToken, string $title, string $description, string $payload, string $currency, array $prices, array $opts = []): array
    {
        return $this->call('sendInvoice', array_filter([
            'chat_id'        => $chatId,
            'title'          => $this->clip($title, 32),
            'description'    => $this->clip($description, 255),
            'payload'        => $this->clip($payload, 128),
            'provider_token' => $providerToken,
            'currency'       => strtoupper($currency),
            'prices'         => json_encode(array_values($prices)),
            'photo_url'      => trim((string) ($opts['photo_url'] ?? '')) ?: null,
            'start_parameter'=> trim((string) ($opts['start_parameter'] ?? '')) ?: null,
            'need_name'      => ! empty($opts['need_name']) ? true : null,
            'need_email'     => ! empty($opts['need_email']) ? true : null,
            'need_phone_number' => ! empty($opts['need_phone_number']) ? true : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Create a shareable invoice link (createInvoiceLink) — a URL the customer
     * can open to pay, usable outside a single chat. Returns the link in
     * result. Same price/currency rules as sendInvoice.
     */
    public function createInvoiceLink(string $providerToken, string $title, string $description, string $payload, string $currency, array $prices, array $opts = []): array
    {
        return $this->call('createInvoiceLink', array_filter([
            'title'          => $this->clip($title, 32),
            'description'    => $this->clip($description, 255),
            'payload'        => $this->clip($payload, 128),
            'provider_token' => $providerToken,
            'currency'       => strtoupper($currency),
            'prices'         => json_encode(array_values($prices)),
            'photo_url'      => trim((string) ($opts['photo_url'] ?? '')) ?: null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Answer a pre_checkout_query. Telegram requires this within 10 seconds or
     * the payment fails — call it the moment the update arrives. `$ok=false`
     * declines with a human-readable reason.
     */
    public function answerPreCheckoutQuery(string $queryId, bool $ok = true, string $errorMessage = ''): array
    {
        return $this->call('answerPreCheckoutQuery', array_filter([
            'pre_checkout_query_id' => $queryId,
            'ok'            => $ok,
            'error_message' => (! $ok && $errorMessage !== '') ? $this->clip($errorMessage, 255) : null,
        ], fn ($v) => $v !== null));
    }

    /** Resolve a file_id → temporary download URL (embeds the token — never store it). */
    public function fileUrl(string $fileId): ?string
    {
        $res = $this->call('getFile', ['file_id' => $fileId]);
        $path = $res['ok'] ? (string) data_get($res, 'result.file_path', '') : '';

        return $path !== '' ? self::BASE.'/file/bot'.$this->token.'/'.$path : null;
    }

    /**
     * Largest profile-photo file_id for a user, or null when they have none /
     * their privacy hides it. Used to capture a DM sender's avatar once.
     */
    public function getUserProfilePhotos(string $userId): ?string
    {
        $res = $this->call('getUserProfilePhotos', ['user_id' => $userId, 'limit' => 1]);
        if (! ($res['ok'] ?? false)) {
            return null;
        }
        // result.photos[0] is an array of sizes (smallest→largest); take the last.
        $sizes = (array) data_get($res, 'result.photos.0', []);
        $file  = end($sizes);

        return $file ? (string) ($file['file_id'] ?? '') ?: null : null;
    }

    /** Download a file_id's bytes, or null on failure. */
    public function download(string $fileId): ?string
    {
        $url = $this->fileUrl($fileId);
        if ($url === null) {
            return null;
        }
        try {
            $r = Http::timeout(30)->get($url);

            return $r->successful() ? $r->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function playerOptions(string $kind, array $opts): array
    {
        $str = fn (string $k) => trim((string) ($opts[$k] ?? '')) !== '' ? trim((string) $opts[$k]) : null;
        $int = fn (string $k) => (int) ($opts[$k] ?? 0) > 0 ? (int) $opts[$k] : null;
        $bool = fn (string $k) => ! empty($opts[$k]) ? true : null;

        return match ($kind) {
            'video' => ['supports_streaming' => empty($opts['no_streaming']) ? true : null, 'has_spoiler' => $bool('spoiler'), 'duration' => $int('duration'), 'width' => $int('width'), 'height' => $int('height')],
            'audio' => ['title' => $str('title'), 'performer' => $str('performer'), 'duration' => $int('duration')],
            default => [],
        };
    }

    private static function methodFor(string $kind): array
    {
        return match ($kind) {
            'video'      => ['sendVideo', 'video'],
            'audio'      => ['sendAudio', 'audio'],
            'voice'      => ['sendVoice', 'voice'],
            'document'   => ['sendDocument', 'document'],
            'sticker'    => ['sendSticker', 'sticker'],
            'animation'  => ['sendAnimation', 'animation'],
            'video_note' => ['sendVideoNote', 'video_note'],
            default      => ['sendPhoto', 'photo'],
        };
    }

    private static function supportsCaption(string $kind): bool
    {
        return ! in_array($kind, ['sticker', 'video_note'], true);
    }

    private function clip(string $s, int $limit): string
    {
        return mb_strlen($s) > $limit ? mb_substr($s, 0, $limit - 1).'…' : $s;
    }

    private function call(string $method, array $params = []): array
    {
        if ($this->token === '') {
            return ['ok' => false, 'error' => 'No Telegram bot token configured.'];
        }
        try {
            $r = $this->request()->asForm()->post('/bot'.$this->token.'/'.$method, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $body = $r->json();
        if (! is_array($body)) {
            return ['ok' => false, 'error' => 'Telegram returned a non-JSON response (HTTP '.$r->status().').'];
        }
        if (($body['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($body['description'] ?? 'Telegram rejected the request.')];
        }

        return ['ok' => true, 'result' => $body['result'] ?? null];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->acceptJson()->timeout(20);
    }
}
