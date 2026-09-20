<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokAccount;
use App\Services\PlanLimitGuard;
use App\Services\Tiktok\TiktokClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * TikTok account connect (Login Kit OAuth v2, web flow).
 *   GET /tiktok/connect  → redirect to TikTok consent (state = CSRF)
 *   GET /tiktok/callback → exchange code, pull profile, store the account
 *
 * Web flow uses `state` + a server-held client_secret (no PKCE — PKCE is for
 * desktop/mobile). Access tokens live 24h; the refresh token (365d) is stored
 * encrypted and renewed by TiktokTokenRefreshSweeper.
 */
class TiktokConnectController extends Controller
{
    private function redirectUri(): string
    {
        // Canonical value (admin-set fixed URI → dynamic fallback). The authorize
        // redirect and the code exchange BOTH read this, so they always match the
        // portal registration and each other — avoiding the "redirect_uri" error.
        return TiktokClient::redirectUri();
    }

    /** Plan-gate in code (start/callback are GET; callback writes). */
    private function planOk(): bool
    {
        $ws = Auth::user()?->currentWorkspace;

        return $ws ? (bool) PlanLimitGuard::hasFeature($ws, 'access_tiktok') : false;
    }

    /** Kick off the TikTok OAuth consent dialog. */
    public function start(Request $request)
    {
        if (! TiktokClient::enabled()) {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('TikTok is not configured. Ask the platform admin to enable it under Settings.')]);
        }
        if (! $this->planOk()) {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('Your plan does not include TikTok. Upgrade to connect an account.')]);
        }

        // Round-tripped `state` is validated on return to stop login-CSRF.
        $request->session()->put('tiktok_oauth_state', $state = bin2hex(random_bytes(16)));

        // DEEP TRACE — TikTok's "Something went wrong … redirect_uri" page is shown
        // DURING consent (before any callback), so this is the one log that explains
        // that error: compare `redirect_uri` here to what's registered in the TikTok
        // portal (Login Kit) for the SAME environment (sandbox vs production) as the
        // client_key below. `is_https` flags the usual culprit (an IP / http APP_URL).
        // The full `authorize_url` can even be opened directly to reproduce.
        $redirectUri  = $this->redirectUri();
        $fixed        = trim((string) \App\Models\SystemSetting::get('tiktok_redirect_uri', ''));
        $authorizeUrl = TiktokClient::authorizeUrl($redirectUri, $state);
        Log::info('[TIKTOK-OAUTH] start → redirecting user to TikTok consent', [
            'redirect_uri'        => $redirectUri,
            'redirect_uri_source' => $fixed !== '' ? 'admin_fixed (tiktok_redirect_uri)' : 'fallback url(/tiktok/callback)',
            'app_url'             => (string) config('app.url'),
            'is_https'            => str_starts_with($redirectUri, 'https://'),
            'has_trailing_slash'  => str_ends_with($redirectUri, '/'),
            'client_key_tail'     => substr(TiktokClient::clientKey(), -6),
            'client_key_len'      => strlen(TiktokClient::clientKey()),
            'scopes'              => TiktokClient::SCOPES,
            'workspace_id'        => Auth::user()?->current_workspace_id,
            'authorize_url'       => $authorizeUrl,
        ]);

        return redirect()->away($authorizeUrl);
    }

    /** OAuth callback → exchange code, fetch profile, upsert the account. */
    public function callback(Request $request)
    {
        // DEEP TRACE — what TikTok actually returned. If consent SUCCEEDED, `error`
        // is empty and `has_code` is true; a redirect_uri/scope problem that still
        // reaches the callback arrives as `error` + `error_description` here.
        Log::info('[TIKTOK-OAUTH] callback received', [
            'has_code'          => $request->filled('code'),
            'error'             => (string) $request->query('error', ''),
            'error_description' => (string) $request->query('error_description', ''),
            'state_present'     => $request->filled('state'),
            'redirect_uri'      => $this->redirectUri(),
            'query_keys'        => array_keys($request->query()),
            'workspace_id'      => Auth::user()?->current_workspace_id,
        ]);

        if ($request->filled('error')) {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => (string) $request->string('error_description', $request->string('error'))]);
        }
        // CSRF: the state we issued must round-trip unchanged.
        $expected = (string) $request->session()->pull('tiktok_oauth_state', '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('TikTok connect could not be verified. Please start again.')]);
        }
        if (! $this->planOk()) {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('Your plan does not include TikTok.')]);
        }

        $code = (string) $request->string('code');
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        if ($code === '' || ! $wsId) {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('Missing code or workspace.')]);
        }

        $tok = TiktokClient::exchangeCode($code, $this->redirectUri());
        if (empty($tok['ok'])) {
            Log::warning('[TIKTOK-CONNECT] token exchange failed', [
                'err'          => $tok['error'] ?? '',
                'redirect_uri' => $this->redirectUri(),
            ]);

            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('Token exchange failed: ').($tok['error'] ?? 'unknown')]);
        }

        $openId = (string) $tok['open_id'];
        if ($openId === '') {
            return redirect('/tiktok/accounts')->withErrors(['tiktok' => __('TikTok did not return an account id.')]);
        }

        $account = TiktokAccount::updateOrCreate(
            ['workspace_id' => $wsId, 'open_id' => $openId],
            [
                'user_id'            => Auth::id(),
                'access_token'       => $tok['access_token'],
                'refresh_token'      => $tok['refresh_token'],
                'token_expires_at'   => Carbon::now()->addSeconds((int) $tok['expires_in']),
                'refresh_expires_at' => Carbon::now()->addSeconds((int) $tok['refresh_expires_in']),
                'scopes'             => array_values(array_filter(explode(',', (string) $tok['scope']))),
                'status'             => 'connected',
                'connect_method'     => 'oauth',
                'last_error'         => null,
            ]
        );

        // Pull profile now (best effort — depends on granted scopes).
        $this->syncProfile($account);

        $name = $account->display_name ?: ($account->username ? '@'.$account->username : $openId);

        return redirect('/tiktok/accounts')->with('status', __('TikTok account connected: :name', ['name' => $name]));
    }

    /** Re-pull profile + stats for a connected account. */
    public function refresh(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $account = TiktokAccount::forWorkspace($wsId)->whereKey($id)->first();
        if (! $account) {
            return back()->withErrors(['tiktok' => __('Account not found.')]);
        }
        if ($this->syncProfile($account)) {
            return back()->with('status', __('TikTok account refreshed.'));
        }

        return back()->withErrors(['tiktok' => __('Could not refresh (the token may need reconnecting).')]);
    }

    /** Disconnect: revoke the token with TikTok, then remove the account. */
    public function disconnect(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $account = TiktokAccount::forWorkspace($wsId)->whereKey($id)->first();
        if (! $account) {
            return back()->withErrors(['tiktok' => __('Account not found.')]);
        }
        try {
            if ($account->access_token) {
                TiktokClient::revoke((string) $account->access_token);
            }
        } catch (\Throwable $e) { /* best effort */ }
        $account->delete();

        return back()->with('status', __('TikTok account disconnected.'));
    }

    /**
     * POST /tiktok/{id}/inbox — enable the Business Messaging (DM) inbox for a
     * connected account. Requires TikTok Messaging-Partner approval + the business
     * app credentials the platform admin sets under Admin → Channel Settings
     * (tiktok_business_app_id / tiktok_business_app_secret / tiktok_inbox_enabled).
     * The operator pastes the business_id + business access token issued by their
     * approved partner authorization; we store them on the account meta (the same
     * keys TiktokBusinessClient + dispatchTiktok read), detect the account region
     * (which the availability gate needs — an unknown region fails closed), and
     * hand back the webhook URL to register on the app in the developer portal.
     * Fail-soft: nothing here throws, and the DM lane stays off until real,
     * partner-approved credentials + a supported region are present.
     */
    public function connectInbox(Request $request, int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $account = TiktokAccount::forWorkspace($wsId)->whereKey($id)->first();
        if (! $account) {
            return back()->withErrors(['tiktok_inbox' => __('Account not found.')]);
        }
        if (! \App\Services\Tiktok\TiktokBusinessClient::enabled()) {
            return back()->withErrors(['tiktok_inbox' => setup_hint(__('The TikTok DM inbox is not enabled — it needs TikTok Messaging-Partner approval and the business app credentials set under Admin → Channel Settings.'), __('The TikTok DM inbox is currently unavailable. Please contact support for assistance.'))]);
        }

        $data = $request->validate([
            'business_id'           => 'required|string|max:64',
            'business_access_token' => 'required|string|max:4000',
        ]);

        // Store the Business Messaging credentials on the account meta — the same
        // keys TiktokBusinessClient (business.business_id / business.access_token)
        // and dispatchTiktok (business.region) read.
        $meta = is_array($account->meta_json) ? $account->meta_json : [];
        $meta['business'] = array_merge($meta['business'] ?? [], [
            'business_id'  => $data['business_id'],
            'access_token' => $data['business_access_token'],
            'connected_at' => now()->toIso8601String(),
        ]);
        $account->meta_json = $meta;
        $account->save();

        // Detect + store the account region — messaging is region-gated and an
        // unknown region fails closed, so this is what actually opens the DM lane
        // for a supported, approved account.
        $client = new \App\Services\Tiktok\TiktokBusinessClient($account);
        $info = $client->getBusinessInfo();
        if (! empty($info['region'])) {
            $meta['business']['region'] = (string) $info['region'];
            $account->meta_json = $meta;
            $account->save();
        }

        $webhookUrl = url('/webhooks/tiktok/business');
        $base = __('TikTok DM credentials saved. Register this webhook URL on your TikTok app in the developer portal (subscribe new_message / new_conversation): :url', ['url' => $webhookUrl]);

        if (empty($info['region'])) {
            return back()->with('warning', $base.' '.__('The account region could not be confirmed yet — DM delivery is region-gated, so verify your Messaging-Partner app is approved for this account.'));
        }

        return back()->with('status', $base);
    }

    /** DELETE /tiktok/{id}/inbox — clear the Business Messaging credentials. */
    public function disconnectInbox(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $account = TiktokAccount::forWorkspace($wsId)->whereKey($id)->first();
        if (! $account) {
            return back()->withErrors(['tiktok_inbox' => __('Account not found.')]);
        }
        $meta = is_array($account->meta_json) ? $account->meta_json : [];
        unset($meta['business']);
        $account->meta_json = $meta;
        $account->save();

        return back()->with('status', __('TikTok DM inbox disconnected.'));
    }

    /** Fetch profile/stats via the Display API and store them. Returns true on success. */
    private function syncProfile(TiktokAccount $account): bool
    {
        $u = (new TiktokClient($account))->getUserInfo();
        if (empty($u['open_id']) && empty($u['display_name']) && empty($u['username'])) {
            return false;
        }
        $account->forceFill(array_filter([
            'union_id'        => $u['union_id'] ?? $account->union_id,
            'display_name'    => $u['display_name'] ?? $account->display_name,
            'username'        => $u['username'] ?? $account->username,
            'avatar_url'      => $u['avatar_url'] ?? $account->avatar_url,
            'bio'             => $u['bio_description'] ?? $account->bio,
            'is_verified'     => $u['is_verified'] ?? $account->is_verified,
            'follower_count'  => $u['follower_count'] ?? $account->follower_count,
            'following_count' => $u['following_count'] ?? $account->following_count,
            'likes_count'     => $u['likes_count'] ?? $account->likes_count,
            'video_count'     => $u['video_count'] ?? $account->video_count,
        ], fn ($v) => $v !== null))->save();

        return true;
    }
}
