<?php

namespace App\Services\WeChat;

use App\Models\WeChatChannel;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the WeChat Official Account API (host api.weixin.qq.com).
 * Every method returns a plain array and never throws (a channel being down is an
 * expected reply-path outcome, mirrors LineClient). The shared access_token is
 * managed on the channel row (WeChatChannel::activeAccessToken) — cached + locked
 * because WeChat invalidates the old token whenever a new one is fetched.
 *
 * Verified endpoints (official docs):
 *   token   : GET  /cgi-bin/token?grant_type=client_credential&appid=&secret=
 *   send    : POST /cgi-bin/message/custom/send?access_token=   (JSON, 48h window)
 *   profile : GET  /cgi-bin/user/info?access_token=&openid=&lang=
 *   media   : POST /cgi-bin/media/upload  · GET /cgi-bin/media/get
 */
class WeChatClient
{
    private const BASE = 'https://api.weixin.qq.com';

    /** WeChat text content ceiling (bytes ~2048; keep conservative in chars). */
    public const TEXT_LIMIT = 2048;

    public function __construct(private readonly WeChatChannel $channel) {}

    // ── access_token (static — used by the channel's token manager) ──────
    public static function fetchAccessToken(string $appId, string $appSecret): array
    {
        try {
            $res = Http::acceptJson()->timeout(15)->get(self::BASE.'/cgi-bin/token', [
                'grant_type' => 'client_credential',
                'appid'      => $appId,
                'secret'     => $appSecret,
            ]);
            $j = (array) ($res->json() ?? []);
            if (! empty($j['access_token'])) {
                return ['ok' => true, 'access_token' => (string) $j['access_token'], 'expires_in' => (int) ($j['expires_in'] ?? 7200), 'error' => ''];
            }

            return ['ok' => false, 'access_token' => '', 'expires_in' => 0,
                'error' => trim((string) ($j['errcode'] ?? '').' '.(string) ($j['errmsg'] ?? $res->body()))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'access_token' => '', 'expires_in' => 0, 'error' => $e->getMessage()];
        }
    }

    // ── Send (customer-service message, JSON, within the 48h window) ─────
    /** Push a message to one OpenID. $message is a builder output (msgtype+body). */
    public function sendCustomMessage(string $openid, array $message): array
    {
        return $this->call('POST', '/cgi-bin/message/custom/send', [], array_merge(['touser' => $openid], $message));
    }

    // ── Mass send (群发 — broadcast; Service Accounts ~4/month) ──────────
    /** Broadcast to a specific OpenID list (≥2). Returns {ok,data:{msg_id},error}. */
    public function massSend(array $openids, array $message): array
    {
        $body = array_merge(['touser' => array_slice(array_values($openids), 0, 10000)], $message);

        return $this->call('POST', '/cgi-bin/message/mass/send', [], $body);
    }

    /** Broadcast to ALL followers or a tag. $filter = ['is_to_all'=>true] or ['tag_id'=>N]. */
    public function massSendAll(array $filter, array $message): array
    {
        $body = array_merge(['filter' => $filter], $message);

        return $this->call('POST', '/cgi-bin/message/mass/sendall', [], $body);
    }

    // ── Profile ──────────────────────────────────────────────────────────
    /** Basic user info: subscribe, openid, nickname, headimgurl, unionid?, … */
    public function getUserInfo(string $openid): array
    {
        return $this->call('GET', '/cgi-bin/user/info', ['openid' => $openid, 'lang' => 'en']);
    }

    // ── Media (temporary — media_id valid ~3 days) ──────────────────────
    /** Upload bytes → media_id. $type = image|voice|video|thumb. */
    public function uploadMedia(string $type, string $bytes, string $filename): array
    {
        $token = $this->channel->activeAccessToken();
        if ($token === '') {
            return ['ok' => false, 'media_id' => '', 'error' => 'no access_token'];
        }
        try {
            $res = Http::attach('media', $bytes, $filename)->timeout(30)
                ->post(self::BASE.'/cgi-bin/media/upload?access_token='.urlencode($token).'&type='.urlencode($type));
            $j = (array) ($res->json() ?? []);
            if (! empty($j['media_id'])) {
                return ['ok' => true, 'media_id' => (string) $j['media_id'], 'error' => ''];
            }

            return ['ok' => false, 'media_id' => '', 'error' => trim((string) ($j['errcode'] ?? '').' '.(string) ($j['errmsg'] ?? ''))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'media_id' => '', 'error' => $e->getMessage()];
        }
    }

