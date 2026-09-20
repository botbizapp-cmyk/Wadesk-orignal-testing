<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokAccount;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokTokenRefreshSweeper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * TikTok insights — account profile (followers / likes / videos) + a grid of the
 * connected account's public videos with per-video engagement, read live from
 * the Display API (poll-based; TikTok has no insights webhooks). Every page has
 * an always-visible account identity + switcher, mirroring the Facebook insights
 * page. Video cover URLs are TTL'd — shown live, never persisted.
 */
class TiktokInsightsController extends Controller
{
    public function index(Request $request): View
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);

        // No-cron token maintenance — 24h access tokens are refreshed on load.
        try { TiktokTokenRefreshSweeper::run($wsId); } catch (\Throwable $e) {}

        $accounts = TiktokAccount::forWorkspace($wsId)->connected()->orderBy('display_name')->get();

        $account  = null;
        $identity = [];
        $kpis     = [];
        $videos   = [];

        if ($accounts->isNotEmpty()) {
            $accountId = (int) $request->integer('account', $accounts->first()->id);
            $account   = $accounts->firstWhere('id', $accountId) ?: $accounts->first();

            $client = new TiktokClient($account);

            // Refresh identity live (best effort) so counts are current; fall back
            // to the stored values from connect-time.
            $u = $client->getUserInfo();
            $identity = [
                'name'     => $u['display_name'] ?? $account->display_name ?? $account->open_id,
                'username' => $u['username'] ?? $account->username,
                'avatar'   => $u['avatar_url'] ?? $account->avatar_url,
                'bio'      => $u['bio_description'] ?? $account->bio,
                'verified' => $u['is_verified'] ?? $account->is_verified,
                'deeplink' => $u['profile_deep_link'] ?? null,
            ];
            $kpis = [
                'followers' => $u['follower_count']  ?? $account->follower_count,
                'following' => $u['following_count'] ?? $account->following_count,
                'likes'     => $u['likes_count']     ?? $account->likes_count,
                'videos'    => $u['video_count']     ?? $account->video_count,
            ];

            // Persist the refreshed scalar stats (not the TTL'd avatar/covers).
            if (! empty($u)) {
                $account->forceFill(array_filter([
                    'display_name'    => $u['display_name'] ?? null,
                    'username'        => $u['username'] ?? null,
                    'follower_count'  => $u['follower_count'] ?? null,
                    'following_count' => $u['following_count'] ?? null,
                    'likes_count'     => $u['likes_count'] ?? null,
                    'video_count'     => $u['video_count'] ?? null,
                ], fn ($v) => $v !== null))->save();
            }

            $videos = $client->listVideos(20)['videos'];
        }

        return view('user.tiktok.insights', compact('accounts', 'account', 'identity', 'kpis', 'videos'));
    }
}
