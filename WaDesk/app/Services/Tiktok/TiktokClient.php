<?php

namespace App\Services\Tiktok;

use App\Models\SystemSetting;
use App\Models\TiktokAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the TikTok "for Developers" API (app A: Login Kit + Display
 * + Content Posting). OAuth/token helpers are static (no account instance);
 * per-account calls (user info, refresh) use the account's access token.
 *
 * Verified endpoints (official docs, OAuth v2):
 *   Authorize : GET  https://www.tiktok.com/v2/auth/authorize/      (consent redirect)
 *   Token     : POST https://open.tiktokapis.com/v2/oauth/token/     (code | refresh grants)
 *   Revoke    : POST https://open.tiktokapis.com/v2/oauth/revoke/
 *   User info : GET  https://open.tiktokapis.com/v2/user/info/?fields=…  (Bearer)
 *
 * Token lifetimes: access_token = expires_in (86400s / 24h);
 * refresh_token = refresh_expires_in (31536000s / 365d, rotates on refresh).
 */
class TiktokClient
{
    private const AUTH_BASE  = 'https://www.tiktok.com/v2/auth/authorize/';
    private const API_BASE   = 'https://open.tiktokapis.com';

    /** Scopes the MVP requests. user.info.basic is auto-granted; the rest need app approval. */
    public const SCOPES = ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list', 'video.upload'];

    public string $lastError = '';

    public function __construct(private ?TiktokAccount $account = null) {}

    // ── Credentials (admin-configured, per platform app A) ──────────────────

    public static function clientKey(): string
    {
        return (string) SystemSetting::get('tiktok_client_key', '');
    }

    public static function clientSecret(): string
    {
        return (string) SystemSetting::get('tiktok_client_secret', '');
    }

    public static function enabled(): bool
    {
        return (bool) SystemSetting::get('tiktok_enabled', false)
            && self::clientKey() !== '' && self::clientSecret() !== '';
    }

    /**
     * The OAuth redirect URI — MUST match a "Redirect URI" registered in the
     * TikTok portal (Login Kit) exactly (scheme + host + path, no trailing-slash
     * drift). TikTok requires https for web apps and rejects any mismatch with a
     * "redirect_uri" error. Prefer the admin-set fixed value (Admin → Channel
     * Settings → TikTok) so it is identical no matter how the app is reached
     * (www vs apex, sub-path, proxy scheme); fall back to the dynamic route URL.
     * Used identically by the authorize redirect AND the code exchange — they
     * must send the same value or TikTok rejects the exchange.
     */
    public static function redirectUri(): string
    {
        $fixed = trim((string) SystemSetting::get('tiktok_redirect_uri', ''));

        return $fixed !== '' ? $fixed : url('/tiktok/callback');
    }

    // ── OAuth (static) ──────────────────────────────────────────────────────

    /** Build the consent-redirect URL. `state` is the CSRF token, validated on callback. */
    public static function authorizeUrl(string $redirectUri, string $state, ?array $scopes = null): string
    {
        $params = [
            'client_key'    => self::clientKey(),
            'scope'         => implode(',', $scopes ?: self::SCOPES),
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ];

        return self::AUTH_BASE.'?'.http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{ok:bool, access_token?:string, refresh_token?:string, open_id?:string,
     *               scope?:string, expires_in?:int, refresh_expires_in?:int, error?:string}
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        return self::tokenRequest([
            'client_key'    => self::clientKey(),
            'client_secret' => self::clientSecret(),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
        ]);
    }

