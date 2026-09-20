<?php

namespace App\Bsp\Services;

use App\Models\MessageCharge;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Admin reseller P&L, read straight from the per-message billing ledger
 * (message_charges), written by MessageBillingService at delivery time.
 *
 *   revenue = SUM(revenue_minor) over charged rows       (what customers paid)
 *   cost    = SUM(cost_minor)    over charged rows        (what Meta bills us)
 *   margin  = revenue − cost
 *
 * Refunded messages are excluded from revenue (status filter), so failed sends
 * never inflate the numbers. All amounts are platform-currency MINOR units.
 */
class ReportService
{
    private function charged(Carbon $from, Carbon $to)
    {
        return MessageCharge::where('status', MessageCharge::STATUS_CHARGED)
            ->whereBetween('created_at', [$from, $to]);
    }

    /** Platform-wide totals for a date window (defaults to this month). */
    public function platformTotals(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to   ??= now();

        $agg = $this->charged($from, $to)->selectRaw('
            COUNT(*) AS billed,
            COALESCE(SUM(revenue_minor),0) AS revenue,
            COALESCE(SUM(cost_minor),0)    AS cost
        ')->first();

        // Messages that delivered free (rate 0, quota, or empty wallet) — still
        // count toward "no Meta cost captured" awareness.
        $free = MessageCharge::where('status', MessageCharge::STATUS_FREE)
            ->whereBetween('created_at', [$from, $to])->count();
        $unpriced = $this->charged($from, $to)->whereNull('cost_minor')->count();

        return [
            'from'          => $from,
            'to'            => $to,
            'messages'      => (int) ($agg->billed ?? 0),
            'free_msgs'     => (int) $free,
            'revenue_minor' => (int) ($agg->revenue ?? 0),
            'cost_minor'    => (int) ($agg->cost ?? 0),
            'margin_minor'  => (int) (($agg->revenue ?? 0) - ($agg->cost ?? 0)),
            'unpriced_msgs' => (int) $unpriced,
            'wallet_float_minor' => (int) User::query()->sum('wallet_currency_minor'),
            'wallet_credits_out' => (int) User::query()->sum('wallet_credits'),
        ];
    }

    /** Per-workspace P&L rows for the window, top spenders first. */
    public function perWorkspace(?Carbon $from = null, ?Carbon $to = null, int $limit = 100): array
    {
        $from ??= now()->startOfMonth();
        $to   ??= now();

        $rowsRaw = $this->charged($from, $to)
            ->selectRaw('
                workspace_id,
                COUNT(*) AS messages,
                COALESCE(SUM(revenue_minor),0) AS revenue,
                COALESCE(SUM(cost_minor),0)    AS cost
            ')
            ->groupBy('workspace_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $wsNames = Workspace::whereIn('id', $rowsRaw->pluck('workspace_id')->all())
            ->pluck('name', 'id');
        $credits = Workspace::whereIn('id', $rowsRaw->pluck('workspace_id')->all())
            ->get(['id', 'owner_user_id'])
            ->mapWithKeys(fn ($w) => [$w->id => (int) (User::whereKey($w->owner_user_id)->value('wallet_credits') ?? 0)]);

        return $rowsRaw->map(fn ($r) => [
            'workspace_id'   => (int) $r->workspace_id,
            'workspace_name' => $wsNames[$r->workspace_id] ?? ('Workspace #' . $r->workspace_id),
            'messages'       => (int) $r->messages,
            'revenue_minor'  => (int) $r->revenue,
            'cost_minor'     => (int) $r->cost,
            'margin_minor'   => (int) ($r->revenue - $r->cost),
            'credits_left'   => (int) ($credits[$r->workspace_id] ?? 0),
        ])->all();
    }
}
