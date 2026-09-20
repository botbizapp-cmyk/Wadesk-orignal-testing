<?php

namespace App\Services\Tiktok;

use App\Models\SystemSetting;
use App\Models\TiktokShop;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Shop Partner API client (App C). SEPARATE app from Login Kit (A) and
 * Business Messaging (B): its own app_key + app_secret, seller OAuth, and a
 * `shop_cipher` + computed `sign` (HMAC-SHA256) on EVERY call. Region-split
 * Partner Centers (global vs US). Powers a buyer↔seller IM inbox + order/product
 * context alongside the Shopify / WooCommerce integrations.
 *
 * ⚠️ PARTNER-GATED. The `seller.customer_service` scope needs TikTok Shop Partner
 * Center approval. Some literal paths were SPA-blocked in research (versioned
 * `/customer_service/202309/…`) — re-confirm at partner.tiktokshop.com/docv2
 * before production. Every call fails soft.
 */
class TiktokShopClient
{
    public string $lastError = '';

    public function __construct(private ?TiktokShop $shop = null) {}

    // ── Credentials + region ────────────────────────────────────────────────

    public static function appKey(): string
    {
        return (string) SystemSetting::get('tiktok_shop_app_key', '');
    }

    public static function appSecret(): string
    {
        return (string) SystemSetting::get('tiktok_shop_app_secret', '');
    }

    public static function enabled(): bool
    {
        return (bool) SystemSetting::get('tiktok_shop_enabled', false)
            && self::appKey() !== '' && self::appSecret() !== '';
    }

    /** Open API host (same global host; region is carried by the shop_cipher). */
    private static function apiBase(): string
    {
        return 'https://open-api.tiktokglobalshop.com';
    }

    /** Seller-auth service host — US has a separate Partner Center. */
    public static function authBase(?string $region = null): string
    {
        return strtoupper((string) $region) === 'US'
            ? 'https://services.us.tiktokshop.com'
            : 'https://services.tiktokshop.com';
    }

    // ── OAuth (seller authorization) ────────────────────────────────────────

    /** Seller-authorization URL; TikTok redirects back with `code`. */
    public static function authorizeUrl(string $state, ?string $region = null): string
    {
        return self::authBase($region).'/open/authorize?'.http_build_query([
            'service_id' => self::appKey(),
            'state'      => $state,
        ]);
    }

    /** Exchange the auth code → access_token + refresh_token + shops (with cipher). */
    public static function exchangeCode(string $code): array
    {
        return self::tokenCall(['app_key' => self::appKey(), 'app_secret' => self::appSecret(), 'auth_code' => $code, 'grant_type' => 'authorized_code']);
    }

    public static function refreshToken(string $refreshToken): array
    {
        return self::tokenCall(['app_key' => self::appKey(), 'app_secret' => self::appSecret(), 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);
    }

    private static function tokenCall(array $query): array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get('https://auth.tiktok-shops.com/api/v2/token/get', $query);
            $data = (array) ($r->json('data') ?? []);
            if ($r->successful() && (int) ($r->json('code') ?? -1) === 0 && ! empty($data['access_token'])) {
                return ['ok' => true] + $data;
            }

            return ['ok' => false, 'error' => (string) ($r->json('message') ?? 'token call failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List the shops this access token is authorized for — returns shop_id +
     * shop_cipher + region for each. This is the call that PROVIDES the cipher,
     * so it signs WITHOUT one. Pass the freshly-minted token explicitly.
     */
    public static function getAuthorizedShops(string $accessToken): array
    {
        if (! self::enabled()) {
            return ['ok' => false, 'error' => 'TikTok Shop not enabled.'];
        }
        $path = '/authorization/202309/shops';
        $query = ['app_key' => self::appKey(), 'timestamp' => (string) time()];
        $query['sign'] = self::computeSign($path, $query, '');
        try {
            $r = Http::withHeaders(['x-tts-access-token' => $accessToken])->acceptJson()->timeout(20)
                ->get(self::apiBase().$path, $query);
            $j = (array) $r->json();
            $ok = $r->successful() && (int) ($j['code'] ?? -1) === 0;

            return ['ok' => $ok, 'shops' => (array) data_get($j, 'data.shops', []), 'error' => $ok ? null : (string) ($j['message'] ?? 'shops lookup failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Customer-service (buyer messaging) inbox ────────────────────────────

    public function getConversations(array $params = []): array
    {
        return $this->signedCall('GET', '/customer_service/202309/conversations', $params);
    }

    public function getMessages(string $conversationId, array $params = []): array
    {
        return $this->signedCall('GET', "/customer_service/202309/conversations/{$conversationId}/messages", $params);
    }

    public function sendMessage(string $conversationId, string $text): array
    {
        return $this->signedCall('POST', "/customer_service/202309/conversations/{$conversationId}/messages", [], [
            'type' => 'TEXT', 'text' => mb_substr($text, 0, 2000),
        ]);
    }

    /** Register/refresh the NEW_MESSAGE / NEW_CONVERSATION webhook. */
    public function registerWebhook(string $callbackUrl): array
    {
        return $this->signedCall('PUT', '/event/202309/webhooks', [], [
            'address' => $callbackUrl,
            'events'  => ['NEW_MESSAGE', 'NEW_CONVERSATION'],
        ]);
    }

    /** Orders for CRM context in the conversation. */
    public function searchOrders(array $body = []): array
    {
        return $this->signedCall('POST', '/order/202309/orders/search', [], $body);
    }

    // ── Signed request ──────────────────────────────────────────────────────

    /**
     * TikTok Shop signs every call. sign = HMAC-SHA256 over
     *   app_secret + path + (sorted "keyvalue" of all query params except sign/access_token)
     *   + rawBody + app_secret
     * keyed by app_secret, hex. The call also needs app_key, timestamp, shop_cipher
     * and the access_token (header).
     */
    private function signedCall(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (! self::enabled()) {
            return ['ok' => false, 'error' => 'TikTok Shop is not enabled / not partner-approved yet.'];
        }
        $shop = $this->shop;
        if (! $shop) {
            return ['ok' => false, 'error' => 'No TikTok Shop connected.'];
        }

        $query = array_merge($query, [
            'app_key'     => self::appKey(),
            'timestamp'   => (string) time(),
            'shop_cipher' => (string) $shop->shop_cipher,
        ]);
        $rawBody = $body !== null ? json_encode($body) : '';
        $query['sign'] = self::computeSign($path, $query, $rawBody);

        try {
            $req = Http::withHeaders(['x-tts-access-token' => (string) $shop->access_token])
                ->acceptJson()->timeout(20);
            $url = self::apiBase().$path;
            $r = match ($method) {
                'GET'  => $req->get($url, $query),
                'PUT'  => $req->withBody($rawBody, 'application/json')->put($url.'?'.http_build_query($query)),
                default => $req->withBody($rawBody, 'application/json')->post($url.'?'.http_build_query($query)),
            };
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

    /** Build the TikTok Shop request signature (see signedCall docblock). */
    private static function computeSign(string $path, array $query, string $rawBody): string
    {
        $params = $query;
        unset($params['sign'], $params['access_token']);
        ksort($params);
        $concat = '';
        foreach ($params as $k => $v) {
            $concat .= $k.$v;
        }
        $secret = self::appSecret();
        $base = $secret.$path.$concat.$rawBody.$secret;

        return hash_hmac('sha256', $base, $secret);
    }
}
