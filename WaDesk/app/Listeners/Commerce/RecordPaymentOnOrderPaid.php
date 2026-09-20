<?php

namespace App\Listeners\Commerce;

use App\Events\Commerce\WaOrderPaid;
use App\Models\WaOrder;
use App\Services\Crm\PaymentLedger;
use Illuminate\Support\Facades\Log;

/**
 * AI-CRM Phase 2.2 bridge — when a customer's WaOrder is confirmed PAID (native
 * checkout / any gateway / WA-Pay), write a Payment ledger row and flip the
 * linked invoice to paid. Auto-discovered by Laravel via the handle(WaOrderPaid)
 * type-hint (do NOT also Event::listen it, per AppServiceProvider). Idempotent —
 * PaymentLedger::record de-dupes on (workspace, source, gateway_payment_id), so a
 * webhook retry can never double-count.
 */
class RecordPaymentOnOrderPaid
{
    public function __construct(private PaymentLedger $ledger) {}

    public function handle(WaOrderPaid $event): void
    {
        try {
            $order = WaOrder::where('workspace_id', $event->workspaceId)->find($event->orderId);
            if (! $order) {
                return;
            }
            $meta  = is_array($order->meta_json) ? $order->meta_json : [];
            $waTxn = (string) ($order->wa_payment_txn_id ?? '');
            $gwId  = (string) ($meta['gateway_payment_id'] ?? '') ?: ($waTxn ?: null);
            $source = $waTxn !== '' ? 'wa_pay' : 'gateway';

            // AUTO-INVOICE — a paid native order (checkout / gateway) gets its
            // invoice generated NOW instead of waiting on the delayed sweeper poll,
            // matching how Shopify/Woo issue on their paid webhook. handleWebhookOrder
            // gates on auto_send_own + the auto_invoice plan feature and dedups, so
            // it's a no-op when off/already-issued; issue-only, the sweeper renders +
            // sends the PDF. Best-effort — must never break the checkout flow.
            try {
                if (method_exists($order, 'invoice') && ! $order->invoice()->exists()) {
                    app(\App\Services\Invoice\InvoiceService::class)
                        ->handleWebhookOrder($order, 'own', null, 'own_store');
                }
            } catch (\Throwable $e) {
                Log::warning('[AUTO-INVOICE] paid-order issue failed: ' . $e->getMessage());
            }

            // Link the invoice generated for this order, if any (hasOne latestOfMany).
            $invoice = method_exists($order, 'invoice') ? $order->invoice()->first() : null;

            $this->ledger->record((int) $event->workspaceId, [
                'amount_minor'       => (int) ($order->total_minor ?? 0),
                'currency'           => strtoupper((string) ($order->currency_code ?? 'USD')) ?: 'USD',
                'method'             => $source,
                'source'             => $source,
                'paid_at'            => $order->updated_at ?? now(),
                'reference'          => (string) ($meta['gateway_payment_id'] ?? $waTxn) ?: null,
                'gateway_payment_id' => $gwId,
                'invoice_id'         => $invoice?->id,
                'wa_order_id'        => $order->id,
                'contact_id'         => $order->contact_id ?? null,
                'recorded_by'        => null, // system
                'meta_json'          => ['paid_via' => $meta['paid_via'] ?? $event->gatewaySlug],
            ]);
        } catch (\Throwable $e) {
            // A ledger-write failure must never break the checkout/receipt flow.
            Log::warning('[CRM-PAYMENT] order-paid bridge failed: ' . $e->getMessage());
        }
    }
}
