<?php

namespace App\Services\Viber;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Viber REST Bot API (host chatapi.viber.com, JSON). Auth is
 * a STATIC X-Viber-Auth-Token header (no exchange). Every method returns a plain
 * array `['ok','status','data','error']` and never throws (mirrors LineClient).
 * Viber's own success marker is the body's `status == 0`.
 *
 * Verified against developers.viber.com/docs/api/rest-bot-api. Every send carries a
 * `sender:{name,avatar}` object (the bot identity), injected here from the channel.
 */
class ViberClient
{
    private const BASE = 'https://chatapi.viber.com/pa';

    public const TEXT_LIMIT = 7000;

    /** @param array{name?:string,avatar?:string} $sender */
    public function __construct(private readonly string $authToken, private readonly array $sender = []) {}

    // ── Signature ────────────────────────────────────────────────────────
    /** X-Viber-Content-Signature = hex(HMAC-SHA256(rawBody, auth_token)). */
    public static function verifySignature(string $raw, string $signature, string $authToken): bool
    {
        if ($signature === '' || $authToken === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $raw, $authToken), $signature);
    }

    // ── Webhook / account ────────────────────────────────────────────────
    public function setWebhook(string $url, ?array $eventTypes = null): array
    {
        return $this->call('/set_webhook', array_filter([
            'url'         => $url,
            'event_types' => $eventTypes ?: ['message', 'subscribed', 'unsubscribed', 'conversation_started', 'delivered', 'seen', 'failed'],
            'send_name'   => true,
            'send_photo'  => true,
        ], fn ($v) => $v !== null));
    }

    /** Remove the webhook (set an empty url) — used on disconnect. */
    public function removeWebhook(): array
    {
        return $this->call('/set_webhook', ['url' => '']);
    }

    public function getAccountInfo(): array
    {
        return $this->call('/get_account_info', []);
    }

    public function getUserDetails(string $id): array
    {
        return $this->call('/get_user_details', ['id' => $id]);
    }

    public function getOnline(array $ids): array
    {
        return $this->call('/get_online', ['ids' => array_values($ids)]);
    }

    // ── Send ─────────────────────────────────────────────────────────────
    /** Send one message object to a Viber user id. The sender object is injected. */
    public function sendMessage(string $receiver, array $message): array
    {
        return $this->call('/send_message', array_merge([
            'receiver'        => $receiver,
            'min_api_version' => 1,
            'sender'          => $this->sender ?: ['name' => 'Bot'],
        ], $message));
    }

    /** Broadcast one message to ≤300 subscribed user ids. */
    public function broadcast(array $broadcastList, array $message): array
    {
        return $this->call('/broadcast_message', array_merge([
            'min_api_version' => 1,
            'sender'          => $this->sender ?: ['name' => 'Bot'],
            'broadcast_list'  => array_slice(array_values($broadcastList), 0, 300),
        ], $message));
    }

    // ── Message builders ─────────────────────────────────────────────────
    public static function textMessage(string $text): array
    {
        return ['type' => 'text', 'text' => mb_substr($text, 0, self::TEXT_LIMIT)];
    }

    public static function pictureMessage(string $media, string $text = '', string $thumbnail = ''): array
    {
        return array_filter(['type' => 'picture', 'text' => mb_substr($text, 0, 768), 'media' => $media, 'thumbnail' => $thumbnail ?: null], fn ($v) => $v !== null);
    }

    public static function videoMessage(string $media, int $size, int $duration = 0, string $thumbnail = ''): array
    {
        return array_filter(['type' => 'video', 'media' => $media, 'size' => max(1, $size), 'duration' => $duration ?: null, 'thumbnail' => $thumbnail ?: null], fn ($v) => $v !== null);
    }

    public static function fileMessage(string $media, int $size, string $fileName): array
    {
        return ['type' => 'file', 'media' => $media, 'size' => max(1, $size), 'file_name' => mb_substr($fileName, 0, 256)];
    }

    public static function urlMessage(string $url): array
    {
        return ['type' => 'url', 'media' => mb_substr($url, 0, 2000)];
    }

    public static function contactMessage(string $name, string $phone): array
    {
        return ['type' => 'contact', 'contact' => ['name' => mb_substr($name, 0, 28), 'phone_number' => mb_substr($phone, 0, 18)]];
    }

    public static function locationMessage(float $lat, float $lon): array
    {
        return ['type' => 'location', 'location' => ['lat' => (string) $lat, 'lon' => (string) $lon]];
    }

    public static function stickerMessage(int $stickerId): array
    {
        return ['type' => 'sticker', 'sticker_id' => $stickerId];
    }

    /**
     * Attach an inline keyboard (quick-reply / open-url buttons) to any message.
     * A `reply` button's ActionBody comes back as a user message, so the keyword/
     * flow engine matches it (like LINE quick replies). $buttons = [[Text, type,
     * value]]; type 'url' → open-url, else 'reply'.
     */
    public static function withKeyboard(array $message, array $buttons): array
    {
        $btns = [];
        foreach (array_slice(array_values($buttons), 0, 24) as $b) {
            $text = trim((string) ($b['text'] ?? ($b['title'] ?? '')));
            if ($text === '') {
                continue;
            }
            $type = strtolower((string) ($b['type'] ?? 'reply'));
            $isUrl = in_array($type, ['url', 'open-url', 'quick_reply_url'], true) && trim((string) ($b['url'] ?? '')) !== '';
            $btns[] = [
                'Columns'    => 6,
                'Rows'       => 1,
                'ActionType' => $isUrl ? 'open-url' : 'reply',
                'ActionBody' => $isUrl ? trim((string) $b['url']) : $text,
                'Text'       => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
                'TextSize'   => 'regular',
                'BgColor'    => '#7360F2',
            ];
        }
        if ($btns) {
            $message['min_api_version'] = 3;
            $message['keyboard'] = ['Type' => 'keyboard', 'DefaultHeight' => false, 'Buttons' => $btns];
        }

        return $message;
    }

    /**
     * A rich-media (carousel) message. $items = [[image, text, actionType, actionBody]].
     * Each item becomes an image button + a text/CTA button.
     */
    public static function richMediaMessage(array $items, int $columns = 6): array
    {
        $buttons = [];
        foreach (array_values($items) as $it) {
            $img  = trim((string) ($it['image'] ?? ''));
            $text = trim((string) ($it['text'] ?? ''));
            $type = strtolower((string) ($it['type'] ?? 'reply'));
            $body = trim((string) ($it['value'] ?? ($it['url'] ?? $text)));
            $isUrl = in_array($type, ['url', 'open-url'], true);
            if ($img !== '') {
                $buttons[] = ['Columns' => $columns, 'Rows' => 4, 'ActionType' => $isUrl ? 'open-url' : 'reply', 'ActionBody' => $body ?: $text, 'Image' => $img];
            }
            if ($text !== '') {
                $buttons[] = ['Columns' => $columns, 'Rows' => 2, 'ActionType' => $isUrl ? 'open-url' : 'reply', 'ActionBody' => $body ?: $text,
                    'Text' => '<b>'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</b>', 'TextSize' => 'medium', 'TextHAlign' => 'center'];
            }
        }

        return ['type' => 'rich_media', 'min_api_version' => 7, 'rich_media' => [
            'Type' => 'rich_media', 'ButtonsGroupColumns' => $columns, 'ButtonsGroupRows' => 6, 'BgColor' => '#FFFFFF', 'Buttons' => $buttons,
        ]];
    }

    /**
     * Map a local template — body + optional buttons — to a Viber message. Reply
     * buttons + URL buttons become an inline keyboard on the text (a reply button's
     * ActionBody comes back as a message the keyword/flow engine matches). Returns
     * one message object.
     *
     * @param  array<int, array<string, mixed>>  $buttons
     * @return array<int, array<string, mixed>>
     */
    public static function templateToMessages(string $body, array $buttons = [], array $vars = []): array
    {
        $body = self::fill($body, $vars);
        $btns = [];
        foreach ($buttons as $b) {
            $type = strtolower((string) ($b['type'] ?? ($b['sub_type'] ?? 'quick_reply')));
            $label = trim((string) ($b['text'] ?? ($b['title'] ?? '')));
            if ($label === '') {
                continue;
            }
            if (in_array($type, ['url', 'quick_reply_url'], true) && trim((string) ($b['url'] ?? '')) !== '') {
                $btns[] = ['text' => $label, 'type' => 'url', 'url' => trim((string) $b['url'])];
            } elseif (in_array($type, ['phone_number', 'call', 'phone'], true) && trim((string) ($b['phone_number'] ?? ($b['phone'] ?? ''))) !== '') {
                $btns[] = ['text' => $label, 'type' => 'url', 'url' => 'tel:'.trim((string) ($b['phone_number'] ?? $b['phone']))];
            } else {
                $btns[] = ['text' => $label, 'type' => 'reply'];
            }
        }

        $msg = self::textMessage($body !== '' ? $body : ' ');
        if ($btns) {
            $msg = self::withKeyboard($msg, $btns);
        }

        return [$msg];
    }

    /** {{var}} substitution shared by the template mapper. */
    private static function fill(string $s, array $vars): string
    {
        if (! $vars) {
            return $s;
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';

            return is_scalar($v) ? (string) $v : '';
        }, $s);
    }

    // ── HTTP core (never throws) ─────────────────────────────────────────
    private function call(string $path, array $body): array
    {
        try {
            $res = Http::withHeaders(['X-Viber-Auth-Token' => $this->authToken])
                ->acceptJson()->timeout(20)
                ->post(self::BASE.$path, (object) $body);
            $data   = (array) ($res->json() ?? []);
            $status = (int) ($data['status'] ?? -1);
            $ok     = $res->successful() && $status === 0;

            return [
                'ok'     => $ok,
                'status' => $status,
                'data'   => $data,
                'error'  => $ok ? '' : trim(($status >= 0 ? $status.' ' : '').(string) ($data['status_message'] ?? $res->body())),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => -1, 'data' => [], 'error' => $e->getMessage()];
        }
    }
}
