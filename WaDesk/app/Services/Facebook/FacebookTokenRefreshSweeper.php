<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Health-checks a workspace's connected Facebook Page tokens and flags any that
 * Meta has invalidated (revoked / password-changed / app-removed) so the UI can
 * prompt a reconnect. Page tokens derived from a long-lived user token do not
 * expire, so there's nothing to REFRESH — but they CAN be revoked, and the
 * 90-day data-access window can lapse; both are surfaced here.
 *
 * Project policy is no-cron: this runs INLINE, cache-gated to once/hour per
 * workspace, from the Channels page load. Cheap (1 debug_token call per Page).
 */
class FacebookTokenRefreshSweeper
{
    public static function run(int $workspaceId): void
    {
        if ($workspaceId <= 0 || ! (bool) SystemSetting::get('facebook_enabled', false)) {
            return;
        }
        $key = 'fb_token_sweep:'.$workspaceId;
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, 1, now()->addHour());

        $pages = FacebookPage::where('workspace_id', $workspaceId)
            ->whereIn('status', ['connected', 'expired', 'error'])->get();

        foreach ($pages as $page) {
            try {
                $d = FacebookPageClient::debugToken((string) $page->access_token);
                if ($d === []) {
                    continue; // couldn't check (network/app creds) — leave as-is
                }
                $upd = [];
                if (! empty($d['data_access_expires_at'])) {
                    $upd['data_access_expires_at'] = Carbon::createFromTimestamp((int) $d['data_access_expires_at']);
                }
                if (! empty($d['is_valid'])) {
                    // Recovered / still healthy — clear any prior expired flag.
                    if ($page->status !== 'connected') {
                        $upd['status'] = 'connected';
                        $upd['last_error'] = null;
                    }
                } else {
                    $upd['status'] = 'expired';
                    $upd['last_error'] = 'Token is no longer valid — reconnect this Page.';
                }
                if ($upd) {
                    $page->forceFill($upd)->save();
                }
            } catch (\Throwable $e) {
                // best effort — a hiccup must never break the page load
            }
        }
    }
}
