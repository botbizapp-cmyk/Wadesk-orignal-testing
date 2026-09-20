<?php

namespace App\Http\Controllers;

use App\Models\FacebookPage;
use App\Models\SystemSetting;
use App\Services\Facebook\FacebookPageClient;
use App\Services\PlanLimitGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Facebook account connect. The user logs in with their Facebook ACCOUNT once
 * and every Page that account manages is stored (each with its own Page access
 * token). Three paths, all reusing the WhatsApp Meta app credentials:
 *   GET  /facebook/connect          → redirect to Meta's OAuth dialog
 *   GET  /facebook/callback         → exchange code, extend, store all Pages
 *   POST /facebook/connect/embedded → embedded-signup popup posts the code (JSON)
 *   POST /facebook/connect/manual   → paste a Page access token directly
 *
 * Meta does not allow posting to a personal profile, so the Pages behind the
 * account are the addressable channels (posts, comments, Messenger, insights).
 */
class FacebookConnectController extends Controller
{
    /** Page permissions requested at OAuth. */
    private const SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content,pages_manage_metadata,pages_messaging,read_insights,business_management';

    private function redirectUri(): string
    {
        return url('/facebook/callback');
    }

    /**
     * Enforce the plan feature in code. The route middleware only HARD-blocks
     * unsafe methods; start()/callback() are GET (and callback() WRITES), so a
     * workspace without access_facebook could otherwise connect via the redirect
     * flow. Platform admins bypass inside PlanLimitGuard.
     */
    private function planOk(): bool
    {
        $ws = Auth::user()?->currentWorkspace;

        return $ws ? (bool) PlanLimitGuard::hasFeature($ws, 'access_facebook') : false;
    }

    /** Kick off the Facebook Login OAuth dialog. */
    public function start(Request $request)
    {
        if (! $this->planOk()) {
            return redirect('/devices')->withErrors(['facebook' => __('Your plan does not include Facebook. Upgrade to connect a Page.')]);
        }
        $appId = FacebookPageClient::appId();
        if ($appId === '') {
            return back()->withErrors(['facebook' => setup_hint(__('Facebook is not configured — enable it under Admin → Channel Settings.'), __('Facebook connection is currently unavailable. Please contact support for assistance.'))]);
        }
        $v = FacebookPageClient::version();
        $configId = (string) SystemSetting::get('fb_config_id', '');
        $params = [
            'client_id'     => $appId,
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            // State is validated on return (callback) to stop login-CSRF: an
            // attacker cannot silently inject their Page into a victim's
            // workspace because the round-tripped state must match the session.
            'state'         => csrf_token(),
        ];
        // A Login-for-Business configuration id pins the requested assets +
        // permissions. When set it drives the dialog; scope stays as a fallback.
        // Meta ignores `scope` when config_id is present and wants
        // override_default_response_type=true for the code grant.
        if ($configId !== '') {
            $params['config_id'] = $configId;
            $params['override_default_response_type'] = 'true';
        }

        return redirect('https://www.facebook.com/'.$v.'/dialog/oauth?'.http_build_query($params));
    }

