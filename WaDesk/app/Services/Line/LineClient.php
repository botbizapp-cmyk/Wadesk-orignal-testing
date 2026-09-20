<?php

namespace App\Services\Line;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the LINE Messaging API (Bearer channel access token, JSON).
 * Every method returns a plain array `['ok','status','data','error']` and never
 * throws — a channel being down is an expected reply-path outcome (mirrors
 * TelegramClient). Phase 1 surface: connect (bot info + webhook), inbox
 * send/receive (reply/push, profile, media content). Template/flex/rich-menu
 * builders land with later phases.
 *
 * Verified against github.com/line/line-openapi (messaging-api.yml). Max 5
 * message objects per send; reply is FREE (needs a ~1-min replyToken), push is
 * billable — the caller (InboxDispatcher::dispatchLine) picks which.
 */
class LineClient
{
    private const BASE      = 'https://api.line.me';
    private const DATA_BASE = 'https://api-data.line.me';

    public const TEXT_LIMIT = 5000;
    /** Buttons-template body cap (no image/title). LINE rejects longer. */
    public const TEMPLATE_TEXT_LIMIT = 160;
    /** Action label cap (buttons template + quick reply). */
    public const LABEL_LIMIT = 20;
    /** Quick-reply items cap per message. */
    public const QUICK_REPLY_LIMIT = 13;

    public function __construct(private readonly string $accessToken) {}

    /**
     * The bearer token for every call. Kept behind an accessor so the token
     * strategy can move from a pasted long-lived token to a rotated v2.1 JWT /
     * v3 stateless token (issueStatelessToken) without touching any call site.
     */
    private function token(): string
    {
        return $this->accessToken;
    }

    // ── Connect / channel ────────────────────────────────────────────────
    /** Identify the OA behind a token — validates the token on connect. */
    public function botInfo(): array
    {
        return $this->get('/v2/bot/info');
    }

    /** Point LINE at this install for this channel. */
    public function setWebhookEndpoint(string $url): array
    {
        return $this->call('PUT', '/v2/bot/channel/webhook/endpoint', ['endpoint' => $url]);
    }

    public function getWebhookEndpoint(): array
    {
        return $this->get('/v2/bot/channel/webhook/endpoint');
    }

    public function testWebhookEndpoint(?string $url = null): array
    {
        return $this->call('POST', '/v2/bot/channel/webhook/test', $url ? ['endpoint' => $url] : []);
    }

