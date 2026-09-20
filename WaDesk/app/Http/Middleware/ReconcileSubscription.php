<?php

namespace App\Http\Middleware;

use App\Services\Payment\SubscriptionReconciler;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cron-free renewal safety net. After the response is sent, reconcile the
 * signed-in user's current workspace subscription against its gateway, so an
 * auto-renewal charge applies even when the merchant never wired up the
 * gateway webhook (the "auto-renewed but still on Free" report).
 *
 * All the real cost control lives in SubscriptionReconciler (only polls a
 * pending/near-expiry subscription, once per few hours). This middleware just
 * triggers it off normal web traffic — deliberately in terminate() so it never
 * adds latency to the page the user is waiting on.
 */
class ReconcileSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! Auth::hasUser()) {
                return;
            }
            $wsId = (int) (Auth::user()->current_workspace_id ?? 0);
            if ($wsId <= 0) {
                return;
            }
            app(SubscriptionReconciler::class)->reconcileWorkspace($wsId);
        } catch (\Throwable $e) {
            // Never let a billing reconcile break a request that already
            // succeeded — the webhook path remains as the primary mechanism.
            Log::warning('[SUBRECON] middleware terminate failed: ' . $e->getMessage());
        }
    }
}
