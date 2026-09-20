<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Facebook Graph API for the Pages channel. Mirrors
 * InstagramService: it REUSES the same Meta app already configured for
 * WhatsApp Embedded Signup (waba_app_id / waba_app_secret) unless a
 * Facebook-specific override is set (fb_app_id / fb_app_secret). All Page calls
 * use the Page access token; OAuth/token helpers are static (no Page instance).
 *
 * P0 scope: OAuth code exchange, short→long-lived USER token, /me/accounts Page
 * enumeration (each Page carries its own non-expiring PAGE token), token debug,
 * and per-Page webhook subscribe. Publishing / comments / insights land in
 * later phases.
 */
class FacebookPageClient
{
    private string $base;

    public string $lastError = '';

    public function __construct(private FacebookPage $page)
    {
        $this->base = 'https://graph.facebook.com/'.self::version();
    }

    // ── Shared credential resolution (reuse the WhatsApp Meta app) ──────────

    /** Graph API version — Facebook override, else the WhatsApp app's version, else v23.0. */
    public static function version(): string
    {
        return (string) (SystemSetting::get('fb_graph_version', '')
            ?: (SystemSetting::get('waba_graph_api_version', '') ?: 'v23.0'));
    }

    public static function appId(): string
    {
        return (string) (SystemSetting::get('fb_app_id', '') ?: SystemSetting::get('waba_app_id', ''));
    }

    public static function appSecret(): string
    {
        return (string) (SystemSetting::get('fb_app_secret', '') ?: SystemSetting::get('waba_app_secret', ''));
    }

    private function token(): string
    {
        return (string) $this->page->access_token;
    }

    /**
     * Inspect a Graph error and, if it's a token problem (OAuthException code
     * 190 — expired / revoked / password-changed / app removed), flag this Page
     * as needing re-auth so the UI shows a reconnect prompt. Cheap + idempotent.
     */
    private function noteGraphError($error): void
    {
        $error = (array) $error;
        $code = (int) ($error['code'] ?? 0);
        if ($code === 190) {
            try {
                $this->page->forceFill([
                    'status'     => 'expired',
                    'last_error' => mb_substr((string) ($error['message'] ?? 'Token expired — reconnect required.'), 0, 490),
                ])->save();
            } catch (\Throwable $e) {
                // best effort — never let flagging break the send
            }
        }
    }

    // ── Instance calls (Page token) ────────────────────────────────────────