    // ── Send ─────────────────────────────────────────────────────────────
    /** Reply (FREE) — needs a fresh replyToken (~1 min TTL, single use). */
    public function reply(string $replyToken, array $messages): array
    {
        return $this->call('POST', '/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => array_slice(array_values($messages), 0, 5),
        ]);
    }

    /** Push (billable) — send to one userId/groupId/roomId anytime. */
    public function push(string $to, array $messages): array
    {
        return $this->call('POST', '/v2/bot/message/push', [
            'to'       => $to,
            'messages' => array_slice(array_values($messages), 0, 5),
        ]);
    }

    /** Multicast (billable) — same message to ≤500 userIds. */
    public function multicast(array $userIds, array $messages): array
    {
        return $this->call('POST', '/v2/bot/message/multicast', [
            'to'       => array_slice(array_values($userIds), 0, 500),
            'messages' => array_slice(array_values($messages), 0, 5),
        ]);
    }

    /**
     * Broadcast (billable) — same message to EVERY friend of the OA. Rate-limited
     * by LINE to 60 requests/hour; use for true send-to-all only.
     */
    public function broadcast(array $messages): array
    {
        return $this->call('POST', '/v2/bot/message/broadcast', [
            'messages' => array_slice(array_values($messages), 0, 5),
        ]);
    }

    // ── Profile / content ────────────────────────────────────────────────
    /** User profile: displayName, userId, pictureUrl?, statusMessage?, language?. */
    public function getProfile(string $userId): array
    {
        return $this->get('/v2/bot/profile/'.rawurlencode($userId));
    }

    /**
     * Download inbound media bytes (image/video/audio/file). Content host is
     * api-data.line.me and retention is short — fetch immediately. Returns the
     * raw bytes or null on failure.
     */
    public function getMessageContent(string $messageId): ?string
    {
        try {
            $res = Http::withToken($this->token())->timeout(30)
                ->get(self::DATA_BASE.'/v2/bot/message/'.rawurlencode($messageId).'/content');

            return $res->successful() ? $res->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Message builders ─────────────────────────────────────────────────
    /** A text message object (LINE caps at 5000 chars). */
    public static function textMessage(string $text): array
    {
        return ['type' => 'text', 'text' => mb_substr($text, 0, self::TEXT_LIMIT)];
    }

    public static function imageMessage(string $originalUrl, ?string $previewUrl = null): array
    {
        return ['type' => 'image', 'originalContentUrl' => $originalUrl, 'previewImageUrl' => $previewUrl ?: $originalUrl];
    }

    public static function videoMessage(string $originalUrl, string $previewUrl): array
    {
        return ['type' => 'video', 'originalContentUrl' => $originalUrl, 'previewImageUrl' => $previewUrl];
    }

    public static function audioMessage(string $originalUrl, int $durationMs): array
    {
        return ['type' => 'audio', 'originalContentUrl' => $originalUrl, 'duration' => max(1, $durationMs)];
    }

    public static function locationMessage(string $title, string $address, float $lat, float $lng): array
    {
        return [
            'type'     => 'location',
            'title'    => mb_substr($title !== '' ? $title : $address, 0, 100) ?: 'Location',
            'address'  => mb_substr($address !== '' ? $address : $title, 0, 100) ?: '-',
            'latitude' => $lat,
            'longitude'=> $lng,
        ];
    }

    /**
     * Attach quick-reply chips to any message object. Each label taps back as an
     * ordinary text message (a "message" action), so the flow/keyword resume path
     * matches on the label exactly like Telegram's reply keyboard. ≤13 items.
     *
     * @param  array<int, string|array{label:string,text?:string}>  $items
     */
    public static function withQuickReplies(array $message, array $items): array
    {
        $chips = [];
        foreach (array_slice(array_values($items), 0, self::QUICK_REPLY_LIMIT) as $it) {
            $label = trim((string) (is_array($it) ? ($it['label'] ?? '') : $it));
            if ($label === '') {
                continue;
            }
            $text = trim((string) (is_array($it) ? ($it['text'] ?? $label) : $label)) ?: $label;
            $chips[] = [
                'type'   => 'action',
                'action' => ['type' => 'message', 'label' => mb_substr($label, 0, self::LABEL_LIMIT), 'text' => $text],
            ];
        }
        if ($chips) {
            $message['quickReply'] = ['items' => $chips];
        }

        return $message;
    }

    /**
     * A buttons-template message — up to 4 tappable actions (uri / message /
     * postback). Text is capped at 160 chars (LINE's no-image ceiling).
     *
     * @param  array<int, array{type:string,label:string,uri?:string,text?:string,data?:string}>  $actions
     */
    public static function buttonsTemplate(string $text, array $actions, string $altText = ''): array
    {
        $acts = [];
        foreach (array_slice(array_values($actions), 0, 4) as $a) {
            $label = mb_substr(trim((string) ($a['label'] ?? '')), 0, self::LABEL_LIMIT);
            $type  = strtolower((string) ($a['type'] ?? 'message'));
            if ($label === '') {
                continue;
            }
            if ($type === 'uri' && trim((string) ($a['uri'] ?? '')) !== '') {
                $acts[] = ['type' => 'uri', 'label' => $label, 'uri' => trim((string) $a['uri'])];
            } elseif ($type === 'postback' && trim((string) ($a['data'] ?? '')) !== '') {
                $acts[] = ['type' => 'postback', 'label' => $label, 'data' => mb_substr((string) $a['data'], 0, 300), 'displayText' => $label];
            } else {
                $acts[] = ['type' => 'message', 'label' => $label, 'text' => trim((string) ($a['text'] ?? $label)) ?: $label];
            }
        }
        $body = mb_substr($text !== '' ? $text : '-', 0, self::TEMPLATE_TEXT_LIMIT);

        return [
            'type'     => 'template',
            'altText'  => mb_substr($altText !== '' ? $altText : $body, 0, 400),
            'template' => ['type' => 'buttons', 'text' => $body, 'actions' => $acts],
        ];
    }

    /**
     * Map a local (non-Meta) template — body + optional buttons — to LINE message
     * objects. URL/phone buttons become a buttons-template (LINE has no plain
     * "link button" on a text bubble); quick-reply buttons become quick-reply
     * chips. A body longer than the template ceiling is sent as its own text
     * bubble first so nothing is truncated. Returns 1–2 message objects.
     *
     * @param  array<int, array<string, mixed>>  $buttons
     * @return array<int, array<string, mixed>>
     */
    public static function templateToMessages(string $body, array $buttons = [], array $vars = []): array
    {
        $body = self::fill($body, $vars);

        $actions = [];   // → buttons template (uri/phone)
        $chips   = [];   // → quick replies (plain reply buttons)
        foreach ($buttons as $b) {
            $type = strtolower((string) ($b['type'] ?? ($b['sub_type'] ?? 'quick_reply')));
            $label = trim((string) ($b['text'] ?? ($b['title'] ?? '')));
            if ($label === '') {
                continue;
            }
            if (in_array($type, ['url', 'quick_reply_url'], true) && trim((string) ($b['url'] ?? '')) !== '') {
                $actions[] = ['type' => 'uri', 'label' => $label, 'uri' => trim((string) $b['url'])];
            } elseif (in_array($type, ['phone_number', 'call', 'phone'], true)
                && trim((string) ($b['phone_number'] ?? ($b['phone'] ?? ''))) !== '') {
                $actions[] = ['type' => 'uri', 'label' => $label, 'uri' => 'tel:'.trim((string) ($b['phone_number'] ?? $b['phone']))];
            } else {
                $chips[] = $label;
            }
        }

        $messages = [];

        if ($actions) {
            // A buttons template carries the actions. If the body is too long for
            // the template, send it as its own bubble first.
            if (mb_strlen($body) > self::TEMPLATE_TEXT_LIMIT) {
                $messages[] = self::textMessage($body);
                $tplText = '↓';
            } else {
                $tplText = $body !== '' ? $body : '↓';
            }
            // Any plain reply buttons ride along as extra template actions.
            foreach ($chips as $c) {
                $actions[] = ['type' => 'message', 'label' => $c, 'text' => $c];
            }
            $messages[] = self::buttonsTemplate($tplText, $actions, $body);
        } else {
            $msg = self::textMessage($body !== '' ? $body : ' ');
            if ($chips) {
                $msg = self::withQuickReplies($msg, $chips);
            }
            $messages[] = $msg;
        }

        return array_slice($messages, 0, 5);
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

    /**
     * Issue a v3 stateless channel access token (grant_type=client_credentials,
     * ~15-min TTL, no JWK setup) from a channel id + secret. Kept static so the
     * caller can rotate without a stored long-lived token. Returns
     * ['ok','access_token','expires_in','error']. (The revocable 30-day v2.1 JWT
     * token needs a console-registered assertion key — a later addition; this
     * covers rotation for tenants that only pasted id+secret.)
     */
    public static function issueStatelessToken(string $channelId, string $channelSecret): array
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout(20)
                ->post(self::BASE.'/oauth2/v3/token', [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $channelId,
                    'client_secret' => $channelSecret,
                ]);
            $data = $res->json() ?? [];

            return [
                'ok'           => $res->successful() && ! empty($data['access_token']),
                'access_token' => (string) ($data['access_token'] ?? ''),
                'expires_in'   => (int) ($data['expires_in'] ?? 0),
                'error'        => $res->successful() ? '' : (string) ($data['error_description'] ?? $data['error'] ?? $res->body()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'access_token' => '', 'expires_in' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Issue a revocable v2.1 channel access token (≤30 days, ≤30/channel) from a
     * signed JWT assertion. Needs an RSA-2048 Assertion Signing Key registered in
     * the LINE console (its JWK `kid`) — build the JWT with buildAssertionJwt().
     * Returns ['ok','access_token','key_id','expires_in','error']; store key_id
     * to later revoke via revokeChannelAccessTokenV21().
     */
    public static function issueChannelAccessTokenV21(string $jwt): array
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout(20)
                ->post(self::BASE.'/oauth2/v2.1/token', [
                    'grant_type'            => 'client_credentials',
                    'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                    'client_assertion'      => $jwt,
                ]);
            $data = $res->json() ?? [];

            return [
                'ok'           => $res->successful() && ! empty($data['access_token']),
                'access_token' => (string) ($data['access_token'] ?? ''),
                'key_id'       => (string) ($data['key_id'] ?? ''),
                'expires_in'   => (int) ($data['expires_in'] ?? 0),
                'error'        => $res->successful() ? '' : (string) ($data['error_description'] ?? $data['error'] ?? $res->body()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'access_token' => '', 'key_id' => '', 'expires_in' => 0, 'error' => $e->getMessage()];
        }
    }

    /** Revoke a v2.1 token so a leaked one can be killed without rotating all. */
    public static function revokeChannelAccessTokenV21(string $channelId, string $channelSecret, string $accessToken): array
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout(20)
                ->post(self::BASE.'/oauth2/v2.1/revoke', [
                    'client_id'     => $channelId,
                    'client_secret' => $channelSecret,
                    'access_token'  => $accessToken,
                ]);

            return ['ok' => $res->successful(), 'error' => $res->successful() ? '' : (string) $res->body()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the RS256-signed JWT assertion for the v2.1 token endpoint. Signs
     * with openssl (no external dependency). $privateKeyPem is the RSA private
     * key of the console-registered Assertion Signing Key; $kid is its JWK kid.
     * Claims per LINE spec: iss/sub=channelId, aud="https://api.line.me/",
     * exp≤30min, token_exp≤30days. Returns the compact JWT, or '' on failure.
     */
    public static function buildAssertionJwt(string $channelId, string $kid, string $privateKeyPem, int $tokenExpSeconds = 2592000, int $nowTs = 0): string
    {
        try {
            $now = $nowTs > 0 ? $nowTs : time();
            $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
            $claims = $b64(json_encode([
                'iss'       => $channelId,
                'sub'       => $channelId,
                'aud'       => 'https://api.line.me/',
                'exp'       => $now + 1800,                       // assertion life ≤30min
                'token_exp' => min(max($tokenExpSeconds, 60), 2592000), // issued-token life ≤30d
            ]));
            $signingInput = $header.'.'.$claims;
            $key = openssl_pkey_get_private($privateKeyPem);
            if (! $key) {
                return '';
            }
            $sig = '';
            if (! openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) {
                return '';
            }

            return $signingInput.'.'.$b64($sig);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ── Flex ─────────────────────────────────────────────────────────────
    /** A Flex message wrapper — contents is a validated bubble/carousel object. */
    public static function flexMessage(string $altText, array $contents): array
    {
        return ['type' => 'flex', 'altText' => mb_substr($altText !== '' ? $altText : 'Flex message', 0, 400), 'contents' => $contents];
    }

    // ── Narrowcast (billable, 60 req/hour) ───────────────────────────────
    /**
     * Send to a filtered audience. `filter.demographic` targets age/gender/area/
     * appType/subscriptionPeriod; `limit` MUST be an object {max, ...}. LINE
     * answers 202 + an X-Line-Request-Id header used to poll progress.
     */
    public function narrowcast(array $messages, ?array $recipient = null, ?array $filter = null, ?array $limit = null): array
    {
        $body = ['messages' => array_slice(array_values($messages), 0, 5)];
        if ($recipient) {
            $body['recipient'] = $recipient;
        }
        if ($filter) {
            $body['filter'] = $filter;
        }
        if ($limit) {
            $body['limit'] = $limit;   // object: {max, upToRemainingQuota?, forbidPartialDelivery?}
        }

        return $this->callWithRequestId('POST', '/v2/bot/message/narrowcast', $body);
    }

    public function narrowcastProgress(string $requestId): array
    {
        return $this->get('/v2/bot/message/progress/narrowcast?requestId='.rawurlencode($requestId));
    }

    // ── Insights / quota (billing guardrails + dashboard) ────────────────
    /** Monthly push quota: {type:none|limited, value?}. */
    public function messageQuota(): array
    {
        return $this->get('/v2/bot/message/quota');
    }

    /** Messages already consumed this period: {totalUsage}. */
    public function messageQuotaConsumption(): array
    {
        return $this->get('/v2/bot/message/quota/consumption');
    }

    /** Number of message deliveries on a UTC date (yyyyMMdd). */
    public function insightMessageDelivery(string $date): array
    {
        return $this->get('/v2/bot/insight/message/delivery?date='.rawurlencode($date));
    }

    /** Follower counts (followers/targetedReaches/blocks) on a date (yyyyMMdd). */
    public function insightFollowers(string $date): array
    {
        return $this->get('/v2/bot/insight/followers?date='.rawurlencode($date));
    }

    /** Friend demographics (gender/age/area/appType/subscriptionPeriod). */
    public function insightDemographic(): array
    {
        return $this->get('/v2/bot/insight/demographic');
    }

    // ── Rich menus (CRUD 100 req/hour) ───────────────────────────────────
    /** Create a rich menu object → returns {richMenuId}. Image is uploaded after. */
    public function createRichMenu(array $richMenu): array
    {
        return $this->call('POST', '/v2/bot/richmenu', $richMenu);
    }

    public function deleteRichMenu(string $richMenuId): array
    {
        return $this->call('DELETE', '/v2/bot/richmenu/'.rawurlencode($richMenuId));
    }

    public function getRichMenu(string $richMenuId): array
    {
        return $this->get('/v2/bot/richmenu/'.rawurlencode($richMenuId));
    }

    public function listRichMenus(): array
    {
        return $this->get('/v2/bot/richmenu/list');
    }

    /** Upload the menu image (PNG/JPEG) — raw body to the DATA host. */
    public function uploadRichMenuImage(string $richMenuId, string $bytes, string $contentType = 'image/png'): array
    {
        try {
            $res = Http::withToken($this->token())
                ->withBody($bytes, $contentType)
                ->timeout(60)
                ->post(self::DATA_BASE.'/v2/bot/richmenu/'.rawurlencode($richMenuId).'/content');

            return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => [], 'error' => $res->successful() ? '' : (string) $res->body()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    public function setDefaultRichMenu(string $richMenuId): array
    {
        return $this->call('POST', '/v2/bot/user/all/richmenu/'.rawurlencode($richMenuId));
    }

    public function cancelDefaultRichMenu(): array
    {
        return $this->call('DELETE', '/v2/bot/user/all/richmenu');
    }

    public function linkRichMenuToUser(string $userId, string $richMenuId): array
    {
        return $this->call('POST', '/v2/bot/user/'.rawurlencode($userId).'/richmenu/'.rawurlencode($richMenuId));
    }

    public function unlinkRichMenuFromUser(string $userId): array
    {
        return $this->call('DELETE', '/v2/bot/user/'.rawurlencode($userId).'/richmenu');
    }

    // Rich-menu aliases (richMenuAliasId ^[a-z0-9_-]{1,32}$) — for richmenuswitch.
    public function createRichMenuAlias(string $aliasId, string $richMenuId): array
    {
        return $this->call('POST', '/v2/bot/richmenu/alias', ['richMenuAliasId' => $aliasId, 'richMenuId' => $richMenuId]);
    }

    public function updateRichMenuAlias(string $aliasId, string $richMenuId): array
    {
        return $this->call('POST', '/v2/bot/richmenu/alias/'.rawurlencode($aliasId), ['richMenuId' => $richMenuId]);
    }

    public function deleteRichMenuAlias(string $aliasId): array
    {
        return $this->call('DELETE', '/v2/bot/richmenu/alias/'.rawurlencode($aliasId));
    }

    public function getRichMenuAliasList(): array
    {
        return $this->get('/v2/bot/richmenu/alias/list');
    }

    // ── Loading indicator ────────────────────────────────────────────────
    /** Show the animated “…” in a 1:1 chat (5–60s, step 5). LINE answers 202. */
    public function showLoading(string $chatId, int $seconds = 20): array
    {
        $s = max(5, min(60, (int) (round($seconds / 5) * 5)));

        return $this->call('POST', '/v2/bot/chat/loading/start', ['chatId' => $chatId, 'loadingSeconds' => $s]);
    }

    // ── HTTP core (never throws) ─────────────────────────────────────────
    private function get(string $path): array
    {
        return $this->call('GET', $path);
    }

    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $req = Http::withToken($this->token())->acceptJson()->timeout(20);
            $res = match (strtoupper($method)) {
                'GET'    => $req->get(self::BASE.$path),
                'PUT'    => $req->put(self::BASE.$path, $body),
                'DELETE' => $req->delete(self::BASE.$path, $body),
                default  => $req->post(self::BASE.$path, $body),
            };
            $data = $res->json() ?? [];

            return [
                'ok'     => $res->successful(),
                'status' => $res->status(),
                'data'   => is_array($data) ? $data : [],
                'error'  => $res->successful() ? '' : (string) ($data['message'] ?? $res->body()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Like call() but also returns the X-Line-Request-Id header — narrowcast
     * answers 202 with no body and this id is what you poll progress with.
     */
    private function callWithRequestId(string $method, string $path, array $body = []): array
    {
        try {
            $req = Http::withToken($this->token())->acceptJson()->timeout(20);
            $res = strtoupper($method) === 'POST' ? $req->post(self::BASE.$path, $body) : $req->send($method, self::BASE.$path, ['json' => $body]);
            $data = $res->json() ?? [];

            return [
                'ok'         => $res->successful(),
                'status'     => $res->status(),
                'data'       => is_array($data) ? $data : [],
                'request_id' => (string) $res->header('X-Line-Request-Id'),
                'error'      => $res->successful() ? '' : (string) ($data['message'] ?? $res->body()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'request_id' => '', 'error' => $e->getMessage()];
        }
    }
}
