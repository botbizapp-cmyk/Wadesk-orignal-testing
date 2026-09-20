<?php

namespace App\Services\Tiktok;

use App\Models\SystemSetting;
use App\Models\TiktokAccount;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Business Messaging + Business Account API (app "B" — business-api.tiktok.com).
 *
 * ⚠️ PARTNER-GATED + REGION-LOCKED. This is a SEPARATE app from the Login Kit app
 * used by the MVP: it uses `app_id` + `secret`, an OAuth token passed in the
 * `Access-Token` HEADER (not Bearer), and requires TikTok **Messaging Partner**
 * approval before the Business Messaging endpoints are enabled. The organic DM
 * inbox is unavailable in the US / EEA / Switzerland / UK — always gate with
 * {@see TiktokAvailability} before calling.
 *
 * Endpoint paths verified against the TikTok Business API v1.3 Postman
 * collection and the community Go SDK (github.com/bububa/tiktok-business) —
 * the Business Messaging surface lives under `/open_api/v1.3/business/message/…`
 * (singular "message"), e.g. `conversation/list/`, `content/list/`, `send/`,
 * `media/upload/`, `capabilities/get/`. Inbound is delivered to the webhook URL
 * you register on the app in the TikTok developer portal — there is NO API call
 * to subscribe a webhook (see {@see subscribeWebhook}). Every method still fails
 * soft (returns ok:false) so a not-yet-approved partner app never breaks a page.
 * Confirm the exact `recipient`/`recipient_type` send-target semantics on the
 * partner portal once your Messaging-Partner app is approved.
 */
class TiktokBusinessClient
{
    private const BASE = 'https://business-api.tiktok.com/open_api/v1.3';

    public string $lastError = '';

    public function __construct(private TiktokAccount $account) {}

    // ── Business app credentials (admin-configured; separate from Login Kit) ──

    public static function appId(): string
    {
        return (string) SystemSetting::get('tiktok_business_app_id', '');
    }

    public static function appSecret(): string
    {
        return (string) SystemSetting::get('tiktok_business_app_secret', '');
    }

    public static function enabled(): bool
    {
        return (bool) SystemSetting::get('tiktok_inbox_enabled', false)
            && self::appId() !== '' && self::appSecret() !== '';
    }

    /** The business access token + business_id are stored on the account meta. */
    private function accessToken(): string
    {
        return (string) data_get($this->account->meta_json, 'business.access_token', '');
    }

    private function businessId(): string
    {
        return (string) data_get($this->account->meta_json, 'business.business_id', '');
    }

    /** All Business API calls carry the token in the Access-Token header. */
    private function req()
    {
        return Http::withHeaders(['Access-Token' => $this->accessToken()])
            ->acceptJson()->timeout(20);
    }

    // ── Business account info (region detection for gating) ──────────────────