    /** OAuth callback → exchange, upgrade to long-lived, store every Page. */
    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            return redirect('/devices')->withErrors(['facebook' => (string) $request->string('error_description')]);
        }
        // CSRF: the state we put on the dialog must round-trip back unchanged.
        // Without this a victim lured to /facebook/callback?code=… could have an
        // attacker's Page silently connected into their workspace (login-CSRF).
        if (! hash_equals(csrf_token(), (string) $request->query('state'))) {
            return redirect('/devices')->withErrors(['facebook' => __('Facebook connect could not be verified. Please start the connection again.')]);
        }
        // Enforce the plan feature: this is a write-on-GET, which the route
        // middleware does not hard-block (it only blocks unsafe methods).
        if (! $this->planOk()) {
            return redirect('/devices')->withErrors(['facebook' => __('Your plan does not include Facebook.')]);
        }
        $code = (string) $request->string('code');
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        if ($code === '' || ! $wsId) {
            return redirect('/devices')->withErrors(['facebook' => __('Missing code or workspace.')]);
        }

        $tok = FacebookPageClient::exchangeCode($code, $this->redirectUri());
        if (empty($tok['ok'])) {
            Log::warning('[FB-CONNECT] token exchange failed', ['err' => $tok['error'] ?? '']);

            return redirect('/devices')->withErrors(['facebook' => __('Token exchange failed: ').($tok['error'] ?? 'unknown')]);
        }

        $userToken = $this->longLived((string) $tok['access_token']);
        $res = $this->storePages($userToken, $wsId, 'oauth');
        if (! $res['ok']) {
            return redirect('/devices')->withErrors(['facebook' => $res['message']]);
        }

        return redirect('/devices')->with('status', $res['message']);
    }

    /**
     * Embedded-signup (Facebook JS SDK popup). The browser posts the server-side
     * `code`; we exchange WITHOUT a redirect_uri (the SDK already handled it),
     * upgrade to a long-lived token and store every Page. Returns JSON.
     */
    public function connectEmbedded(Request $request): JsonResponse
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $code = trim((string) $request->input('code', ''));
        if ($code === '' || ! $wsId) {
            return response()->json(['ok' => false, 'message' => __('Missing code or workspace.')], 422);
        }
        if (FacebookPageClient::appId() === '' || FacebookPageClient::appSecret() === '') {
            return response()->json(['ok' => false, 'message' => __('Facebook Meta app credentials are not configured.')], 422);
        }

        $tok = FacebookPageClient::exchangeCode($code, '');
        if (empty($tok['ok'])) {
            return response()->json(['ok' => false, 'message' => __('Token exchange failed: ').($tok['error'] ?? 'unknown')], 422);
        }

        $userToken = $this->longLived((string) $tok['access_token']);
        $res = $this->storePages($userToken, $wsId, 'embedded');
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'message' => $res['message']], 422);
        }

        return response()->json(['ok' => true, 'redirect' => '/devices', 'message' => $res['message'], 'pages' => $res['count']]);
    }

    /**
     * Manual connect — the merchant pastes a long-lived PAGE access token (from
     * Meta's Graph API Explorer / Business settings) for a single Page. We
     * resolve the Page from the token and store it. The alternative to the
     * popup for operators who manage tokens directly.
     */
    public function connectManual(Request $request)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $data = $request->validate([
            'page_access_token' => 'required|string|min:20|max:1000',
        ]);
        if (! $wsId) {
            return back()->withErrors(['facebook' => __('No active workspace.')]);
        }

        $pageToken = trim((string) $data['page_access_token']);
        $p = FacebookPageClient::pageFromToken($pageToken);
        if (empty($p['id'])) {
            return back()->withErrors(['facebook' => __('That token did not resolve to a Facebook Page. Paste a valid Page access token.')]);
        }

        [$grantedScopes, $dataExp] = $this->tokenGrant($pageToken);

        $this->upsertPage($wsId, [
            'page_id'  => (string) $p['id'],
            'name'     => (string) ($p['name'] ?? ''),
            'category' => (string) ($p['category'] ?? ''),
            'username' => (string) ($p['username'] ?? ''),
            'picture'  => (string) ($p['picture']['data']['url'] ?? ''),
            'token'    => $pageToken,
            'tasks'    => (array) ($p['tasks'] ?? []),
            'fan'      => isset($p['fan_count']) ? (int) $p['fan_count'] : null,
            'scopes'   => $grantedScopes,
            'data_exp' => $dataExp,
        ], 'manual');

        return redirect('/devices')->with('status', __('Facebook Page “:name” connected.', ['name' => ($p['name'] ?? $p['id'])]));
    }

    /**
     * Read a token's actually-granted scopes + data-access expiry via
     * debug_token. Returns [scopes[], Carbon|null]. Never throws.
     *
     * @return array{0: array<int,string>, 1: ?\Illuminate\Support\Carbon}
     */
    private function tokenGrant(string $token): array
    {
        $grant = FacebookPageClient::debugToken($token);
        $scopes = array_values(array_filter(array_map('strval', (array) ($grant['scopes'] ?? []))));
        $dataExp = ! empty($grant['data_access_expires_at'])
            ? Carbon::createFromTimestamp((int) $grant['data_access_expires_at'])
            : null;

        return [$scopes, $dataExp];
    }

    /** Upgrade a short-lived user token to long-lived (never skip before /me/accounts). */
    private function longLived(string $shortToken): string
    {
        $long = FacebookPageClient::extendUserToken($shortToken);
        if (! empty($long['ok'])) {
            return (string) $long['access_token'];
        }
        Log::warning('[FB-CONNECT] long-lived exchange failed: '.($long['error'] ?? 'unknown'));

        return $shortToken;
    }

    /**
     * Fan out a user token into every Page it manages and store each with its
     * own Page token. This is the "connect account → get all my Pages" step.
     *
     * @return array{ok:bool, count:int, message:string}
     */
    private function storePages(string $userToken, int $wsId, string $method): array
    {
        $pages = FacebookPageClient::listPages($userToken);
        if (empty($pages)) {
            return ['ok' => false, 'count' => 0, 'message' => __('No Facebook Pages were found on this account. Make sure you granted access to at least one Page you manage.')];
        }

        // One debug_token call on the USER token gives the actually-granted
        // scopes + the data-access expiry, which every derived Page shares.
        [$grantedScopes, $dataExp] = $this->tokenGrant($userToken);

        $names = [];
        foreach ($pages as $p) {
            $pageToken = (string) ($p['access_token'] ?? '');
            $pageId = (string) ($p['id'] ?? '');
            if ($pageId === '' || $pageToken === '') {
                continue;
            }
            $this->upsertPage($wsId, [
                'page_id'  => $pageId,
                'name'     => (string) ($p['name'] ?? ''),
                'category' => (string) ($p['category'] ?? ''),
                'username' => (string) ($p['username'] ?? ''),
                'picture'  => (string) ($p['picture']['data']['url'] ?? ''),
                'token'    => $pageToken,
                'tasks'    => (array) ($p['tasks'] ?? []),
                'fan'      => isset($p['fan_count']) ? (int) $p['fan_count'] : null,
                'scopes'   => $grantedScopes,
                'data_exp' => $dataExp,
            ], $method);
            $names[] = (string) ($p['name'] ?? $pageId);
        }

        if (empty($names)) {
            return ['ok' => false, 'count' => 0, 'message' => __('Could not store any Page — Meta returned no usable Page tokens.')];
        }

        $n = count($names);
        $msg = $n === 1
            ? __('Facebook Page “:name” connected.', ['name' => $names[0]])
            : __(':n Facebook Pages connected: :list', ['n' => $n, 'list' => implode(', ', array_slice($names, 0, 5)).($n > 5 ? '…' : '')]);

        return ['ok' => true, 'count' => $n, 'message' => $msg];
    }

    /** Upsert a single Page row + best-effort webhook subscribe. */
    private function upsertPage(int $wsId, array $d, string $method): FacebookPage
    {
        $page = FacebookPage::updateOrCreate(
            ['workspace_id' => $wsId, 'page_id' => $d['page_id']],
            [
                'user_id'        => Auth::id(),
                'name'           => $d['name'] ?: null,
                'category'       => $d['category'] ?: null,
                'username'       => $d['username'] ?: null,
                'picture_url'    => $d['picture'] ?: null,
                'access_token'   => $d['token'],
                // Page tokens derived from a long-lived user token do not expire.
                'token_expires_at' => null,
                // Meta's separate data-access clock (~90 days of inactivity);
                // surfaced so re-auth can be prompted before it lapses.
                'data_access_expires_at' => $d['data_exp'] ?? null,
                // Store what Meta actually GRANTED (from debug_token), not just
                // what we requested — falls back to the requested set.
                'scopes'         => ! empty($d['scopes']) ? $d['scopes'] : explode(',', self::SCOPES),
                'tasks'          => $d['tasks'],
                'status'         => 'connected',
                'connect_method' => $method,
                'fan_count'      => $d['fan'],
                'last_error'     => null,
            ]
        );

        try {
            (new FacebookPageClient($page))->subscribeWebhooks();
        } catch (\Throwable $e) {
            Log::warning('[FB-CONNECT] subscribe failed: '.$e->getMessage(), ['page' => $page->id]);
        }

        // Pull existing Messenger threads into the unified inbox so past chats are
        // already here (mirrors Instagram's connect backfill). Deferred so the
        // connect response stays instant; stores only, never auto-replies.
        try {
            $bpage = $page;
            dispatch(function () use ($bpage) {
                try { \App\Services\Facebook\FacebookConversationBackfillService::run($bpage); }
                catch (\Throwable $e) { Log::warning('[FB-CONNECT] backfill failed: '.$e->getMessage()); }
            })->afterResponse();
        } catch (\Throwable $e) {
            Log::warning('[FB-CONNECT] backfill dispatch failed: '.$e->getMessage());
        }

        return $page;
    }

    /** Re-pull a Page's public profile (name / picture / followers). */
    public function refresh(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $page = FacebookPage::where('workspace_id', $wsId)->whereKey($id)->first();
        if (! $page) {
            return back()->withErrors(['facebook' => __('Page not found.')]);
        }
        $p = (new FacebookPageClient($page))->getProfile();
        if (! empty($p['id'])) {
            $page->forceFill([
                'name'        => (string) ($p['name'] ?? $page->name),
                'category'    => (string) ($p['category'] ?? $page->category),
                'username'    => (string) ($p['username'] ?? $page->username),
                'picture_url' => (string) ($p['picture']['data']['url'] ?? $page->picture_url),
                'fan_count'   => isset($p['fan_count']) ? (int) $p['fan_count'] : (isset($p['followers_count']) ? (int) $p['followers_count'] : $page->fan_count),
            ])->save();

            return back()->with('status', __('Facebook Page refreshed.'));
        }

        return back()->withErrors(['facebook' => __('Could not refresh (the Page token may need re-auth).')]);
    }

    /** Re-run this Page's webhook subscription ("Fix inbound"). */
    public function resubscribe(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $page = FacebookPage::where('workspace_id', $wsId)->whereKey($id)->first();
        if (! $page) {
            return back()->withErrors(['facebook' => __('Page not found.')]);
        }
        $r = (new FacebookPageClient($page))->subscribeWebhooks();

        return ! empty($r['ok'])
            ? back()->with('status', __('Facebook webhooks re-subscribed for this Page.'))
            : back()->withErrors(['facebook' => __('Re-subscribe failed: ').($r['error'] ?? 'unknown')]);
    }

    /** Disconnect: unsubscribe webhooks and remove the Page. */
    public function disconnect(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $page = FacebookPage::where('workspace_id', $wsId)->whereKey($id)->first();
        if ($page) {
            try {
                (new FacebookPageClient($page))->unsubscribeWebhooks();
            } catch (\Throwable $e) {
                // best-effort — remove locally regardless
            }
            $page->delete();
        }

        return back()->with('status', __('Facebook Page disconnected.'));
    }
}