    /** Download inbound media bytes (or null). WeChat returns JSON on error. */
    public function getMedia(string $mediaId): ?string
    {
        $token = $this->channel->activeAccessToken();
        if ($token === '') {
            return null;
        }
        try {
            $res = Http::timeout(30)->get(self::BASE.'/cgi-bin/media/get', ['access_token' => $token, 'media_id' => $mediaId]);
            $ct = strtolower((string) $res->header('Content-Type'));
            if ($res->successful() && ! str_contains($ct, 'application/json') && ! str_contains($ct, 'text/plain')) {
                return $res->body();
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Message builders ─────────────────────────────────────────────────
    public static function textMessage(string $text): array
    {
        return ['msgtype' => 'text', 'text' => ['content' => mb_substr($text, 0, self::TEXT_LIMIT)]];
    }

    public static function imageMessage(string $mediaId): array
    {
        return ['msgtype' => 'image', 'image' => ['media_id' => $mediaId]];
    }

    public static function voiceMessage(string $mediaId): array
    {
        return ['msgtype' => 'voice', 'voice' => ['media_id' => $mediaId]];
    }

    public static function videoMessage(string $mediaId, string $thumbMediaId, string $title = '', string $desc = ''): array
    {
        return ['msgtype' => 'video', 'video' => array_filter([
            'media_id' => $mediaId, 'thumb_media_id' => $thumbMediaId,
            'title' => $title ?: null, 'description' => $desc ?: null,
        ], fn ($v) => $v !== null)];
    }

    /** External-link article cards (≤8). Each: [title, description, url, picurl]. */
    public static function newsMessage(array $articles): array
    {
        return ['msgtype' => 'news', 'news' => ['articles' => array_slice(array_values($articles), 0, 8)]];
    }

    /** A tappable menu list — each item [id, content]. */
    public static function msgmenuMessage(string $head, array $items, string $tail = ''): array
    {
        return ['msgtype' => 'msgmenu', 'msgmenu' => array_filter([
            'head_content' => $head ?: null,
            'list'         => array_values($items),
            'tail_content' => $tail ?: null,
        ], fn ($v) => $v !== null)];
    }

    /**
     * Map a local (non-Meta) template — body + optional buttons — to WeChat
     * message object(s). Reply-type buttons become a tappable **msgmenu** (a tap
     * comes back as a text message the keyword/flow engine can match); URL/phone
     * buttons become text lines (WeChat auto-links URLs). Returns one message.
     *
     * @param  array<int, array<string, mixed>>  $buttons
     * @return array<int, array<string, mixed>>
     */
    public static function templateToMessages(string $body, array $buttons = [], array $vars = []): array
    {
        $body = self::fill($body, $vars);

        $menuItems = [];
        $urlLines  = [];
        foreach ($buttons as $i => $b) {
            $type  = strtolower((string) ($b['type'] ?? ($b['sub_type'] ?? 'quick_reply')));
            $label = trim((string) ($b['text'] ?? ($b['title'] ?? '')));
            if ($label === '') {
                continue;
            }
            if (in_array($type, ['url', 'quick_reply_url'], true) && trim((string) ($b['url'] ?? '')) !== '') {
                $urlLines[] = $label.': '.trim((string) $b['url']);
            } elseif (in_array($type, ['phone_number', 'call', 'phone'], true)
                && trim((string) ($b['phone_number'] ?? ($b['phone'] ?? ''))) !== '') {
                $urlLines[] = $label.': '.trim((string) ($b['phone_number'] ?? $b['phone']));
            } else {
                $menuItems[] = ['id' => 'opt_'.$i, 'content' => mb_substr($label, 0, 200)];
            }
        }
        $tail = $urlLines ? implode("\n", $urlLines) : '';

        if ($menuItems) {
            return [self::msgmenuMessage($body, $menuItems, $tail)];
        }

        $text = $body.($tail !== '' ? "\n\n".$tail : '');

        return [self::textMessage($text !== '' ? $text : ' ')];
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

    // ── Template message (模板消息 — send OUTSIDE the 48h window) ──────────
    /**
     * Send a Template Message — the WeChat way to message a user outside the 48h
     * customer-service window (transactional/notification). Needs a pre-approved
     * template_id from the OA. $data = ['keyword1'=>['value'=>'','color'=>'#..'],…].
     */
    public function sendTemplate(string $openid, string $templateId, array $data, string $url = '', array $miniprogram = []): array
    {
        $body = array_filter([
            'touser'      => $openid,
            'template_id' => $templateId,
            'url'         => $url ?: null,
            'miniprogram' => $miniprogram ?: null,
            'data'        => (object) $data,
        ], fn ($v) => $v !== null);

        return $this->call('POST', '/cgi-bin/message/template/send', [], $body);
    }

    // ── Custom menu (自定义菜单 — the persistent bottom bar) ────────────────
    /** Create/replace the OA menu. $buttons = the `button` array (≤3 top items). */
    public function createMenu(array $buttons): array
    {
        return $this->call('POST', '/cgi-bin/menu/create', [], ['button' => array_values($buttons)]);
    }

    public function getMenu(): array
    {
        return $this->call('GET', '/cgi-bin/menu/get');
    }

    public function deleteMenu(): array
    {
        return $this->call('GET', '/cgi-bin/menu/delete');
    }

    // ── Insights (datacube) ──────────────────────────────────────────────
    /** New/cancel followers per day (max 7-day range, yyyy-mm-dd). */
    public function getUserSummary(string $begin, string $end): array
    {
        return $this->call('POST', '/datacube/getusersummary', [], ['begin_date' => $begin, 'end_date' => $end]);
    }

    /** Cumulative follower count per day (max 7-day range). */
    public function getUserCumulate(string $begin, string $end): array
    {
        return $this->call('POST', '/datacube/getusercumulate', [], ['begin_date' => $begin, 'end_date' => $end]);
    }

    // ── Parametric QR (带参数二维码 — attribution) ─────────────────────────
    /** Create a scene QR. Returns {ticket,url,expire_seconds}; render at showqrcode?ticket=. */
    public function createQrCode(string $sceneStr, int $expireSeconds = 0): array
    {
        $body = $expireSeconds > 0
            ? ['expire_seconds' => $expireSeconds, 'action_name' => 'QR_STR_SCENE', 'action_info' => ['scene' => ['scene_str' => $sceneStr]]]
            : ['action_name' => 'QR_LIMIT_STR_SCENE', 'action_info' => ['scene' => ['scene_str' => $sceneStr]]];

        return $this->call('POST', '/cgi-bin/qrcode/create', [], $body);
    }

    /** Public image URL for a QR ticket. */
    public static function qrImageUrl(string $ticket): string
    {
        return 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket='.urlencode($ticket);
    }

    // ── Typing indicator ─────────────────────────────────────────────────
    public function showTyping(string $openid, bool $on = true): array
    {
        return $this->call('POST', '/cgi-bin/message/custom/typing', [], ['touser' => $openid, 'command' => $on ? 'Typing' : 'CancelTyping']);
    }

    // ── HTTP core (never throws; refreshes the token once on 40001/42001) ─
    private function call(string $method, string $path, array $query = [], array $body = [], bool $retry = true): array
    {
        $token = $this->channel->activeAccessToken();
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'no access_token'];
        }
        try {
            $query['access_token'] = $token;
            $req = Http::acceptJson()->timeout(20);
            $res = strtoupper($method) === 'GET'
                ? $req->get(self::BASE.$path, $query)
                : $req->withBody(json_encode($body, JSON_UNESCAPED_UNICODE), 'application/json')
                       ->post(self::BASE.$path.'?'.http_build_query($query));

            $data    = (array) ($res->json() ?? []);
            $errcode = (int) ($data['errcode'] ?? 0);

            // Token invalid/expired → refresh once and retry.
            if ($retry && in_array($errcode, [40001, 42001, 40014], true)) {
                $this->channel->refreshAccessToken();

                return $this->call($method, $path, array_diff_key($query, ['access_token' => 1]), $body, false);
            }

            $ok = $res->successful() && $errcode === 0;

            return [
                'ok'     => $ok,
                'status' => $res->status(),
                'data'   => $data,
                'error'  => $ok ? '' : trim(($errcode ?: '').' '.(string) ($data['errmsg'] ?? $res->body())),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $e->getMessage()];
        }
    }
}