    /** Read this Page's public profile (name, category, followers, picture). */
    public function getProfile(): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}", [
                    'fields' => 'id,name,category,username,fan_count,followers_count,picture.type(large){url}',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Subscribe this Page to webhook events (both layers are required: the
     * APP-level callback is configured once in the Meta dashboard / admin, and
     * this per-Page subscribed_apps binding tells Meta to deliver THIS Page's
     * events). Without it, no feed/comment/message webhook ever arrives.
     *
     * @return array{ok:bool, error?:string}
     */
    public function subscribeWebhooks(): array
    {
        // 'feed' delivers new posts, comments and reactions; the Messenger
        // fields deliver DMs. All are Page-level webhook fields.
        $fields = 'feed,mention,messages,messaging_postbacks,message_reactions,messaging_optins,messaging_referrals';
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)->asForm()
                ->post("{$this->base}/{$this->page->page_id}/subscribed_apps", ['subscribed_fields' => $fields]);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'subscribe failed');
            Log::warning('[FB-SUBSCRIBE] failed', ['page' => $this->page->id, 'error' => $this->lastError]);

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Read back the live subscribed_apps binding for this Page (diagnostics). */
    public function subscribedApps(): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/subscribed_apps");
            if (! $r->successful()) {
                return ['ok' => false, 'fields' => [], 'error' => (string) ($r->json('error.message') ?? 'read failed')];
            }
            $fields = [];
            foreach ((array) $r->json('data', []) as $app) {
                foreach ((array) ($app['subscribed_fields'] ?? []) as $f) {
                    $fields[] = is_array($f) ? (string) ($f['name'] ?? '') : (string) $f;
                }
            }

            return ['ok' => true, 'fields' => array_values(array_unique(array_filter($fields))), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => [], 'error' => $e->getMessage()];
        }
    }

    /** Remove this app's Page subscription (called on disconnect). */
    public function unsubscribeWebhooks(): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->delete("{$this->base}/{$this->page->page_id}/subscribed_apps");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Messenger + comments (Page token) ──────────────────────────────────

    /**
     * Send a Messenger message to a user (PSID) as the Page. messaging_type
     * RESPONSE keeps it inside the 24-hour standard messaging window. Returns
     * the message id on success.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendMessage(string $psid, string $text): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$this->page->page_id}/messages", [
                    'recipient'      => ['id' => $psid],
                    'messaging_type' => 'RESPONSE',
                    'message'        => ['text' => mb_substr($text, 0, 2000)],
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'mid' => (string) ($r->json('message_id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'send failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Public reply under a comment (or post) as the Page. */
    public function replyComment(string $objectId, string $text): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$objectId}/comments", ['message' => mb_substr($text, 0, 8000)]);
            if ($r->successful()) {
                return ['ok' => true, 'id' => (string) ($r->json('id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'reply failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Private reply to a comment — sends a Messenger DM to the commenter via the
     * Send API. One-time, within the 7-day window Meta allows after a comment.
     */
    public function privateReply(string $commentId, string $text): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$this->page->page_id}/messages", [
                    'recipient'      => ['comment_id' => $commentId],
                    'messaging_type' => 'RESPONSE',
                    'message'        => ['text' => mb_substr($text, 0, 2000)],
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'mid' => (string) ($r->json('message_id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'private reply failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Page-level insights (metrics like page_impressions, page_post_engagements). */
    public function pageInsights(array $metrics, string $period = 'days_28'): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/insights", [
                    'metric' => implode(',', $metrics),
                    'period' => $period,
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Per-post insights (post_impressions, post_engaged_users, …). */
    public function postInsights(string $postId, array $metrics): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$postId}/insights", ['metric' => implode(',', $metrics)]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Recent published posts with basic fields (for an analytics list). */
    public function recentPosts(int $limit = 10): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/published_posts", [
                    'fields' => 'id,message,created_time,permalink_url,full_picture',
                    'limit'  => $limit,
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Messenger user profile (name + avatar) for a PSID that messaged the Page. */
    public function getSenderProfile(string $psid): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(12)
                ->get("{$this->base}/{$psid}", ['fields' => 'name,first_name,last_name,profile_pic']);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Fetch a single comment (backfill message/from when a webhook omits them). */
    public function getComment(string $commentId): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(12)
                ->get("{$this->base}/{$commentId}", ['fields' => 'id,message,from{id,name,picture},created_time,parent{id},attachment']);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Publishing (Page token) ────────────────────────────────────────────

    /**
     * Publish (or schedule) a post on this Page. Spec keys:
     *   message?     text body
     *   link?        a link post
     *   photos?      array of image URLs (1 = single photo, >1 = multi-photo)
     *   scheduled_ts? unix timestamp → schedules instead of publishing now
     *
     * Single photo posts to /photos; multi-photo uploads each unpublished to
     * collect media_fbids then posts them together to /feed; text/link go to
     * /feed. Returns ['ok'=>bool, 'id'=>string, 'error'=>?string].
     */
    public function publish(array $spec): array
    {
        $pageId = $this->page->page_id;
        $photos = array_values(array_filter(array_map('strval', (array) ($spec['photos'] ?? []))));
        $scheduled = ! empty($spec['scheduled_ts']) ? (int) $spec['scheduled_ts'] : null;
        $message = trim((string) ($spec['message'] ?? ''));
        $link = trim((string) ($spec['link'] ?? ''));

        // Single photo (and not a link post) → /photos directly with a caption.
        if (count($photos) === 1 && $link === '') {
            $params = ['url' => $photos[0], 'caption' => $message];
            if ($scheduled) {
                $params['published'] = 'false';
                $params['scheduled_publish_time'] = $scheduled;
                // Disambiguate SCHEDULED from a plain draft on the /photos endpoint.
                $params['unpublished_content_type'] = 'SCHEDULED';
            }

            return $this->postGraph("{$pageId}/photos", $params, ['post_id', 'id']);
        }

        // Multi-photo → upload each as UNPUBLISHED, collect media_fbid, then /feed.
        $attached = [];
        foreach ($photos as $i => $url) {
            $r = $this->postGraph("{$pageId}/photos", ['url' => $url, 'published' => 'false'], ['id']);
            if (empty($r['ok'])) {
                return $r;
            }
            $attached["attached_media[{$i}]"] = json_encode(['media_fbid' => (string) $r['id']]);
        }

        $params = [];
        if ($message !== '') {
            $params['message'] = $message;
        }
        if ($link !== '') {
            $params['link'] = $link;
        }
        $params += $attached;
        if ($scheduled) {
            $params['published'] = 'false';
            $params['scheduled_publish_time'] = $scheduled;
        }

        return $this->postGraph("{$pageId}/feed", $params, ['id']);
    }

    /** Flip a scheduled/unpublished object live (is_published=true). */
    public function publishNow(string $objectId): array
    {
        return $this->postGraph($objectId, ['is_published' => 'true'], ['id', 'success']);
    }

    /**
     * Publish (or schedule) a feed VIDEO from a public file URL. Meta fetches
     * the file, so $fileUrl must be a public HTTPS URL. Returns the video id.
     */
    public function postVideo(string $fileUrl, string $description = '', ?int $scheduledTs = null): array
    {
        $params = ['file_url' => $fileUrl, 'description' => mb_substr($description, 0, 5000)];
        if ($scheduledTs) {
            $params['published'] = 'false';
            $params['scheduled_publish_time'] = $scheduledTs;
        }

        return $this->postGraph("{$this->page->page_id}/videos", $params, ['id']);
    }

    /**
     * Publish (or schedule) a REEL via the 3-phase video_reels API using a
     * hosted-file transfer (file_url header). Meta must be able to fetch
     * $fileUrl over public HTTPS. Returns the reel video id.
     *
     * @return array{ok:bool, id?:string, error?:string}
     */
    public function postReel(string $fileUrl, string $description = '', ?int $scheduledTs = null): array
    {
        $pageId = $this->page->page_id;
        $token = $this->token();
        try {
            // 1 · START — reserve a video_id + upload_url.
            $start = Http::withToken($token)->acceptJson()->timeout(30)->asForm()
                ->post("{$this->base}/{$pageId}/video_reels", ['upload_phase' => 'start']);
            if (! $start->successful() || ! $start->json('video_id')) {
                $this->lastError = (string) ($start->json('error.message') ?? 'reel start failed');

                return ['ok' => false, 'error' => $this->lastError];
            }
            $videoId = (string) $start->json('video_id');
            $uploadUrl = (string) $start->json('upload_url');
            if ($uploadUrl === '') {
                $this->lastError = 'reel start returned no upload_url';

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2 · UPLOAD — hosted transfer: tell Meta where to pull the file from.
            $up = Http::withHeaders([
                'Authorization' => 'OAuth '.$token,
                'file_url'      => $fileUrl,
            ])->timeout(180)->post($uploadUrl);
            if (! $up->successful()) {
                $this->lastError = 'reel upload failed: '.mb_substr($up->body(), 0, 300);

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2b · WAIT — the rupload 200 only means Meta accepted the transfer
            //      request; for a hosted file_url it downloads async. Finishing
            //      before that completes intermittently fails ("still uploading").
            //      Poll the upload status (bounded) until it's ready.
            for ($i = 0; $i < 20; $i++) {
                $st = Http::withToken($token)->acceptJson()->timeout(15)
                    ->get("{$this->base}/{$videoId}", ['fields' => 'status']);
                $phase = (string) data_get($st->json(), 'status.uploading_phase.status', '');
                if ($phase === 'complete') {
                    break;
                }
                if ($phase === 'error') {
                    $this->lastError = 'reel upload processing failed';

                    return ['ok' => false, 'error' => $this->lastError];
                }
                usleep(2_000_000); // 2s; ~40s max
            }

            // 3 · FINISH — publish now or schedule. Reel captions cap at ~2200.
            $finish = ['upload_phase' => 'finish', 'video_id' => $videoId, 'description' => mb_substr($description, 0, 2200)];
            if ($scheduledTs) {
                $finish['video_state'] = 'SCHEDULED';
                $finish['scheduled_publish_time'] = $scheduledTs;
            } else {
                $finish['video_state'] = 'PUBLISHED';
            }
            $done = Http::withToken($token)->asForm()->acceptJson()->timeout(30)
                ->post("{$this->base}/{$pageId}/video_reels", $finish);
            if ($done->successful()) {
                return ['ok' => true, 'id' => $videoId, 'error' => null];
            }
            $this->lastError = (string) ($done->json('error.message') ?? 'reel finish failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Delete a published post. */
    public function deletePost(string $objectId): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)->delete("{$this->base}/{$objectId}");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Shared form POST → normalized result reading the first present id key. */
    private function postGraph(string $path, array $params, array $idKeys): array
    {
        try {
            $r = Http::withToken($this->token())->asForm()->acceptJson()->timeout(30)
                ->post("{$this->base}/{$path}", $params);
            if ($r->successful()) {
                $id = '';
                foreach ($idKeys as $k) {
                    if ($r->json($k) !== null) {
                        $id = (string) $r->json($k);
                        break;
                    }
                }

                return ['ok' => true, 'id' => $id, 'error' => null];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'request failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'id' => '', 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'id' => '', 'error' => $e->getMessage()];
        }
    }

    // ── Static OAuth + token helpers (no Page instance) ────────────────────

    /**
     * Exchange an OAuth code for a short-lived USER access token (redirect
     * flow). The embedded-signup popup exchanges WITHOUT a redirect_uri — pass
     * '' to omit it.
     *
     * @return array{ok:bool, access_token?:string, expires_in?:int, error?:string}
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($appId === '' || $secret === '' || $code === '') {
            return ['ok' => false, 'error' => 'Facebook Meta app credentials are not configured.'];
        }
        $params = ['client_id' => $appId, 'client_secret' => $secret, 'code' => $code];
        if ($redirectUri !== '') {
            $params['redirect_uri'] = $redirectUri;
        }
        try {
            $r = Http::acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/oauth/access_token', $params);
            if ($r->successful() && $r->json('access_token')) {
                return ['ok' => true, 'access_token' => (string) $r->json('access_token'), 'expires_in' => (int) $r->json('expires_in', 0)];
            }

            return ['ok' => false, 'error' => (string) ($r->json('error.message') ?? 'token exchange failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upgrade a short-lived USER token to a ~60-day long-lived token. MUST run
     * BEFORE /me/accounts so the derived PAGE tokens never expire — the single
     * most common Facebook-integration bug is skipping this step.
     *
     * @return array{ok:bool, access_token?:string, expires_in?:int, error?:string}
     */
    public static function extendUserToken(string $token): array
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($appId === '' || $secret === '' || $token === '') {
            return ['ok' => false, 'error' => 'missing app credentials or token'];
        }
        try {
            $r = Http::acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/oauth/access_token', [
                    'grant_type'        => 'fb_exchange_token',
                    'client_id'         => $appId,
                    'client_secret'     => $secret,
                    'fb_exchange_token' => $token,
                ]);
            if ($r->successful() && $r->json('access_token')) {
                return ['ok' => true, 'access_token' => (string) $r->json('access_token'), 'expires_in' => (int) $r->json('expires_in', 5184000)];
            }

            return ['ok' => false, 'error' => (string) ($r->json('error.message') ?? 'long-lived exchange failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Enumerate the Pages the authenticated user manages. Each row carries its
     * OWN Page access token (non-expiring when derived from a long-lived user
     * token) plus the granted tasks. This is the account→Pages fan-out.
     *
     * @return array<int, array<string,mixed>>  raw Page rows (id,name,category,access_token,tasks,fan_count,username,picture)
     */
    public static function listPages(string $userToken): array
    {
        if ($userToken === '') {
            return [];
        }
        try {
            $out = [];
            $url = 'https://graph.facebook.com/'.self::version().'/me/accounts';
            $params = ['fields' => 'id,name,category,access_token,tasks,fan_count,username,picture.type(large){url}', 'limit' => 100];
            // Follow pagination so accounts with many Pages are fully listed.
            for ($guard = 0; $guard < 10 && $url !== ''; $guard++) {
                $r = Http::withToken($userToken)->acceptJson()->timeout(20)->get($url, $params);
                if (! $r->successful()) {
                    break;
                }
                foreach ((array) $r->json('data', []) as $p) {
                    $out[] = (array) $p;
                }
                $url = (string) ($r->json('paging.next') ?? '');
                $params = []; // the `next` URL already carries the query string
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[FB-PAGES] listPages failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Resolve a single Page from a manually-pasted PAGE access token. A Page
     * token's /me returns the Page node itself. Used by the "manual" connect
     * path. Returns the Page fields + the granted tasks, or [] on failure.
     */
    public static function pageFromToken(string $pageToken): array
    {
        if ($pageToken === '') {
            return [];
        }
        try {
            $r = Http::withToken($pageToken)->acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/me', [
                    'fields' => 'id,name,category,username,fan_count,tasks,picture.type(large){url}',
                ]);

            return ($r->successful() && $r->json('id')) ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Validate any token — is_valid, scopes, expiry (diagnostics / re-auth). */
    public static function debugToken(string $token): array
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($token === '' || $appId === '' || $secret === '') {
            return [];
        }
        try {
            $r = Http::acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/debug_token', [
                    'input_token'  => $token,
                    'access_token' => $appId.'|'.$secret, // app access token
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Sender actions & message tags ──────────────────────────────────────

    /**
     * Shared Messenger Send-API POST (JSON body, Page token). Normalizes the
     * result and flags token errors via noteGraphError; surfaces whichever id
     * keys Graph returns (message_id → mid, attachment_id, recipient_id).
     *
     * @return array{ok:bool, mid?:string, attachment_id?:string, recipient_id?:string, error?:string}
     */
    private function messagesSend(array $body): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->post("{$this->base}/{$this->page->page_id}/messages", $body);
            if ($r->successful()) {
                $out = ['ok' => true];
                if ($r->json('message_id') !== null) {
                    $out['mid'] = (string) $r->json('message_id');
                }
                if ($r->json('attachment_id') !== null) {
                    $out['attachment_id'] = (string) $r->json('attachment_id');
                }
                if ($r->json('recipient_id') !== null) {
                    $out['recipient_id'] = (string) $r->json('recipient_id');
                }

                return $out;
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'send failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a sender action to a PSID: typing_on | typing_off | mark_seen. Carries
     * no message/messaging_type. Only valid inside the 24h window (and does not
     * extend it); the recipient must be signed in for the indicator to show.
     *
     * @return array{ok:bool, recipient_id?:string, error?:string}
     */
    public function sendSenderAction(string $psid, string $action): array
    {
        return $this->messagesSend([
            'recipient'     => ['id' => $psid],
            'sender_action' => $action,
        ]);
    }

    /**
     * React (emoji) or, when $emoji is null, un-react to a USER message the Page
     * received ($mid from the messages webhook). $emoji is the literal UTF-8
     * character. Only inside the 24h standard messaging window.
     *
     * @return array{ok:bool, error?:string}
     */
    public function reactToMessage(string $psid, string $mid, ?string $emoji = null): array
    {
        $payload = $emoji === null ? ['message_id' => $mid] : ['message_id' => $mid, 'reaction' => $emoji];

        return $this->messagesSend([
            'recipient'     => ['id' => $psid],
            'sender_action' => $emoji === null ? 'unreact' : 'react',
            'payload'       => $payload,
        ]);
    }

    /**
     * Send a message OUTSIDE the 24h window under a message tag. Only HUMAN_AGENT
     * (7-day human-reply window) remains usable — the *_UPDATE tags were
     * deprecated 2026-04-27 and now return error 100. $message is a Send-API
     * message object ({text:...} or {attachment:...}).
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendTaggedMessage(string $psid, array $message, string $tag): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'MESSAGE_TAG',
            'tag'            => $tag,
            'message'        => $message,
        ]);
    }

    /**
     * Send a media attachment (image|video|audio|file) from a public HTTPS URL
     * Meta fetches server-side. is_reusable=true also returns a reusable
     * attachment_id in the same response. Within the 24h window.
     *
     * @return array{ok:bool, mid?:string, attachment_id?:string, error?:string}
     */
    public function sendAttachment(string $psid, string $type, string $url, bool $isReusable = false): array
    {
        $payload = ['url' => $url];
        if ($isReusable) {
            $payload['is_reusable'] = true;
        }

        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => $type, 'payload' => $payload]],
        ]);
    }

    /**
     * Pre-upload a REUSABLE media asset (no recipient) so the same file can be
     * sent to many recipients without re-upload. Returns the attachment_id to
     * feed into sendAttachmentById / sendMediaTemplate.
     *
     * @return array{ok:bool, attachment_id?:string, error?:string}
     */
    public function uploadAttachment(string $type, string $url): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(30)
                ->post("{$this->base}/{$this->page->page_id}/message_attachments", [
                    'message' => ['attachment' => ['type' => $type, 'payload' => ['is_reusable' => true, 'url' => $url]]],
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'attachment_id' => (string) ($r->json('attachment_id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'attachment upload failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send media by a previously-uploaded attachment_id (from uploadAttachment or
     * a prior is_reusable send). $type must match the uploaded asset. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendAttachmentById(string $psid, string $type, string $attachmentId): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => $type, 'payload' => ['attachment_id' => $attachmentId]]],
        ]);
    }

    /**
     * Send text with up to 13 quick replies (text/user_phone_number/user_email).
     * A tap returns message.quick_reply.payload in the messages webhook. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendQuickReplies(string $psid, string $text, array $replies): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => [
                'text'          => mb_substr($text, 0, 2000),
                'quick_replies' => array_slice(array_values($replies), 0, 13),
            ],
        ]);
    }

    /**
     * Send a button template (≤3 buttons: web_url | postback | phone_number).
     * postback taps arrive on the messaging_postbacks webhook. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendButtonTemplate(string $psid, string $text, array $buttons): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'button',
                'text'          => mb_substr($text, 0, 640),
                'buttons'       => array_slice(array_values($buttons), 0, 3),
            ]]],
        ]);
    }

    /**
     * Send a generic (horizontal-scroll carousel) template with 1–10 elements,
     * each up to 3 buttons + optional default_action. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendGenericTemplate(string $psid, array $elements): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'generic',
                'elements'      => array_slice(array_values($elements), 0, 10),
            ]]],
        ]);
    }

    /**
     * Send a media template (exactly one image|video element) — pass an
     * attachment_id or a Facebook-hosted media URL, with ≤3 overlay buttons.
     * Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendMediaTemplate(string $psid, string $mediaType, string $attachmentIdOrUrl, array $buttons = []): array
    {
        $element = ['media_type' => $mediaType];
        if (preg_match('#^https?://#i', $attachmentIdOrUrl)) {
            $element['url'] = $attachmentIdOrUrl;
        } else {
            $element['attachment_id'] = $attachmentIdOrUrl;
        }
        if ($buttons !== []) {
            $element['buttons'] = array_slice(array_values($buttons), 0, 3);
        }

        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'media',
                'elements'      => [$element],
            ]]],
        ]);
    }

    /**
     * Send a One-Time Notification opt-in request button. On tap you receive a
     * messaging_optins webhook carrying a single-use one_time_notif_token.
     * Beta feature (advanced_permission) — verify availability before wiring.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendOtnRequest(string $psid, string $title, string $payload): array
    {
        return $this->messagesSend([
            'recipient' => ['id' => $psid],
            'message'   => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'one_time_notif_req',
                'title'         => mb_substr($title, 0, 65),
                'payload'       => $payload,
            ]]],
        ]);
    }

    /**
     * Send exactly ONE message outside the 24h window using a one_time_notif_token
     * captured from the opt-in webhook. The token is consumed after a single send.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendOtnMessage(string $token, array $message): array
    {
        return $this->messagesSend([
            'recipient' => ['one_time_notif_token' => $token],
            'message'   => $message,
        ]);
    }

    /**
     * Send a recurring-notifications (marketing) opt-in prompt. On opt-in you get
     * a messaging_optin webhook with a notification_messages_token + expiry.
     * Meta's recommended replacement for deprecated tags/OTN (advanced_permission).
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendRecurringNotificationRequest(string $psid, string $title, string $frequency, string $payload, ?string $imageUrl = null): array
    {
        $tpl = [
            'template_type'                   => 'notification_messages',
            'title'                           => mb_substr($title, 0, 65),
            'notification_messages_frequency' => $frequency,
            'payload'                         => $payload,
        ];
        if ($imageUrl !== null && $imageUrl !== '') {
            $tpl['image_url'] = $imageUrl;
        }

        return $this->messagesSend([
            'recipient' => ['id' => $psid],
            'message'   => ['attachment' => ['type' => 'template', 'payload' => $tpl]],
        ]);
    }

    /**
     * Send a marketing message via a notification_messages_token, outside the 24h
     * window. Limit 1/day/token at the opted-in cadence; token expires and must be
     * re-requested (advanced_permission).
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendRecurringNotification(string $token, array $message): array
    {
        return $this->messagesSend([
            'recipient' => ['notification_messages_token' => $token],
            'message'   => $message,
        ]);
    }

    /**
     * Send a receipt (order) template. $receipt supplies recipient_name,
     * order_number, currency, payment_method, summary{...} and optional
     * elements/address/adjustments. Must be within 24h or under an eligible tag.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendReceiptTemplate(string $psid, array $receipt): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'receipt'] + $receipt]],
        ]);
    }

    /**
     * Read a single message node by mid. CAVEAT: read-by-bare-mid is unreliable —
     * prefer getConversationMessages / webhook backfill. from/to come back as
     * {data:[{id,name,email?}]}. Returns decoded array, [] on failure.
     */
    public function getMessage(string $mid, ?string $fields = null): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$mid}", [
                    'fields' => $fields ?: 'id,created_time,from,to,message,attachments,sticker,shares,tags',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read full message content for a conversation (the reliable path a webhook
     * omitted, since read-by-mid is unreliable). Page-scoped; newest first.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}
     */
    public function getConversationMessages(string $conversationId, int $limit = 25, ?string $fields = null): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$conversationId}/messages", [
                    'fields' => $fields ?: 'id,created_time,from,to,message,attachments,sticker,shares',
                    'limit'  => $limit,
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Handover Protocol ──────────────────────────────────────────────────

    /**
     * Shared Handover-Protocol POST (JSON body, Page token). Control transfers are
     * EXEMPT from the 24h window and need no tag; flags token-190 via noteGraphError.
     *
     * @return array{ok:bool, error?:string}
     */
    private function handoverPost(string $edge, array $body): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$this->page->page_id}/{$edge}", $body);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'handover failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pass thread control to another app (default 263902037430900 = Page Inbox /
     * human agent). Caller must currently own the thread (Primary Receiver) or #2018105.
     * Exempt from the 24h window; requires the messaging_handovers webhook to receive events.
     *
     * @return array{ok:bool, error?:string}
     */
    public function passThreadControl(string $psid, int|string $targetAppId = 263902037430900, string $metadata = ''): array
    {
        $body = ['recipient' => ['id' => $psid], 'target_app_id' => $targetAppId];
        if ($metadata !== '') {
            $body['metadata'] = $metadata;
        }

        return $this->handoverPost('pass_thread_control', $body);
    }

    /**
     * Forcibly take thread control back FROM a secondary receiver. Caller MUST be
     * the Primary Receiver app (else #2018105). Control transfer only — no 24h/tag.
     *
     * @return array{ok:bool, error?:string}
     */
    public function takeThreadControl(string $psid, string $metadata = ''): array
    {
        $body = ['recipient' => ['id' => $psid]];
        if ($metadata !== '') {
            $body['metadata'] = $metadata;
        }

        return $this->handoverPost('take_thread_control', $body);
    }

    /**
     * Secondary receiver asks the Primary Receiver to hand it control. 'success'
     * only means the request webhook was delivered, NOT that control was granted.
     * Exempt from the 24h window.
     *
     * @return array{ok:bool, error?:string}
     */
    public function requestThreadControl(string $psid, string $metadata = ''): array
    {
        $body = ['recipient' => ['id' => $psid]];
        if ($metadata !== '') {
            $body['metadata'] = $metadata;
        }

        return $this->handoverPost('request_thread_control', $body);
    }

    /**
     * List the apps registered as secondary receivers on this Page. MUST be called
     * by the Primary Receiver (non-primary callers get empty/error). Returns
     * decoded data[], [] on failure.
     */
    public function getSecondaryReceivers(): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/secondary_receivers", ['fields' => 'id,name']);
            if ($r->successful()) {
                return (array) $r->json('data', []);
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Who currently owns a given user's thread (the guard before auto-replying —
     * skip the bot when a human/Page-Inbox app owns it). Compare app_id to your own
     * vs 263902037430900. Returns ['ok'=>true,'app_id'=>...], [] on failure.
     *
     * @return array{ok:bool, app_id?:string, error?:string}|array{}
     */
    public function getThreadOwner(string $psid): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/thread_owner", ['recipient' => $psid]);
            if ($r->successful()) {
                return ['ok' => true, 'app_id' => (string) data_get($r->json(), 'data.0.thread_owner.app_id', '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Messenger Profile ──────────────────────────────────────────────────

    /**
     * Shared messenger_profile setter (JSON body, Page token). Every public setter
     * builds its one-key payload and calls this. Rate limit 10 calls / 10 min / page.
     *
     * @return array{ok:bool, error?:string}
     */
    /**
     * @param string $version Graph version override — used only by the greeting
     *                        retry, where the configured version has dropped the
     *                        field but an older one still accepts it. Empty =
     *                        the client's configured version.
     */
    private function postMessengerProfile(array $payload, string $version = ''): array
    {
        $base = $version !== ''
            ? 'https://graph.facebook.com/' . ltrim($version, '/')
            : $this->base;

        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->post("{$base}/{$this->page->page_id}/messenger_profile", $payload);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'profile update failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Shared messenger_profile deleter — DELETE carries a JSON body {fields:[...]}.
     * Every public deleter calls this with its property name(s).
     *
     * @return array{ok:bool, error?:string}
     */
    /** @param string $version Graph version override — see postMessengerProfile(). */
    private function deleteMessengerProfile(array $fields, string $version = ''): array
    {
        $base = $version !== ''
            ? 'https://graph.facebook.com/' . ltrim($version, '/')
            : $this->base;

        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->delete("{$base}/{$this->page->page_id}/messenger_profile", ['fields' => array_values($fields)]);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'profile delete failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Read messenger_profile properties (fields REQUIRED by Meta). Returns the
     * decoded data[] list of {property:value} objects; unset properties are absent.
     * subject_to_new_eu_privacy_rules is read-only. Returns [] on failure.
     */
    public function getMessengerProfile(array $fields = ['get_started', 'greeting', 'ice_breakers', 'persistent_menu', 'whitelisted_domains', 'account_linking_url', 'commands', 'subject_to_new_eu_privacy_rules']): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/messenger_profile", ['fields' => implode(',', $fields)]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /** Set the Get Started button payload (echoed in messaging_postbacks on tap; new threads only). */
    public function setGetStarted(string $payload): array
    {
        return $this->postMessengerProfile(['get_started' => ['payload' => $payload]]);
    }

    /** Remove the Get Started button (usually delete alongside persistent_menu to avoid an orphan menu). */
    public function deleteGetStarted(): array
    {
        return $this->deleteMessengerProfile(['get_started']);
    }

    /**
     * Set the welcome-screen greeting — each item {locale,text}, ≥1 with
     * locale='default'; overwrites all.
     *
     * Meta DROPPED `greeting` from messenger_profile in recent Graph versions.
     * On those, a POST carrying only `greeting` contains no field the endpoint
     * recognises, so it answers:
     *
     *   (#100) Requires one of the params: get_started,persistent_menu,
     *   whitelisted_domains,account_linking_url,home_url,ice_breakers,
     *   platform,description,commands
     *
     * — note `greeting` is absent from its own list of accepted params. That is
     * the tell, and it makes the call fail 100% of the time rather than
     * intermittently. Older Graph versions still accept the field, so retry
     * once against the fallback version before giving up; only if that also
     * fails do we report it as unsupported.
     */
    public function setGreeting(array $greetings): array
    {
        $res = $this->postMessengerProfile(['greeting' => array_values($greetings)]);
        if (($res['ok'] ?? false) || ! $this->greetingUnsupported($res['error'] ?? '')) {
            return $res;
        }

        $fallback = self::greetingFallbackVersion();
        if ($fallback !== '' && $fallback !== self::version()) {
            $retry = $this->postMessengerProfile(
                ['greeting' => array_values($greetings)],
                $fallback
            );
            if ($retry['ok'] ?? false) {
                return $retry;
            }
            $res = $retry;
        }

        return [
            'ok'    => false,
            'error' => __('Facebook no longer accepts the Messenger welcome greeting on this API version. Use Ice breakers instead — they show tappable questions on the same welcome screen.'),
            'unsupported' => true,
            'graph_error' => $res['error'] ?? null,
        ];
    }

    /**
     * Graph version to retry the greeting on when the configured one rejects
     * it. Admin-overridable; empty disables the retry entirely.
     */
    public static function greetingFallbackVersion(): string
    {
        return (string) SystemSetting::get('fb_greeting_graph_version', 'v19.0');
    }

    /**
     * True when a Graph error is "this endpoint does not know the `greeting`
     * field" rather than a real failure. Matched on the accepted-params list
     * Meta echoes back: if it enumerates what it takes and `greeting` is not
     * among them, the field is gone on this version. Deliberately narrow — a
     * bad token or a permission error must NOT be swallowed as "unsupported".
     */
    private function greetingUnsupported(string $error): bool
    {
        if ($error === '' || ! str_contains($error, 'Requires one of the params')) {
            return false;
        }

        return ! preg_match('/\bgreeting\b/i', $error);
    }

    /**
     * Delete the greeting across ALL locales (per-locale delete unsupported).
     * Same version problem as setGreeting() — a version that dropped the field
     * cannot delete it either, so retry on the fallback. Removing something
     * Facebook no longer stores is a no-op, so treat a persistent "unsupported"
     * as success rather than blocking the operator on an error they cannot act on.
     */
    public function deleteGreeting(): array
    {
        $res = $this->deleteMessengerProfile(['greeting']);
        if (($res['ok'] ?? false) || ! $this->greetingUnsupported($res['error'] ?? '')) {
            return $res;
        }

        $fallback = self::greetingFallbackVersion();
        if ($fallback !== '' && $fallback !== self::version()) {
            $retry = $this->deleteMessengerProfile(['greeting'], $fallback);
            if ($retry['ok'] ?? false) {
                return $retry;
            }
        }

        return ['ok' => true];
    }

    /** Set the persistent menu — each menu {locale,call_to_actions,...}, ≥1 with locale='default'; replaces the set. */
    public function setPersistentMenu(array $menus): array
    {
        return $this->postMessengerProfile(['persistent_menu' => array_values($menus)]);
    }

    /** Remove all locale persistent menus. */
    public function deletePersistentMenu(): array
    {
        return $this->deleteMessengerProfile(['persistent_menu']);
    }

    /** Set ice breakers (localized {call_to_actions:[{question,payload}],locale} shape; ≤4/locale; tap → messaging_postbacks). */
    public function setIceBreakers(array $iceBreakers): array
    {
        return $this->postMessengerProfile(['ice_breakers' => array_values($iceBreakers)]);
    }

    /** Delete ALL ice breakers across all locales (per-locale delete unsupported). */
    public function deleteIceBreakers(): array
    {
        return $this->deleteMessengerProfile(['ice_breakers']);
    }

    /** Set the domain allowlist for Messenger Extensions / webviews / chat plugin — ≤50, all https; replaces the list. */
    public function setWhitelistedDomains(array $urls): array
    {
        return $this->postMessengerProfile(['whitelisted_domains' => array_values($urls)]);
    }

    /** Clear the domain allowlist. */
    public function deleteWhitelistedDomains(): array
    {
        return $this->deleteMessengerProfile(['whitelisted_domains']);
    }

    /** Set the account-linking callback URL (https) used by the Log In / Log Out button flow. */
    public function setAccountLinkingUrl(string $url): array
    {
        return $this->postMessengerProfile(['account_linking_url' => $url]);
    }

    /** Remove the account-linking URL. */
    public function deleteAccountLinkingUrl(): array
    {
        return $this->deleteMessengerProfile(['account_linking_url']);
    }

    /** Set discoverable bot commands — nested {locale, commands:[{name,description}]} groups, ≥1 locale='default'. */
    public function setCommands(array $commands): array
    {
        return $this->postMessengerProfile(['commands' => array_values($commands)]);
    }

    /** Remove all commands. */
    public function deleteCommands(): array
    {
        return $this->deleteMessengerProfile(['commands']);
    }

    // ── Stories ────────────────────────────────────────────────────────────

    /**
     * Publish a Page photo Story (auto-expires after 24h). Pre-uploads the image
     * UNPUBLISHED then posts to /photo_stories. Recommended 1080x1920 (9:16).
     *
     * @return array{ok:bool, id?:string, error?:string}
     */
    public function publishPhotoStory(string $imageUrl): array
    {
        $photo = $this->uploadPhotoUnpublished($imageUrl);
        if (empty($photo['ok']) || empty($photo['id'])) {
            return ['ok' => false, 'error' => $this->lastError ?: 'photo upload failed'];
        }

        return $this->postGraph("{$this->page->page_id}/photo_stories", ['photo_id' => (string) $photo['id']], ['post_id', 'id']);
    }

    /**
     * Publish a Page video Story via the 3-phase video_stories API (hosted file_url
     * transfer). Story expires 24h; ~≤60s, 9:16. Polls upload status before FINISH.
     *
     * @return array{ok:bool, id?:string, post_id?:string, error?:string}
     */
    public function publishVideoStory(string $fileUrl, string $description = ''): array
    {
        $pageId = $this->page->page_id;
        $token = $this->token();
        try {
            // 1 · START — reserve a video_id + upload_url.
            $start = Http::withToken($token)->acceptJson()->timeout(30)->asForm()
                ->post("{$this->base}/{$pageId}/video_stories", ['upload_phase' => 'start']);
            if (! $start->successful() || ! $start->json('video_id')) {
                $this->lastError = (string) ($start->json('error.message') ?? 'video story start failed');

                return ['ok' => false, 'error' => $this->lastError];
            }
            $videoId = (string) $start->json('video_id');
            $uploadUrl = (string) $start->json('upload_url');
            if ($uploadUrl === '') {
                $this->lastError = 'video story start returned no upload_url';

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2 · UPLOAD — hosted transfer: tell Meta where to pull the file from.
            $up = Http::withHeaders([
                'Authorization' => 'OAuth '.$token,
                'file_url'      => $fileUrl,
            ])->timeout(180)->post($uploadUrl);
            if (! $up->successful()) {
                $this->lastError = 'video story upload failed: '.mb_substr($up->body(), 0, 300);

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2b · WAIT — hosted file downloads async; poll status before finishing.
            for ($i = 0; $i < 20; $i++) {
                $st = Http::withToken($token)->acceptJson()->timeout(15)
                    ->get("{$this->base}/{$videoId}", ['fields' => 'status']);
                $phase = (string) data_get($st->json(), 'status.uploading_phase.status', '');
                if ($phase === 'complete') {
                    break;
                }
                if ($phase === 'error') {
                    $this->lastError = 'video story upload processing failed';

                    return ['ok' => false, 'error' => $this->lastError];
                }
                usleep(2_000_000); // 2s; ~40s max
            }

            // 3 · FINISH — publish the story.
            $finish = ['upload_phase' => 'finish', 'video_id' => $videoId];
            if ($description !== '') {
                $finish['description'] = mb_substr($description, 0, 2200);
            }
            $done = Http::withToken($token)->asForm()->acceptJson()->timeout(30)
                ->post("{$this->base}/{$pageId}/video_stories", $finish);
            if ($done->successful()) {
                return ['ok' => true, 'id' => $videoId, 'post_id' => (string) ($done->json('post_id') ?? ''), 'error' => null];
            }
            $this->lastError = (string) ($done->json('error.message') ?? 'video story finish failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Page management & reads ────────────────────────────────────────────

    /**
     * Rich feed-post publisher (superset of publish()). $spec may carry message,
     * link, place, tags (only with place), message_tags/call_to_action/targeting/
     * feed_targeting (objects → JSON), attached_media (media_fbids), published +
     * scheduled_publish_time. Publishing edge — no 24h/tag.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createFeedPost(array $spec): array
    {
        $params = [];
        foreach (['message', 'link', 'place', 'published', 'scheduled_publish_time', 'backdated_time', 'backdated_time_granularity', 'no_story', 'multi_share_optimized', 'tags'] as $k) {
            if (array_key_exists($k, $spec) && $spec[$k] !== null && $spec[$k] !== '') {
                $params[$k] = is_bool($spec[$k]) ? ($spec[$k] ? 'true' : 'false') : $spec[$k];
            }
        }
        foreach (['message_tags', 'call_to_action', 'targeting', 'feed_targeting'] as $k) {
            if (! empty($spec[$k])) {
                $params[$k] = is_string($spec[$k]) ? $spec[$k] : json_encode($spec[$k]);
            }
        }
        foreach (array_values((array) ($spec['attached_media'] ?? [])) as $i => $fbid) {
            $params["attached_media[{$i}]"] = json_encode(['media_fbid' => (string) $fbid]);
        }

        return $this->postGraph("{$this->page->page_id}/feed", $params, ['id']);
    }

    /**
     * Publish a link carousel: $childAttachments = 2–10 cards, each {link,name,
     * description,image_hash|picture,call_to_action}. $options may set message,
     * multi_share_optimized, multi_share_end_card, published, scheduled_publish_time.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createLinkCarousel(array $childAttachments, string $message = '', array $options = []): array
    {
        $params = ['child_attachments' => json_encode(array_values($childAttachments))];
        if ($message !== '') {
            $params['message'] = $message;
        }
        foreach (['multi_share_optimized', 'multi_share_end_card', 'published'] as $k) {
            if (array_key_exists($k, $options)) {
                $params[$k] = is_bool($options[$k]) ? ($options[$k] ? 'true' : 'false') : $options[$k];
            }
        }
        if (! empty($options['scheduled_publish_time'])) {
            $params['scheduled_publish_time'] = (int) $options['scheduled_publish_time'];
        }

        return $this->postGraph("{$this->page->page_id}/feed", $params, ['id']);
    }

    /**
     * Upload an UNPUBLISHED photo and return its media_fbid (id) for /feed reuse
     * (multi-photo, carousel end-cards, scheduled photo posts). temporary='true'
     * requires published='false'. $options: caption,temporary,no_story,alt_text_custom,...
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function uploadPhotoUnpublished(string $url, array $options = []): array
    {
        $params = ['url' => $url, 'published' => 'false'];
        foreach (['caption', 'temporary', 'no_story', 'alt_text_custom', 'backdated_time'] as $k) {
            if (array_key_exists($k, $options) && $options[$k] !== null && $options[$k] !== '') {
                $params[$k] = is_bool($options[$k]) ? ($options[$k] ? 'true' : 'false') : $options[$k];
            }
        }
        foreach (['targeting', 'place'] as $k) {
            if (! empty($options[$k])) {
                $params[$k] = is_string($options[$k]) ? $options[$k] : json_encode($options[$k]);
            }
        }

        return $this->postGraph("{$this->page->page_id}/photos", $params, ['id']);
    }

    /**
     * Edit a post the app created (text/targeting/schedule only — media cannot be
     * swapped). Reschedule an unpublished post via scheduled_publish_time. The id
     * is preserved. $fields: message,is_published,scheduled_publish_time,targeting,...
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function updatePost(string $postId, array $fields): array
    {
        $params = [];
        foreach (['message', 'is_published', 'scheduled_publish_time'] as $k) {
            if (array_key_exists($k, $fields)) {
                $params[$k] = is_bool($fields[$k]) ? ($fields[$k] ? 'true' : 'false') : $fields[$k];
            }
        }
        foreach (['targeting', 'feed_targeting', 'tags', 'place'] as $k) {
            if (! empty($fields[$k])) {
                $params[$k] = is_string($fields[$k]) ? $fields[$k] : json_encode($fields[$k]);
            }
        }

        return $this->postGraph($postId, $params, ['id', 'success']);
    }

    /**
     * List this Page's unpublished + scheduled posts. To fire one immediately reuse
     * publishNow(). $params: fields,limit,after,before.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getScheduledPosts(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,scheduled_publish_time,created_time,is_published,permalink_url,full_picture'];
            foreach (['limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/scheduled_posts", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * List comments on a post/object (richer than getComment). $params: fields,
     * order,filter,summary,limit,after,before. Comments by other users need
     * pages_read_user_content. Paginate via paging.next.
     *
     * @return array{ok:bool, data:array, paging?:mixed, summary?:mixed, error?:string}|array{}
     */
    public function getPostComments(string $objectId, array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,from{id,name,picture},created_time,parent{id},attachment,like_count,comment_count,message_tags'];
            foreach (['order', 'filter', 'summary', 'limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$objectId}/comments", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging'), 'summary' => $r->json('summary')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * List reactions on a post/object (read-only — writing a specific reaction is
     * not supported by Graph). Pass type + summary=total_count per type for counts.
     * Note SORRY (not SAD). $params: type,summary,limit,after,before.
     *
     * @return array{ok:bool, data:array, paging?:mixed, summary?:mixed, error?:string}|array{}
     */
    public function getPostReactions(string $objectId, array $params = []): array
    {
        try {
            $query = [];
            foreach (['type', 'summary', 'limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$objectId}/reactions", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging'), 'summary' => $r->json('summary')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Chunked resumable upload of a LOCAL video file (start → transfer chunks →
     * finish). $meta: title,description,scheduled_publish_time. For a public URL,
     * postVideo() is simpler. Add subtitles afterwards via uploadVideoCaptions().
     *
     * @return array{ok:bool, id?:string, error?:string}
     */
    public function uploadVideoResumable(string $filePath, array $meta = []): array
    {
        $pageId = $this->page->page_id;
        $token = $this->token();
        try {
            if (! is_file($filePath) || ! is_readable($filePath)) {
                $this->lastError = 'video file not readable: '.$filePath;

                return ['ok' => false, 'error' => $this->lastError];
            }
            $fileSize = (int) filesize($filePath);

            // 1 · START — declare file size, open an upload session.
            $start = Http::withToken($token)->acceptJson()->timeout(30)->asForm()
                ->post("{$this->base}/{$pageId}/videos", ['upload_phase' => 'start', 'file_size' => $fileSize]);
            if (! $start->successful() || ! $start->json('upload_session_id')) {
                $this->lastError = (string) ($start->json('error.message') ?? 'resumable start failed');
                $this->noteGraphError($start->json('error'));

                return ['ok' => false, 'error' => $this->lastError];
            }
            $sessionId = (string) $start->json('upload_session_id');
            $videoId = (string) $start->json('video_id');
            $startOffset = (int) $start->json('start_offset', 0);
            $endOffset = (int) $start->json('end_offset', 0);

            // 2 · TRANSFER — stream each chunk until start_offset == end_offset.
            $fh = fopen($filePath, 'rb');
            if ($fh === false) {
                $this->lastError = 'cannot open video file';

                return ['ok' => false, 'error' => $this->lastError];
            }
            try {
                for ($guard = 0; $guard < 100000 && $startOffset < $endOffset; $guard++) {
                    fseek($fh, $startOffset);
                    $chunk = (string) fread($fh, max(1, $endOffset - $startOffset));
                    $t = Http::withToken($token)->acceptJson()->timeout(180)
                        ->attach('video_file_chunk', $chunk, 'chunk')
                        ->post("{$this->base}/{$pageId}/videos", [
                            'upload_phase'      => 'transfer',
                            'upload_session_id' => $sessionId,
                            'start_offset'      => $startOffset,
                        ]);
                    if (! $t->successful()) {
                        $this->lastError = (string) ($t->json('error.message') ?? 'chunk transfer failed');
                        $this->noteGraphError($t->json('error'));

                        return ['ok' => false, 'error' => $this->lastError];
                    }
                    $startOffset = (int) $t->json('start_offset', $endOffset);
                    $endOffset = (int) $t->json('end_offset', $endOffset);
                }
            } finally {
                fclose($fh);
            }

            // 3 · FINISH — commit + metadata (title/description/schedule).
            $finish = ['upload_phase' => 'finish', 'upload_session_id' => $sessionId];
            if (! empty($meta['title'])) {
                $finish['title'] = $meta['title'];
            }
            if (! empty($meta['description'])) {
                $finish['description'] = $meta['description'];
            }
            if (! empty($meta['scheduled_publish_time'])) {
                $finish['published'] = 'false';
                $finish['scheduled_publish_time'] = (int) $meta['scheduled_publish_time'];
            }
            $done = Http::withToken($token)->acceptJson()->timeout(60)->asForm()
                ->post("{$this->base}/{$pageId}/videos", $finish);
            if ($done->successful()) {
                return ['ok' => true, 'id' => $videoId, 'error' => null];
            }
            $this->lastError = (string) ($done->json('error.message') ?? 'resumable finish failed');
            $this->noteGraphError($done->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Attach subtitles (a local SRT file) to an existing Page video — one call per
     * locale. Pairs with postVideo() / uploadVideoResumable().
     *
     * @return array{ok:bool, error?:string}
     */
    public function uploadVideoCaptions(string $videoId, string $srtFilePath, string $defaultLocale = 'en_US'): array
    {
        try {
            if (! is_file($srtFilePath) || ! is_readable($srtFilePath)) {
                $this->lastError = 'captions file not readable: '.$srtFilePath;

                return ['ok' => false, 'error' => $this->lastError];
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(60)
                ->attach('captions_file', (string) file_get_contents($srtFilePath), $defaultLocale.'.srt')
                ->post("{$this->base}/{$videoId}/captions", ['default_locale' => $defaultLocale]);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'caption upload failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Page likes an object (post/comment) AS ITSELF — plain LIKE only (reaction type cannot be chosen). */
    public function likeObject(string $objectId): array
    {
        return $this->postGraph("{$objectId}/likes", [], ['success', 'id']);
    }

    /** Remove the Page's OWN like from an object (cannot remove others'). */
    public function unlikeObject(string $objectId): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)->delete("{$this->base}/{$objectId}/likes");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List the Page's albums (type ∈ profile|wall|mobile|normal). $params: fields,
     * limit,after,before. Paginate via paging.next.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getAlbums(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,name,count,type,cover_photo,created_time,link,privacy'];
            foreach (['limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/albums", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Create an album, then add images via addPhotoToAlbum(). CAVEAT: the v23
     * page/albums reference is ambiguous about create — verify against the live
     * Page. $privacy = {value:'EVERYONE'|...}.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createAlbum(string $name, string $message = '', ?array $privacy = null): array
    {
        $params = ['name' => $name];
        if ($message !== '') {
            $params['message'] = $message;
        }
        if ($privacy !== null) {
            $params['privacy'] = json_encode($privacy);
        }

        return $this->postGraph("{$this->page->page_id}/albums", $params, ['id']);
    }

    /**
     * Add a photo (public HTTPS url) to a specific album. $options: caption,
     * no_story,published,backdated_time,targeting.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function addPhotoToAlbum(string $albumId, string $url, array $options = []): array
    {
        $params = ['url' => $url];
        foreach (['caption', 'no_story', 'published', 'backdated_time'] as $k) {
            if (array_key_exists($k, $options) && $options[$k] !== null && $options[$k] !== '') {
                $params[$k] = is_bool($options[$k]) ? ($options[$k] ? 'true' : 'false') : $options[$k];
            }
        }
        if (! empty($options['targeting'])) {
            $params['targeting'] = is_string($options['targeting']) ? $options['targeting'] : json_encode($options['targeting']);
        }

        return $this->postGraph("{$albumId}/photos", $params, ['id', 'post_id']);
    }

    /**
     * Rich Page profile/business read (about, phone, emails, website, hours,
     * location, verification_status, fan/followers counts, …). Business fields need
     * a Page role (or Page Public Content Access). Returns decoded array, [] on failure.
     */
    public function getPageDetails(?string $fields = null): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}", [
                    'fields' => $fields ?: 'about,description,general_info,phone,emails,website,hours,location,single_line_address,is_published,verification_status,link,engagement,checkins,were_here_count,category,name,username,fan_count,followers_count,price_range,category_list',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Write Page metadata fields (about, description, phone, website, emails, hours,
     * location, price_range, …). Array sub-objects (hours/location/emails) are
     * JSON-encoded as Meta requires.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function updatePageSettings(array $fields): array
    {
        $params = [];
        foreach ($fields as $k => $v) {
            if ($v === null) {
                continue;
            }
            $params[$k] = is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        }

        return $this->postGraph($this->page->page_id, $params, ['success', 'id']);
    }

    /**
     * List Messenger conversations (unified threads). CURSOR paging only (no
     * since/until). $params: platform,folder,user_id,fields,limit,after. snippet =
     * last-message preview; unread_count for badges.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function listConversations(array $params = []): array
    {
        try {
            $query = [
                'platform' => $params['platform'] ?? 'MESSENGER',
                'fields'   => $params['fields'] ?? 'participants,senders,unread_count,message_count,updated_time,snippet,can_reply,link',
            ];
            foreach (['folder', 'user_id', 'limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/conversations", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read the Page's reviews/recommendations. Legacy 1–5 star `rating` is
     * deprecated — modern reviews are recommendation_type=positive|negative;
     * review_text may be null. Returns decoded data[], [] on failure.
     */
    public function getRatings(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'reviewer{id,name,picture},rating,review_text,recommendation_type,created_time,open_graph_story,has_rating,has_review'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/ratings", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read third-party posts that TAG this Page (distinct from published_posts).
     * tagged_time is the tag time vs the post's created_time. Returns decoded
     * data[], [] on failure. $params: fields,limit,after,since,until.
     */
    public function getTaggedPosts(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,story,from,created_time,permalink_url,full_picture,attachments,tagged_time,likes.summary(true),comments.summary(true)'];
            foreach (['limit', 'after', 'since', 'until'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/tagged", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Paginated/richer sibling of recentPosts — Page-authored posts only (excludes
     * visitor posts), with engagement summaries + cursor/time paging.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getPublishedPosts(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,story,created_time,permalink_url,full_picture,is_published,attachments,shares,likes.summary(true),comments.summary(true),reactions.summary(true)'];
            foreach (['limit', 'after', 'since', 'until'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/published_posts", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read the Page feed = Page posts + visitor posts (superset of published_posts;
     * visitor posts need pages_read_user_content). Cursor + time (since/until) paging.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getFeed(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,story,from,created_time,permalink_url,full_picture,attachments,shares,reactions.summary(true),comments.summary(true),likes.summary(true)'];
            foreach (['limit', 'after', 'before', 'since', 'until'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/feed", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Enable/disable Meta's built-in Wit.ai NLP on Messenger. $options: model,
     * custom_token (your Wit.ai server token), verbose, n_best. Parsed entities then
     * arrive in the `nlp` object on messaging webhook events.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function setNlpConfig(bool $enabled, array $options = []): array
    {
        $params = ['nlp_enabled' => $enabled ? 'true' : 'false'];
        foreach (['model', 'custom_token', 'n_best'] as $k) {
            if (! empty($options[$k])) {
                $params[$k] = $options[$k];
            }
        }
        if (array_key_exists('verbose', $options)) {
            $params['verbose'] = $options['verbose'] ? 'true' : 'false';
        }

        return $this->postGraph("{$this->page->page_id}/nlp_configs", $params, ['success']);
    }

    /**
     * Block Messenger users from interacting with the Page (by PSID). Graph returns
     * a per-id map; postGraph normalizes to ['ok'=>true] on 2xx.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function blockUser(array $psids): array
    {
        return $this->postGraph("{$this->page->page_id}/blocked", ['psid' => json_encode(array_values($psids))], ['success']);
    }

    /** Unblock previously-blocked Messenger users (by PSID). Mirror of blockUser. */
    public function unblockUser(array $psids): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->delete("{$this->base}/{$this->page->page_id}/blocked", ['psid' => json_encode(array_values($psids))]);

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** List who is currently blocked on the Page. $params: fields,limit,after. Returns decoded data[], [] on failure. */
    public function getBlockedUsers(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,name'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/blocked", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read the Page's CTA button(s). READ-ONLY in v23 — programmatic create/update/
     * delete were removed by Meta. Returns decoded data[] (usually 0 or 1), [] on failure.
     */
    public function getCallToActions(): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/call_to_actions", [
                    'fields' => 'id,type,status,web_url,android_url,iphone_url,intl_number_with_plus,created_time',
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Create a Messenger custom label (CRM tag). NOTE the param is page_label_name.
     * Store the returned id for associate/remove/delete.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createLabel(string $name): array
    {
        return $this->postGraph("{$this->page->page_id}/custom_labels", ['page_label_name' => $name], ['id']);
    }

    /**
     * Apply an existing custom label to a Messenger PSID.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function associateLabel(string $labelId, string $psid): array
    {
        return $this->postGraph("{$labelId}/label", ['user' => $psid], ['success']);
    }

    /** Remove a label from a PSID (does not delete the label itself). */
    public function removeLabel(string $labelId, string $psid): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->delete("{$this->base}/{$labelId}/label", ['user' => $psid]);

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Permanently delete a custom label node (and its associations). */
    public function deleteLabel(string $labelId): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)->delete("{$this->base}/{$labelId}");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** List all custom labels on the Page. $params: fields,limit,after. Returns decoded data[], [] on failure. */
    public function getLabels(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'page_label_name'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/custom_labels", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /** Reverse lookup — which labels are attached to one Messenger PSID. Returns decoded data[], [] on failure. */
    public function getLabelsForPsid(string $psid): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$psid}/custom_labels", ['fields' => 'page_label_name']);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Fetch the Page profile picture as JSON (redirect=false → {data:{url,width,
     * height,is_silhouette,cache_key}}). is_silhouette=true means no custom avatar.
     * $type = small|normal|large|square. Returns decoded array, [] on failure.
     */
    public function getPagePicture(string $type = 'large', array $params = []): array
    {
        try {
            $query = ['redirect' => 'false', 'type' => $type];
            foreach (['width', 'height'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(12)
                ->get("{$this->base}/{$this->page->page_id}/picture", $query);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Batch up to 50 Graph calls in one HTTP round-trip (the Page token applies to
     * all). Each element's body is a JSON string — decode individually — and a
     * sub-request can fail (its own `code`) while the outer HTTP is 200.
     *
     * @return array{ok:bool, data?:array, error?:string}
     */
    public function batchGraph(array $requests, bool $includeHeaders = false): array
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(60)->asForm()
                ->post($this->base, [
                    'batch'           => json_encode(array_values($requests)),
                    'include_headers' => $includeHeaders ? 'true' : 'false',
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json()];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'batch failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Insights (extended) ────────────────────────────────────────────────

    /**
     * Richer Page-insights read (adds since/until, date_preset, metric_type). Some
     * classic metrics were deprecated — validate names against the v23 catalog.
     * $params: period,since,until,date_preset,metric_type. Returns decoded data[].
     */
    public function getInsightsMetrics(array $metrics, array $params = []): array
    {
        try {
            $query = ['metric' => implode(',', $metrics), 'period' => $params['period'] ?? 'day'];
            foreach (['since', 'until', 'date_preset', 'metric_type'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/insights", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Per-video insights (total_video_views, complete_views, view_time, …). $videoId
     * must be a video owned by this Page; some metrics are lifetime-only. Returns
     * decoded data[], [] on failure.
     */
    public function getVideoInsights(string $videoId, array $metrics, ?string $period = null): array
    {
        try {
            $query = ['metric' => implode(',', $metrics)];
            if ($period) {
                $query['period'] = $period;
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$videoId}/video_insights", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Richer per-post insights (adds period, date_preset, metric_type=total_value
     * and *_by_type breakdowns). Same /insights edge as postInsights. Returns
     * decoded data[], [] on failure.
     */
    public function getPostInsightsFull(string $postId, array $metrics, array $params = []): array
    {
        try {
            $query = ['metric' => implode(',', $metrics)];
            foreach (['period', 'date_preset', 'metric_type'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$postId}/insights", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Commerce / lead-gen ────────────────────────────────────────────────

    /**
     * List the Page's Instant-Form lead forms. questions[] describes the fields —
     * use to map field_data on leads. Needs leads_retrieval + pages_manage_ads.
     * Returns decoded data[], [] on failure.
     */
    public function getLeadForms(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,name,status,leads_count,locale,created_time,questions,follow_up_action_url,expired_leads_count,organic_leads_count,page'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/leadgen_forms", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Retrieve submitted leads for one form (newest first) — the CRM ingest path.
     * Map field_data[].name to the form's questions[].key. Use $params['filtering']
     * for incremental since-last-sync pulls (Meta auto-deletes leads ~90 days).
     * Returns decoded data[], [] on failure.
     */
    public function getFormLeads(string $formId, array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,is_organic,platform,partner_name'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            if (! empty($params['filtering'])) {
                $query['filtering'] = is_string($params['filtering']) ? $params['filtering'] : json_encode($params['filtering']);
            }
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$formId}/leads", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }
}