    /** Renew an access token from a refresh token (rotates the refresh token). */
    public static function refreshAccessToken(string $refreshToken): array
    {
        return self::tokenRequest([
            'client_key'    => self::clientKey(),
            'client_secret' => self::clientSecret(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /** Revoke a token (disconnect the app for this user). */
    public static function revoke(string $accessToken): array
    {
        try {
            $r = Http::asForm()->acceptJson()->timeout(15)
                ->post(self::API_BASE.'/v2/oauth/revoke/', [
                    'client_key'    => self::clientKey(),
                    'client_secret' => self::clientSecret(),
                    'token'         => $accessToken,
                ]);

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Shared /v2/oauth/token/ POST (form-encoded). Normalises success + error shapes. */
    private static function tokenRequest(array $form): array
    {
        try {
            $r = Http::asForm()->acceptJson()->timeout(15)
                ->post(self::API_BASE.'/v2/oauth/token/', $form);
            $j = (array) $r->json();

            // Deep trace of the /oauth/token exchange. NEVER logs client_secret —
            // only the grant, the redirect_uri (the value that must match the
            // portal), the client_key TAIL, the HTTP status and TikTok's raw
            // error + log_id (quote log_id in a TikTok support ticket).
            \Illuminate\Support\Facades\Log::info('[TIKTOK-OAUTH] token exchange', [
                'grant_type'        => (string) ($form['grant_type'] ?? ''),
                'redirect_uri'      => (string) ($form['redirect_uri'] ?? '(none)'),
                'client_key_tail'   => substr((string) ($form['client_key'] ?? ''), -6),
                'http_status'       => $r->status(),
                'ok'                => $r->successful() && ! empty($j['access_token']),
                'error'             => (string) ($j['error'] ?? ''),
                'error_description' => (string) ($j['error_description'] ?? ''),
                'log_id'            => (string) ($j['log_id'] ?? ''),
                'raw'               => mb_substr((string) json_encode($j), 0, 500),
            ]);

            // v2 returns the fields at the TOP level on success; errors carry an
            // `error` string + `error_description` (also top-level in v2 OAuth).
            if ($r->successful() && ! empty($j['access_token'])) {
                return [
                    'ok'                 => true,
                    'access_token'       => (string) $j['access_token'],
                    'refresh_token'      => (string) ($j['refresh_token'] ?? ''),
                    'open_id'            => (string) ($j['open_id'] ?? ''),
                    'scope'              => (string) ($j['scope'] ?? ''),
                    'expires_in'         => (int) ($j['expires_in'] ?? 86400),
                    'refresh_expires_in' => (int) ($j['refresh_expires_in'] ?? 31536000),
                ];
            }

            $err = (string) ($j['error_description'] ?? $j['error'] ?? 'token request failed');

            return ['ok' => false, 'error' => $err];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Instance (account access token) ─────────────────────────────────────

    /**
     * Get the connected user's profile + stats. Fields are scope-gated: basic
     * needs user.info.basic, username/bio/verified need user.info.profile,
     * counts need user.info.stats. Returns the `data.user` object, [] on failure.
     */
    public function getUserInfo(?array $fields = null): array
    {
        $fields = $fields ?: [
            'open_id', 'union_id', 'avatar_url', 'display_name', 'bio_description',
            'profile_deep_link', 'is_verified', 'username',
            'follower_count', 'following_count', 'likes_count', 'video_count',
        ];
        try {
            $r = Http::withToken($this->freshToken())->acceptJson()->timeout(15)
                ->get(self::API_BASE.'/v2/user/info/', ['fields' => implode(',', $fields)]);
            if ($r->successful()) {
                return (array) ($r->json('data.user') ?? []);
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'user info failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /** Default video fields pulled from the Display API (all under video.list scope). */
    public const VIDEO_FIELDS = [
        'id', 'title', 'video_description', 'duration', 'cover_image_url',
        'share_url', 'embed_link', 'create_time',
        'like_count', 'comment_count', 'share_count', 'view_count',
    ];

    /**
     * List the connected user's public videos (Display API), newest first.
     * `fields` is a query param; `max_count` (≤20) + `cursor` go in the body.
     *
     * @return array{videos:array, cursor:int, has_more:bool}
     */
    public function listVideos(int $maxCount = 20, ?int $cursor = null, ?array $fields = null): array
    {
        $body = ['max_count' => max(1, min(20, $maxCount))];
        if ($cursor !== null) {
            $body['cursor'] = $cursor;
        }
        try {
            $r = Http::withToken($this->freshToken())->acceptJson()->timeout(20)
                ->post(self::API_BASE.'/v2/video/list/?fields='.implode(',', $fields ?: self::VIDEO_FIELDS), $body);
            if ($r->successful()) {
                return [
                    'videos'   => (array) ($r->json('data.videos') ?? []),
                    'cursor'   => (int) ($r->json('data.cursor') ?? 0),
                    'has_more' => (bool) ($r->json('data.has_more') ?? false),
                ];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'video list failed');

            return ['videos' => [], 'cursor' => 0, 'has_more' => false];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['videos' => [], 'cursor' => 0, 'has_more' => false];
        }
    }

    /**
     * Verify / refresh metadata for up to 20 specific video ids. Use this to
     * refresh the TTL'd cover_image_url — those URLs expire and must never be
     * stored long-term. Returns data.videos[], [] on failure.
     */
    public function queryVideos(array $videoIds, ?array $fields = null): array
    {
        $ids = array_values(array_slice(array_filter(array_map('strval', $videoIds)), 0, 20));
        if ($ids === []) {
            return [];
        }
        try {
            $r = Http::withToken($this->freshToken())->acceptJson()->timeout(20)
                ->post(self::API_BASE.'/v2/video/query/?fields='.implode(',', $fields ?: self::VIDEO_FIELDS),
                    ['filters' => ['video_ids' => $ids]]);
            if ($r->successful()) {
                return (array) ($r->json('data.videos') ?? []);
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'video query failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Content Posting API (Upload/Inbox path — no app audit required) ─────

    /**
     * Query the creator's posting permissions (privacy options, interaction
     * toggles, max video duration, nickname). MUST precede a Direct Post; used
     * here to drive the composer UI. Returns data.* , [] on failure.
     */
    public function queryCreatorInfo(): array
    {
        try {
            $r = Http::withToken($this->freshToken())
                ->contentType('application/json; charset=UTF-8')->acceptJson()->timeout(20)
                ->post(self::API_BASE.'/v2/post/publish/creator_info/query/', (object) []);
            if ($r->successful()) {
                return (array) ($r->json('data') ?? []);
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'creator info failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Send a video to the creator's TikTok INBOX/DRAFTS via PULL_FROM_URL. The
     * creator finishes the post inside the TikTok app — this path needs the
     * video.upload scope only (no full app audit, unlike Direct Post). The
     * video_url host must be a VERIFIED URL-prefix property in the TikTok portal.
     *
     * @return array{ok:bool, publish_id?:string, error?:string}
     */
    public function initInboxVideo(string $videoUrl): array
    {
        try {
            $r = Http::withToken($this->freshToken())
                ->contentType('application/json; charset=UTF-8')->acceptJson()->timeout(25)
                ->post(self::API_BASE.'/v2/post/publish/inbox/video/init/', [
                    'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $videoUrl],
                ]);
            if ($r->successful() && $r->json('data.publish_id')) {
                return ['ok' => true, 'publish_id' => (string) $r->json('data.publish_id')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'video init failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a PHOTO post (1–35 images) to the creator's TikTok inbox/drafts via
     * PULL_FROM_URL. Same no-audit inbox path as video. Image host must be a
     * verified URL-prefix property in the TikTok portal.
     *
     * @return array{ok:bool, publish_id?:string, error?:string}
     */
    public function initInboxPhoto(array $imageUrls, string $caption = ''): array
    {
        $urls = array_values(array_slice(array_filter(array_map('strval', $imageUrls)), 0, 35));
        if ($urls === []) {
            return ['ok' => false, 'error' => 'no image urls'];
        }
        try {
            $r = Http::withToken($this->freshToken())
                ->contentType('application/json; charset=UTF-8')->acceptJson()->timeout(25)
                ->post(self::API_BASE.'/v2/post/publish/content/init/', [
                    'post_info'   => ['title' => mb_substr($caption, 0, 90), 'description' => mb_substr($caption, 0, 4000)],
                    'source_info' => ['source' => 'PULL_FROM_URL', 'photo_images' => $urls, 'photo_cover_index' => 0],
                    'post_mode'   => 'MEDIA_UPLOAD',
                    'media_type'  => 'PHOTO',
                ]);
            if ($r->successful() && $r->json('data.publish_id')) {
                return ['ok' => true, 'publish_id' => (string) $r->json('data.publish_id')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'photo init failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Poll the status of a publish by publish_id. Returns the raw data object
     * (status = PROCESSING_UPLOAD | SEND_TO_USER_INBOX | PUBLISH_COMPLETE |
     * FAILED, plus publicaly_available_post_id[] when live). [] on failure.
     */
    public function fetchPublishStatus(string $publishId): array
    {
        try {
            $r = Http::withToken($this->freshToken())
                ->contentType('application/json; charset=UTF-8')->acceptJson()->timeout(20)
                ->post(self::API_BASE.'/v2/post/publish/status/fetch/', ['publish_id' => $publishId]);
            if ($r->successful()) {
                return (array) ($r->json('data') ?? []);
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'status fetch failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Return a valid access token for this account, refreshing proactively when
     * it is within 5 minutes of expiry. Persists the rotated tokens. Falls back
     * to the stored token if refresh fails (the caller surfaces the API error).
     */
    public function freshToken(): string
    {
        $acct = $this->account;
        if (! $acct) {
            return '';
        }
        $nearExpiry = ! $acct->token_expires_at || $acct->token_expires_at->lt(Carbon::now()->addMinutes(5));
        if ($nearExpiry && $acct->refresh_token) {
            $res = self::refreshAccessToken((string) $acct->refresh_token);
            if (! empty($res['ok'])) {
                $acct->forceFill([
                    'access_token'       => $res['access_token'],
                    'refresh_token'      => $res['refresh_token'] ?: $acct->refresh_token,
                    'token_expires_at'   => Carbon::now()->addSeconds((int) $res['expires_in']),
                    'refresh_expires_at' => Carbon::now()->addSeconds((int) $res['refresh_expires_in']),
                    'status'             => 'connected',
                    'last_error'         => null,
                ])->save();
            }
        }

        return (string) $acct->access_token;
    }
}
