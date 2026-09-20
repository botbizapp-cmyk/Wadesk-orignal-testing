<?php

namespace App\Services\Payment;

use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhook-INDEPENDENT renewal safety net.
 *
 * Auto-renewals normally arrive as a gateway webhook (subscription.charged →
 * CheckoutController::webhook). But that depends on the merchant registering the
 * webhook URL + secret in the gateway dashboard. When they don't, the card is
 * charged at the gateway yet the workspace silently drops to Free — the reported
 * "auto-renewed but still Free" bug.
 *
 * This service polls the gateway directly for a subscription that is at/near the
 * end of its paid window and pushes the workspace plan forward if the gateway
 * has already taken the next payment. It is driven by ordinary web traffic (a
 * terminate() middleware), NOT a scheduler/cron — so it works on every install
 * regardless of whether `php artisan schedule:run` is wired up.
 *
 * Cost control: it only polls when a subscription is actually interesting
 * (pending / past_due, or an active plan within LOOKAHEAD_DAYS of expiry) and at
 * most once per THROTTLE_HOURS per subscription (a cache flag). A healthy plan
 * mid-cycle costs nothing.
 */
class SubscriptionReconciler
{
    /** Poll a gateway subscription at most once per this many hours. */
    private const THROTTLE_HOURS = 6;

    /** Start polling when the plan is within this many days of lapsing. */
    private const LOOKAHEAD_DAYS = 3;

    public function __construct(
        private PaymentGatewayManager $gateways,
        private SubscriptionService $subs,
    ) {}

    /**
     * Reconcile every renewable subscription attached to a workspace. Safe to
     * call on any request — fully guarded, never throws.
     */
    public function reconcileWorkspace(int $workspaceId): void
    {
        try {
            $rows = Subscription::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [
                    Subscription::STATUS_PENDING,
                    Subscription::STATUS_ACTIVE,
                    Subscription::STATUS_PAST_DUE,
                ])
                ->get();
            foreach ($rows as $sub) {
                $this->reconcile($sub);
            }
        } catch (\Throwable $e) {
            Log::warning('[SUBRECON] reconcileWorkspace failed: ' . $e->getMessage(), ['workspace_id' => $workspaceId]);
        }
    }

    /**
     * Reconcile one subscription against its gateway. Returns a short outcome
     * code (also handy for tests): skip_* when nothing was done, renewed /
     * up_to_date / gateway_canceled / gateway_expired / no_gateway_sub otherwise.
     */
    public function reconcile(Subscription $sub): string
    {
        if (! in_array($sub->status, [
            Subscription::STATUS_PENDING,
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAST_DUE,
        ], true)) {
            return 'skip_terminal';
        }

        $ws = Workspace::find($sub->workspace_id);
        if (! $ws) {
            return 'skip_no_ws';
        }

        // Only worth polling when the plan is pending/past_due or close to
        // lapsing — a healthy active plan mid-cycle needs no gateway call.
        $endsAt = $ws->plan_ends_at;
        $interesting = $sub->status === Subscription::STATUS_PENDING
            || $sub->status === Subscription::STATUS_PAST_DUE
            || $endsAt === null
            || $endsAt->lessThanOrEqualTo(now()->addDays(self::LOOKAHEAD_DAYS));
        if (! $interesting) {
            return 'skip_healthy';
        }

        // One gateway poll per subscription per window, even across concurrent
        // requests. add() is atomic so two requests can't both poll.
        $throttleKey = 'subrecon:' . $sub->id;
        if (! Cache::add($throttleKey, 1, now()->addHours(self::THROTTLE_HOURS))) {
            return 'skip_throttled';
        }

        $subId = trim((string) ($sub->gateway_subscription_id ?? ''));
        if ($subId === '') {
            // No recurring mandate at the gateway — this was a one-time charge,
            // so nothing can auto-renew it. Surfaced so these are easy to spot.
            Log::info('[SUBRECON] no gateway subscription id — one-time purchase, cannot auto-renew', [
                'subscription_id' => $sub->id,
                'workspace_id'    => $sub->workspace_id,
                'gateway'         => $sub->gateway,
            ]);
            return 'no_gateway_sub';
        }

        try {
            $driver = $this->gateways->driver($sub->gateway);
        } catch (\Throwable $e) {
            return 'skip_no_driver';
        }

        $state = $driver->fetchSubscription($subId);
        if (! is_array($state)) {
            // Driver can't poll (default) or the API call failed — the webhook
            // path stays the mechanism for this gateway.
            return 'skip_poll_failed';
        }

        $status    = (string) ($state['status'] ?? '');
        $periodEnd = $state['period_end'] ?? null;   // unix ts | ISO | null

        // Gateway says the mandate is dead — reflect it locally and let the
        // already-paid window lapse naturally (do NOT shorten it here).
        if (in_array($status, ['canceled', 'expired'], true)) {
            $target = $status === 'expired' ? Subscription::STATUS_EXPIRED : Subscription::STATUS_CANCELED;
            if ($sub->status !== $target) {
                $sub->status = $target;
                if ($status === 'canceled' && ! $sub->canceled_at) {
                    $sub->canceled_at = now();
                }
                $sub->save();
            }
            return 'gateway_' . $status;
        }

        // active / past_due — apply the next paid period only when the gateway
        // is genuinely AHEAD of our window (guards against bumping the plan or
        // renewals_count on a poll that tells us nothing new).
        $gatewayEnd = $this->toCarbon($periodEnd);
        if ($gatewayEnd && (! $endsAt || $gatewayEnd->greaterThan($endsAt))) {
            if ($sub->status === Subscription::STATUS_PENDING) {
                // First charge that never confirmed via callback/webhook.
                $this->subs->activate($sub, ['current_period_end' => $periodEnd]);
            } else {
                $this->subs->renew($sub, $periodEnd);
            }
            Log::info('[SUBRECON] renewal reconciled from gateway (no webhook needed)', [
                'subscription_id' => $sub->id,
                'workspace_id'    => $sub->workspace_id,
                'gateway'         => $sub->gateway,
                'new_period_end'  => $gatewayEnd->toDateTimeString(),
            ]);
            return 'renewed';
        }

        return 'up_to_date';
    }

    private function toCarbon($value): ?\Illuminate\Support\Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return is_numeric($value)
                ? \Illuminate\Support\Carbon::createFromTimestamp((int) $value)
                : \Illuminate\Support\Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