    /** Get business account profile — includes the region used by TiktokAvailability. */
    public function getBusinessInfo(): array
    {
        try {
            $r = $this->req()->get(self::BASE.'/business/get/', [
                'business_id' => $this->businessId(),
                'fields'      => json_encode(['username', 'display_name', 'region', 'followers_count']),
            ]);

            return $r->successful() ? (array) ($r->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Business Messaging (DM inbox) — partner-only ─────────────────────────

    /** List conversations for the inbox sync. */
    public function getConversations(array $params = []): array
    {
        return $this->call('get', '/business/message/conversation/list/', array_merge(['business_id' => $this->businessId()], $params));
    }

    /** Thread history / backfill for one conversation (verified path: content/list). */
    public function getMessages(string $conversationId, array $params = []): array
    {
        return $this->call('get', '/business/message/content/list/', array_merge([
            'business_id'     => $this->businessId(),
            'conversation_id' => $conversationId,
        ], $params));
    }

    /**
     * Send a text DM in an existing conversation. Reactive only — TikTok caps a
     * business to 10 messages in the 48h after the user's first message; unlimited
     * for 48h after each user reply. Enforce the window before calling.
     */
    public function sendMessage(string $conversationId, string $text): array
    {
        // Verified path /business/message/send/ with the flat field structure
        // (message_type + text). We target the existing thread by conversation_id;
        // the SDK also exposes recipient/recipient_type for opening a new thread —
        // confirm which your partner app requires for reactive replies.
        $res = $this->call('post', '/business/message/send/', [
            'business_id'     => $this->businessId(),
            'conversation_id' => $conversationId,
            'message_type'    => 'text',
            'text'            => mb_substr($text, 0, 2000),
        ]);

        return ['ok' => ! empty($res['ok']), 'id' => (string) data_get($res, 'data.message_id', ''), 'error' => $res['error'] ?? null];
    }

    /** Send an image DM by a public URL / uploaded media id. */
    public function sendImage(string $conversationId, string $imageUrl): array
    {
        $res = $this->call('post', '/business/message/send/', [
            'business_id'     => $this->businessId(),
            'conversation_id' => $conversationId,
            'message_type'    => 'image',
            'image'           => $imageUrl,
        ]);

        return ['ok' => ! empty($res['ok']), 'id' => (string) data_get($res, 'data.message_id', ''), 'error' => $res['error'] ?? null];
    }

    // ── Comment management (Business Account API) ────────────────────────────

    /** Reply to a comment on an owned video (moderation; NOT an auto-trigger). */
    public function replyComment(string $commentId, string $text): array
    {
        $res = $this->call('post', '/business/comment/reply/create/', [
            'business_id' => $this->businessId(),
            'comment_id'  => $commentId,
            'text'        => mb_substr($text, 0, 2000),
        ]);

        return ['ok' => ! empty($res['ok']), 'error' => $res['error'] ?? null];
    }

    /** List comments on an owned video (poll — no organic webhook). */
    public function listComments(string $videoId, array $params = []): array
    {
        return $this->call('get', '/business/comment/list/', array_merge([
            'business_id' => $this->businessId(),
            'video_id'    => $videoId,
        ], $params));
    }

    /** Hide / unhide a comment. */
    public function hideComment(string $commentId, bool $hide = true): array
    {
        $res = $this->call('post', '/business/comment/hide/', [
            'business_id' => $this->businessId(),
            'comment_id'  => $commentId,
            'action'      => $hide ? 'HIDE' : 'UNHIDE',
        ]);

        return ['ok' => ! empty($res['ok']), 'error' => $res['error'] ?? null];
    }

    // ── Webhook subscription (inbound DM delivery) ───────────────────────────

    public function subscribeWebhook(string $callbackUrl): array
    {
        // TikTok delivers Business Messaging webhooks to the callback URL you set
        // on the APP in the developer portal (Manage apps → your app → Webhooks),
        // where you subscribe the new_message / new_conversation events. There is
        // NO per-account subscribe API (unlike Meta's subscribed_apps), so this is
        // a no-op that hands the operator the URL to register in the portal.
        return [
            'ok'            => true,
            'portal_config' => true,
            'callback_url'  => $callbackUrl,
            'note'          => 'Register this callback URL and subscribe the new_message / new_conversation events on the TikTok app in the developer portal — TikTok has no per-account webhook-subscribe API.',
        ];
    }

    /**
     * Shared caller. The Business API wraps everything as {code:0, message:"OK",
     * data:{…}} — code 0 is success. Fails soft so an unverified/ungated path
     * only reports an error, never throws.
     */
    private function call(string $method, string $path, array $payload): array
    {
        if (! self::enabled()) {
            return ['ok' => false, 'error' => 'TikTok Business (inbox) is not enabled / not partner-approved yet.'];
        }
        try {
            $r = $method === 'get'
                ? $this->req()->get(self::BASE.$path, $payload)
                : $this->req()->asJson()->post(self::BASE.$path, $payload);
            $j = (array) $r->json();
            $ok = $r->successful() && (int) ($j['code'] ?? -1) === 0;
            if (! $ok) {
                $this->lastError = (string) ($j['message'] ?? 'request failed');
            }

            return ['ok' => $ok, 'data' => $j['data'] ?? [], 'error' => $ok ? null : $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
