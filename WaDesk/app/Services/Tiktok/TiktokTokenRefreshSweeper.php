<?php

namespace App\Services\Tiktok;

use App\Models\SystemSetting;
use App\Models\TiktokAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a workspace's TikTok access tokens alive. TikTok access tokens expire
 * every ~24h (unlike Meta's non-expiring Page tokens), so this ACTUALLY refreshes
 * them — any account whose token is within ~2h of expiry is renewed with its
 * 365-day refresh token (which itself rotates on each refresh). A refresh_token
 * idle >365d cannot be renewed → the account is flagged needs_reconnect.
 *
 * Project policy is no-cron: runs INLINE, cache-gated once/15min per workspace,
 * from the Channels / TikTok pages. Cheap (one token call per near-expiry account).
 */
class TiktokTokenRefreshSweeper
{
    public static function run(int $workspaceId): void
    {
        if ($workspaceId <= 0 || ! TiktokClient::enabled()) {
            return;
        }
        $key = 'tt_token_sweep:'.$workspaceId;
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, 1, now()->addMinutes(15));

        $accounts = TiktokAccount::where('workspace_id', $workspaceId)
            ->whereIn('status', ['connected', 'expired', 'error'])->get();

        foreach ($accounts as $acct) {
            try {
                $soon = ! $acct->token_expires_at || $acct->token_expires_at->lt(Carbon::now()->addHours(2));
                if (! $soon) {
                    continue; // still fresh
                }

                // Refresh token itself lapsed (idle >365d) → full re-auth needed.
                if ($acct->refresh_expires_at && $acct->refresh_expires_at->isPast()) {
                    $acct->forceFill([
                        'status'     => 'needs_reconnect',
                        'last_error' => 'TikTok refresh token expired — reconnect the account.',
                    ])->save();
                    continue;
                }
                if (! $acct->refresh_token) {
                    continue;
                }

                $res = TiktokClient::refreshAccessToken((string) $acct->refresh_token);
                if (! empty($res['ok'])) {
                    $acct->forceFill([
                        'access_token'       => $res['access_token'],
                        'refresh_token'      => $res['refresh_token'] ?: $acct->refresh_token,
                        'token_expires_at'   => Carbon::now()->addSeconds((int) $res['expires_in']),
                        'refresh_expires_at' => Carbon::now()->addSeconds((int) $res['refresh_expires_in']),
                        'status'             => 'connected',
                        'last_error'         => null,
                    ])->save();
                } else {
                    $acct->forceFill([
                        'status'     => 'needs_reconnect',
                        'last_error' => mb_substr('TikTok token refresh failed: '.($res['error'] ?? 'unknown'), 0, 490),
                    ])->save();
                }
            } catch (\Throwable $e) {
                // best effort — never let a token sweep break the page load
            }
        }
    }
}
