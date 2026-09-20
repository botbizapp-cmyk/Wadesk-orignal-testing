<?php

namespace App\Listeners\Commerce;

use App\Events\Commerce\WaOrderPaid;
use App\Models\WaOrder;
use App\Services\Checkout\CheckoutDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Reacts to a confirmed native-checkout payment (WaOrderPaid):
 *   1. Sends the customer a receipt on the workspace's engine (WABA / Baileys).
 *   2. Best-effort pushes the "paid" status to the source store (WooCommerce /
 *      Shopify) so the merchant's own dashboard reflects it.
 *
 * Runs synchronously (there is no queue worker in this deployment — the same
 * reason broadcast events use ShouldBroadcastNow). Every step is guarded so a
 * receipt/sync failure never breaks the paid flip.
 */
class SendCheckoutReceipt
{
    public function __construct(private CheckoutDelivery $delivery) {}

    public function handle(WaOrderPaid $event): void
    {
        $order = WaOrder::find($event->orderId);
        if (!$order) return;

        // 1) Receipt to the customer.
        try {
            $this->delivery->sendReceipt($order);
        } catch (\Throwable $e) {
            Log::warning('[WA-CHECKOUT] receipt failed', ['order' => $order->id, 'err' => $e->getMessage()]);
        }

        // 2) Best-effort store status sync — never blocks the paid flip.
        $this->syncStoreStatus($order);
    }

    private function syncStoreStatus(WaOrder $order): void
    {
        try {
            if ($order->woo_order_id) {
                $integration = \App\Models\WoocommerceIntegration::query()
                    ->where('workspace_id', $order->workspace_id)->first();
                if ($integration) {
                    app(\App\Services\Woocommerce\WoocommerceService::class)
                        ->updateOrderStatus($integration, $order->woo_order_id, 'processing');
                    Log::info('[WA-CHECKOUT] woo status → processing', ['order' => $order->id, 'woo' => $order->woo_order_id]);
                }
            }
            if ($order->shopify_order_id) {
                // Shopify's REST order-paid transition is a separate mutation not
                // yet wired here — log so the merchant sync gap is visible.
                Log::info('[WA-CHECKOUT] shopify order paid (dashboard sync pending)', [
                    'order' => $order->id, 'shopify' => $order->shopify_order_id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[WA-CHECKOUT] store status sync failed', ['order' => $order->id, 'err' => $e->getMessage()]);
        }
    }
}
