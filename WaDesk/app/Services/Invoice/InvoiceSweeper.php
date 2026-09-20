<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort, traffic-driven delivery sweep — NO cron, NO queue. Fired from the
 * Node heartbeat / an ordinary web request; cache-gated to a 5-min interval.
 * Processes a small batch of not-yet-sent invoices (render if needed + send).
 * Cutoff at 5 attempts / 24h → left send_failed, shown as "needs attention".
 * A failing PDF render is parked, never looped.
 */
class InvoiceSweeper
{
    private const INTERVAL = 300;  // 5 min
    private const BATCH = 15;

    public function maybeRun(): void
    {
        if (! Cache::add('invoice_sweep_at', 1, self::INTERVAL)) {
            return; // ran within the last 5 min
        }
        try {
            $this->run();
        } catch (\Throwable $e) {
            Log::warning('invoice.sweep_failed', ['err' => $e->getMessage()]);
        }
    }

    public function run(): int
    {
        $svc = app(InvoiceService::class);
        $done = 0;

        // 1) Issue own-store invoices for native paid orders (auto_send_own on) that
        //    don't have one yet — traffic-driven, gap-fills what a webhook can't
        //    (native orders have no external webhook). Bounded batch, recent only.
        $native = ['storefront', 'waba', 'twilio', 'whatsapp_ai', 'manual'];
        $wsIds = \App\Models\InvoiceSetting::where('auto_send_own', true)->pluck('workspace_id');
        if ($wsIds->isNotEmpty()) {
            $orders = \App\Models\WaOrder::whereIn('workspace_id', $wsIds)
                ->whereIn('source', $native)
                ->whereIn('status', ['paid', 'completed'])
                ->where('updated_at', '>', now()->subDay())
                ->whereDoesntHave('invoice')
                ->orderByDesc('id')->limit(self::BATCH)->get();
            foreach ($orders as $o) {
                try {
                    $svc->handleWebhookOrder($o, 'own', null, 'own_store');
                } catch (\Throwable $e) {
                    Log::warning('invoice.sweep_own_issue_failed', ['order' => $o->id, 'err' => $e->getMessage()]);
                }
            }
        }

        // 2) Render + send pending/ready/failed invoices under the retry cutoff.
        $rows = Invoice::whereIn('send_status', [Invoice::SEND_PENDING, Invoice::SEND_READY, Invoice::SEND_FAILED])
            ->where('send_attempts', '<', 5)
            ->whereNull('sent_at')
            ->where('issued_at', '>', now()->subDay())
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($rows as $inv) {
            try {
                $svc->renderAndSend($inv);
                $done++;
            } catch (\Throwable $e) {
                Log::warning('invoice.sweep_item_failed', ['id' => $inv->id, 'err' => $e->getMessage()]);
            }
        }

        return $done;
    }
}
