<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokAccount;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokTokenRefreshSweeper;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * TikTok accounts — the connect + manage surface (mirrors the Facebook/Instagram
 * device rows, but as a dedicated channel page). Lists connected accounts,
 * exposes Connect / Refresh / Disconnect, and sweeps tokens on load.
 */
class TiktokAccountsController extends Controller
{
    public function index(): View
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);

        // No-cron token maintenance — keep 24h access tokens alive on page load.
        try { TiktokTokenRefreshSweeper::run($wsId); } catch (\Throwable $e) {}

        $accounts  = TiktokAccount::forWorkspace($wsId)->orderBy('display_name')->get();
        $configured = TiktokClient::enabled();

        return view('user.tiktok.accounts', compact('accounts', 'configured'));
    }
}
