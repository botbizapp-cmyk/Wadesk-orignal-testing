<?php

namespace App\Services\Push;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) sender.
 *
 * Auth is minted from the Firebase SERVICE-ACCOUNT JSON via a self-signed JWT
 * (RS256 with PHP's built-in openssl) exchanged for an OAuth access token — so
 * there is NO composer dependency (kreait/google-auth) to add on the client's
 * shared hosting. Config lives in SystemSetting (admin pastes it), so no
 * migration is needed to turn it on:
 *   - fcm_service_account_json : the full service-account JSON (encrypted at rest)
 *   - fcm_project_id           : optional override; else read from the JSON
 *
 * When not configured, enabled() is false and every send is a no-op.
 */
class FcmService
{
    public function enabled(): bool
    {
        return $this->projectId() !== '' && $this->serviceAccount() !== null;
    }

    private function projectId(): string
    {
        $sa = $this->serviceAccount();
        return (string) ($sa['project_id'] ?? (string) SystemSetting::get('fcm_project_id', ''));
    }

    /** Decoded service-account JSON, or null when unset/invalid. */
    private function serviceAccount(): ?array
    {
        $raw = (string) SystemSetting::get('fcm_service_account_json', '');
        if ($raw === '') return null;
        $json = json_decode($raw, true);
        return (is_array($json) && !empty($json['client_email']) && !empty($json['private_key'])) ? $json : null;
    }

    /**
     * OAuth2 access token for the FCM scope — service-account JWT (RS256) →
     * token endpoint. Cached ~55 min (Google tokens live 60).
     */
    private function accessToken(): ?string
    {
        $sa = $this->serviceAccount();
        if (!$sa) return null;

        $cacheKey = 'fcm_access_token_' . md5((string) $sa['client_email']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;

        $now   = time();
        $b64   = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $head  = $b64(['alg' => 'RS256', 'typ' => 'JWT']);
        $claim = $b64([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]);
        $signingInput = $head . '.' . $claim;

        $sig = '';
        if (!openssl_sign($signingInput, $sig, (string) $sa['private_key'], 'sha256')) {
            Log::error('[FCM] openssl_sign failed — check the service-account private_key.');
            return null;
        }
        $jwt = $signingInput . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

        try {
            $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);
            if (!$res->successful()) {
                Log::error('[FCM] token exchange failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
                return null;
            }
            $token = (string) $res->json('access_token');
            $ttl   = (int) ($res->json('expires_in') ?? 3600);
            if ($token !== '') Cache::put($cacheKey, $token, max(60, $ttl - 300));
            return $token ?: null;
        } catch (\Throwable $e) {
            Log::error('[FCM] token exchange exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send ONE notification to MANY tokens (fired concurrently via Http::pool —
     * FCM v1 is one-token-per-call). Returns
     *   ['sent' => int, 'failed' => int, 'invalid' => string[]]
     * where `invalid` are tokens FCM rejected as dead (caller should delete).
     *
     * @param string[] $tokens
     */
    public function sendToTokens(array $tokens, array $notification, array $data = [], array $android = [], array $apns = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $out = ['sent' => 0, 'failed' => 0, 'invalid' => []];
        Log::info('[FCM] sendToTokens', [
            'tokens'       => count($tokens),
            'enabled'      => $this->enabled(),
            'project'      => $this->projectId(),
            'notification' => $notification,
            'data'         => $data,
        ]);
        if (empty($tokens) || !$this->enabled()) {
            Log::warning('[FCM] send skipped', ['tokens' => count($tokens), 'enabled' => $this->enabled()]);
            return $out;
        }

        $access = $this->accessToken();
        if (!$access) { $out['failed'] = count($tokens); return $out; }

        $url  = 'https://fcm.googleapis.com/v1/projects/' . $this->projectId() . '/messages:send';
        $dataFlat = $this->stringifyData($data);

        foreach (array_chunk($tokens, 100) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk, $url, $access, $notification, $dataFlat, $android, $apns) {
                $calls = [];
                foreach ($chunk as $i => $token) {
                    $message = ['message' => array_filter([
                        'token'        => $token,
                        'notification' => $notification ?: null,
                        'data'         => $dataFlat ?: null,
                        'android'      => $android ?: ['priority' => 'high'],
                        'apns'         => $apns ?: null,
                    ], fn ($v) => $v !== null)];
                    $calls[$i] = $pool->as((string) $i)->withToken($access)->acceptJson()->timeout(15)->post($url, $message);
                }
                return $calls;
            });

            foreach ($chunk as $i => $token) {
                $resp = $responses[(string) $i] ?? null;
                if ($resp instanceof \Throwable || $resp === null) { $out['failed']++; continue; }
                if ($resp->successful()) { $out['sent']++; continue; }
                $out['failed']++;
                $status = (string) ($resp->json('error.status') ?? '');
                Log::warning('[FCM] token send failed', [
                    'http'   => $resp->status(),
                    'status' => $status,
                    'body'   => mb_substr($resp->body(), 0, 300),   // FCM's exact rejection reason
                    'token'  => substr($token, 0, 12) . '…',
                ]);
                // Dead/unregistered token → tell the caller to prune it.
                if (in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                    $out['invalid'][] = $token;
                }
            }
        }
        Log::info('[FCM] send summary', $out);
        return $out;
    }

    /** FCM `data` must be a flat string→string map. */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($v === null) continue;
            $out[(string) $k] = is_scalar($v) ? (string) $v : (string) json_encode($v);
        }
        return $out;
    }
}
