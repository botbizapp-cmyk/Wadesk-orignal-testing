<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokShop;
use App\Services\Tiktok\TiktokShopClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TikTok Shop connect + manage (App C — Partner API). Lives with the other
 * store integrations (Shopify / WooCommerce / Catalog). Seller-authorization
 * OAuth mints an access token + refresh token; a follow-up authorized-shops
 * call resolves each shop's `shop_cipher` (required on every later call).
 *
 * ⚠️ Partner-gated (seller.customer_service scope needs TikTok Shop Partner
 * Center approval) and region-split. Fails soft until approved.
 */
class TiktokShopController extends Controller
{
    public function index(Request $request): View
    {
        $wsId  = (int) (Auth::user()?->current_workspace_id ?? 0);
        $shops = TiktokShop::forWorkspace($wsId)->orderBy('shop_name')->get();
        $configured = TiktokShopClient::enabled();

        // Which connected shop is being managed — a workspace can connect several
        // (multi-shop sellers). Defaults to the first; switch via ?shop=<id>.
        $shopId = (int) $request->query('shop');
        $shop   = $shops->firstWhere('id', $shopId) ?: $shops->first();

        $tabs = ['overview' => 'Overview', 'orders' => 'Orders', 'products' => 'Products', 'messages' => 'Buyer messages', 'settings' => 'Settings'];
        $activeTab = (string) $request->query('tab', 'overview');
        if (! array_key_exists($activeTab, $tabs)) {
            $activeTab = 'overview';
        }

        return view('user.tiktok.shop', compact('shops', 'shop', 'configured', 'tabs', 'activeTab'));
    }

    /** Kick off seller authorization. */
    public function connect(Request $request)
    {
        if (! TiktokShopClient::enabled()) {
            return redirect('/tiktok-shop')->withErrors(['tiktok_shop' => __('TikTok Shop is not configured. Ask the platform admin to add the Shop app credentials.')]);
        }
        $request->session()->put('tiktok_shop_state', $state = bin2hex(random_bytes(16)));

        return redirect()->away(TiktokShopClient::authorizeUrl($state));
    }

    /** OAuth callback → exchange code, resolve shops + ciphers, store. */
    public function callback(Request $request)
    {
        $expected = (string) $request->session()->pull('tiktok_shop_state', '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/tiktok-shop')->withErrors(['tiktok_shop' => __('TikTok Shop connect could not be verified. Please try again.')]);
        }
        $code = (string) $request->string('code', $request->string('auth_code'));
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        if ($code === '' || ! $wsId) {
            return redirect('/tiktok-shop')->withErrors(['tiktok_shop' => __('Missing authorization code.')]);
        }

        $tok = TiktokShopClient::exchangeCode($code);
        if (empty($tok['ok'])) {
            Log::warning('[TT-SHOP] token exchange failed', ['err' => $tok['error'] ?? '']);

            return redirect('/tiktok-shop')->withErrors(['tiktok_shop' => __('Token exchange failed: ').($tok['error'] ?? 'unknown')]);
        }

        $access  = (string) $tok['access_token'];
        $refresh = (string) ($tok['refresh_token'] ?? '');
        $accessExp  = isset($tok['access_token_expire_in']) ? Carbon::now()->addSeconds((int) $tok['access_token_expire_in']) : null;
        $refreshExp = isset($tok['refresh_token_expire_in']) ? Carbon::now()->addSeconds((int) $tok['refresh_token_expire_in']) : null;

        // Resolve the authorized shops (gives shop_cipher). If the call is not yet
        // enabled (pending partner approval), still store a pending record so the
        // token isn't lost — the operator sees "connected, resolving shop".
        $shops = TiktokShopClient::getAuthorizedShops($access);
        $rows  = ! empty($shops['ok']) ? (array) $shops['shops'] : [[]];

        $count = 0;
        foreach ($rows as $s) {
            TiktokShop::updateOrCreate(
                ['workspace_id' => $wsId, 'shop_id' => (string) ($s['id'] ?? $s['shop_id'] ?? ('pending-'.$wsId))],
                [
                    'user_id'            => Auth::id(),
                    'shop_name'          => (string) ($s['name'] ?? $tok['seller_name'] ?? 'TikTok Shop'),
                    'shop_cipher'        => (string) ($s['cipher'] ?? $s['shop_cipher'] ?? ''),
                    'shop_code'          => (string) ($s['code'] ?? ''),
                    'region'             => (string) ($s['region'] ?? ''),
                    'seller_name'        => (string) ($tok['seller_name'] ?? ''),
                    'access_token'       => $access,
                    'refresh_token'      => $refresh,
                    'token_expires_at'   => $accessExp,
                    'refresh_expires_at' => $refreshExp,
                    'status'             => 'connected',
                    'last_error'         => empty($shops['ok']) ? 'Connected — shop details resolve once the Partner API scope is granted.' : null,
                ]
            );
            $count++;
        }

        return redirect('/tiktok-shop')->with('status', __(':n TikTok Shop(s) connected.', ['n' => $count]));
    }

    public function disconnect(int $id)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $shop = TiktokShop::forWorkspace($wsId)->whereKey($id)->first();
        if ($shop) {
            $shop->delete();
        }

        return back()->with('status', __('TikTok Shop disconnected.'));
    }
}
