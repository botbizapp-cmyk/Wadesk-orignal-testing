<?php

namespace App\Events\Commerce;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A CUSTOMER's WaOrder was confirmed PAID (native-checkout webhook, any of the 30
 * merchant gateways). Fired ONCE — markPaid() is idempotent. Step 5 listeners use
 * it to: resume the paused checkout flow, send the receipt on the right engine
 * (WABA / Baileys), and push the order status to Shopify/WooCommerce.
 */
class WaOrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $orderId,
        public int $workspaceId,
        public string $gatewaySlug = '',
    ) {}
}
