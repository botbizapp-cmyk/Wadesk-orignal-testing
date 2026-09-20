<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\User;
use App\Models\WaMerchantGateway;
use App\Models\WaOrder;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Str;

/**
 * Turns a CUSTOMER's WaOrder into a hosted checkout URL on the MERCHANT's own
 * gateway account — for ANY of the 30 payment drivers, with ZERO driver changes.
 *
 * How it works (verified by audit):
 *   - Drivers only READ from an `Order` (they never ->save() it; the CALLER
 *     persists gateway_order_id after initiate — see CheckoutController), so a
 *     TRANSIENT Order adapter built from the WaOrder satisfies the contract.
 *   - The driver is constructed from a TRANSIENT PaymentGateway carrying the
 *     MERCHANT's creds (WaMerchantGateway::toTransientPaymentGateway), so the
 *     money lands in the merchant's account, not the platform's.
 *
 * Engine-agnostic: WABA (native Flow) and Unofficial/Baileys (pay-link) checkout
 * both call createCheckout(); only the in-chat DELIVERY of the URL differs.
 */
class CustomerPaymentService
{
    public function __construct(private PaymentGatewayManager $gateways) {}

    /**
     * Merchant gateway config for this order + slug. Prefers a storefront-specific
     * row, then the workspace-wide (storefront_id=0) default. Null when the
     * merchant hasn't configured usable creds for this gateway.
     */
    public function resolveMerchantGateway(WaOrder $order, string $slug): ?WaMerchantGateway
    {
        $sfId = (int) ($order->storefront_id ?? 0);
        $base = fn () => WaMerchantGateway::query()->active()
            ->where('workspace_id', $order->workspace_id)
            ->where('slug', $slug);

        $row = $base()->where('storefront_id', $sfId)->first()
            ?: $base()->where('storefront_id', 0)->first();

        return ($row && $row->isConfigured()) ? $row : null;
    }

    /** Gateway slugs this order can actually be paid with (configured merchant creds). */
    public function availableGateways(WaOrder $order): array
    {
        $sfId = (int) ($order->storefront_id ?? 0);
        return WaMerchantGateway::query()->active()
            ->where('workspace_id', $order->workspace_id)
            ->whereIn('storefront_id', [$sfId, 0])
            ->orderByDesc('storefront_id')   // storefront-specific wins over the 0 default
            ->orderBy('sort_order')
            ->get()
            ->filter->isConfigured()
            ->unique('slug')                 // keep the preferred row per gateway
            ->pluck('slug')->values()->all();
    }

    /**
     * Create the hosted checkout on the merchant's gateway + stamp the reference
     * onto the WaOrder so the webhook can reverse-map it. Returns the driver's
     * PaymentResult (redirect URL on success).
     */
    public function createCheckout(WaOrder $order, string $slug, string $callbackUrl): PaymentResult
    {
        $merchant = $this->resolveMerchantGateway($order, $slug);
        if (!$merchant) {
            return PaymentResult::failed('gateway_not_configured_for_merchant');
        }

        try {
            $driver = $this->gateways->driverFromModel($merchant->toTransientPaymentGateway());
        } catch (\Throwable $e) {
            return PaymentResult::failed('driver_unavailable: ' . $e->getMessage());
        }

        $adapter = $this->orderAdapter($order);
        $result  = $driver->initiate($adapter, $callbackUrl);

        // The driver never writes to the order (verified) — persist the reference
        // here so the customer webhook can resolve THIS WaOrder later.
        $meta = is_array($order->meta_json) ? $order->meta_json : [];
        $meta['gateway_slug'] = $slug;
        $meta['payment_ref']  = $adapter->order_number;
        if ($result->gatewayOrderId)   $meta['gateway_order_id']   = $result->gatewayOrderId;
        if ($result->gatewayPaymentId) $meta['gateway_payment_id'] = $result->gatewayPaymentId;

        $patch = ['meta_json' => $meta, 'payment_method' => $slug];
        if ($result->redirectUrl) $patch['payment_link'] = $result->redirectUrl;
        $order->forceFill($patch)->save();

        return $result;
    }

    /**
     * Resolve which WaOrder a raw webhook body belongs to WITHOUT trusting it yet
     * (signature is verified afterwards). Breaks the chicken-and-egg — we need the
     * order to know the workspace to load the merchant creds to verify the sig:
     *   1) our echoed reference "WAO-<id>-<rand>" (order_number we sent the gateway);
     *   2) fallback — a stored payment_ref / gateway_order_id that appears verbatim
     *      in the body, scanned over recent unpaid orders on this gateway.
     */
    public function resolveOrderFromWebhook(string $slug, string $rawBody): ?WaOrder
    {
        if (preg_match('/WAO-(\d+)-[A-Z0-9]+/', $rawBody, $m)) {
            $o = WaOrder::find((int) $m[1]);
            if ($o) return $o;
        }
        $candidates = WaOrder::query()
            ->where('status', '!=', 'paid')
            ->where('payment_method', $slug)
            ->whereNotNull('meta_json')
            ->latest('id')->limit(200)->get();
        foreach ($candidates as $o) {
            $ref = (string) data_get($o->meta_json, 'payment_ref', '');
            $gid = (string) data_get($o->meta_json, 'gateway_order_id', '');
            if (($ref !== '' && str_contains($rawBody, $ref)) || ($gid !== '' && str_contains($rawBody, $gid))) {
                return $o;
            }
        }
        return null;
    }

    /**
     * Verify + apply a customer payment webhook. Builds the driver with the
     * MERCHANT's creds, fail-closes on a bad signature, and marks the WaOrder paid
     * only when the driver reports status=paid. Idempotent. Returns a small status
     * array (never throws to the gateway).
     */
    public function applyWebhook(WaOrder $order, string $slug, string $rawBody, ?string $sigHeader): array
    {
        $merchant = $this->resolveMerchantGateway($order, $slug);
        if (!$merchant) return ['ok' => false, 'reason' => 'no_merchant_gateway'];

        try {
            $driver = $this->gateways->driverFromModel($merchant->toTransientPaymentGateway());
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'driver_unavailable'];
        }

        // Fail-closed: the driver's per-gateway HMAC must pass. Drivers that have
        // no signature scheme return true by default — the reference-scan +
        // gateway_order_id cross-check below is the second layer for those.
        if (!$driver->verifyWebhookSignature($rawBody, $sigHeader)) {
            return ['ok' => false, 'reason' => 'signature_mismatch'];
        }

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $result  = $driver->handleWebhook($payload);

        if (($result->status ?? '') !== 'paid') {
            return ['ok' => true, 'status' => $result->status ?? 'pending'];
        }

        // Cross-check: the paid event must reference the SAME gateway order we
        // recorded at createCheckout (guards against a forged reference pointing
        // the webhook at a different order). Skipped when we never stored one.
        $storedGid = (string) data_get($order->meta_json, 'gateway_order_id', '');
        if ($storedGid !== '' && $result->gatewayOrderId && (string) $result->gatewayOrderId !== $storedGid) {
            return ['ok' => false, 'reason' => 'gateway_order_id_mismatch'];
        }

        $this->markPaid($order, $slug, (string) ($result->gatewayPaymentId ?? ''));
        return ['ok' => true, 'status' => 'paid', 'order_id' => $order->id];
    }

    /**
     * Confirm a payment when the CUSTOMER is redirected back from the gateway
     * (return URL) — a fallback/complement to the webhook that also works when
     * the webhook can't reach the server (e.g. a LAN/dev host). Verifies with the
     * gateway server-side (outbound), then marks paid. Idempotent.
     *
     * Two confirmation paths cover all drivers:
     *   1. handleCallback($returnQuery) — Stripe reads session_id from the return
     *      query; many gateways echo their status in the redirect params.
     *   2. verify($order) — the drivers that implement a "is this order paid?"
     *      server call (Paystack, Flutterwave, Mollie, Square, …), keyed on the
     *      gateway_order_id we stored at createCheckout.
     */
    public function confirmFromReturn(WaOrder $order, array $returnQuery): array
    {
        if ($order->status === 'paid') return ['ok' => true, 'status' => 'paid', 'already' => true];

        $slug = (string) (data_get($order->meta_json, 'gateway_slug') ?: $order->payment_method);
        if ($slug === '') return ['ok' => false, 'reason' => 'no_gateway_on_order'];

        $merchant = $this->resolveMerchantGateway($order, $slug);
        if (!$merchant) return ['ok' => false, 'reason' => 'no_merchant_gateway'];

        try {
            $driver = $this->gateways->driverFromModel($merchant->toTransientPaymentGateway());
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'driver_unavailable'];
        }

        // Adapter carries the stored gateway ids so verify() can look the txn up.
        $adapter = $this->orderAdapter($order);
        $adapter->gateway_order_id   = (string) data_get($order->meta_json, 'gateway_order_id', '');
        $adapter->gateway_payment_id = (string) data_get($order->meta_json, 'gateway_payment_id', '');

        $result = null;
        try { $result = $driver->handleCallback($returnQuery); } catch (\Throwable $e) { /* try verify */ }
        if (($result->status ?? '') !== 'paid') {
            try {
                $v = $driver->verify($adapter);
                if (($v->status ?? '') === 'paid') $result = $v;
            } catch (\Throwable $e) { /* stays pending */ }
        }

        if (($result->status ?? '') !== 'paid') {
            return ['ok' => true, 'status' => $result->status ?? 'pending'];
        }

        // Same cross-check as the webhook: the confirmed txn must reference the
        // gateway order we recorded (guards a forged return against another order).
        $stored = (string) data_get($order->meta_json, 'gateway_order_id', '');
        if ($stored !== '' && $result->gatewayOrderId && (string) $result->gatewayOrderId !== $stored) {
            return ['ok' => false, 'reason' => 'gateway_order_id_mismatch'];
        }

        $this->markPaid($order, $slug, (string) ($result->gatewayPaymentId ?? ''));
        return ['ok' => true, 'status' => 'paid'];
    }

    /**
     * Flip a WaOrder to paid (idempotent) + fire WaOrderPaid for Step-5 listeners
     * (resume flow / receipt / Shopify-Woo push). The ONLY place a customer order
     * becomes paid via the native checkout.
     */
    public function markPaid(WaOrder $order, string $slug, string $gatewayPaymentId = ''): WaOrder
    {
        if ($order->status === 'paid') return $order; // idempotent — never double-fire

        $meta = is_array($order->meta_json) ? $order->meta_json : [];
        $meta['paid_via'] = $slug;
        $meta['paid_at']  = now()->toIso8601String();
        if ($gatewayPaymentId !== '') $meta['gateway_payment_id'] = $gatewayPaymentId;

        $order->forceFill(['status' => 'paid', 'meta_json' => $meta])->save();

        event(new \App\Events\Commerce\WaOrderPaid((int) $order->id, (int) $order->workspace_id, $slug));
        return $order;
    }

    /**
     * A TRANSIENT Order that satisfies every field the 30 drivers read
     * (order_number, amount, currency, id, workspace_id, user->{email,phone},
     * the customer_ and billing_ fields, package[null → optional()->pname
     * fallback]). Never saved — the customer payment ledger stays on the
     * WaOrder, not the SaaS `orders` table.
     */
    public function orderAdapter(WaOrder $order): Order
    {
        $addr  = is_array($order->customer_address) ? $order->customer_address : [];
        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone);
        // Some gateways require a non-empty payer email — synthesise a safe one
        // when the customer didn't give theirs (never a real inbox).
        $email = trim((string) $order->customer_email) ?: ('wa' . ($phone ?: 'guest') . '@no-email.invalid');

        // Reference the gateway echoes back — unique per attempt, tied to the
        // WaOrder for reverse lookup on the webhook.
        $ref = 'WAO-' . $order->id . '-' . Str::upper(Str::random(6));

        $o = new Order();
        $o->id             = $order->id;                 // meaningful in gateway metadata
        $o->order_number   = $ref;
        $o->workspace_id   = $order->workspace_id;
        $o->amount         = round(((int) $order->total_minor) / 100, 2);
        $o->currency       = strtoupper((string) $order->currency_code) ?: 'USD';
        $o->customer_name  = (string) $order->customer_name;
        $o->customer_email = $email;
        $o->email          = $email;                     // one driver reads $order->email
        $o->billing_country = (string) ($addr['country'] ?? '');
        $o->billing_city    = (string) ($addr['city'] ?? '');
        $o->billing_postal  = (string) ($addr['postal'] ?? $addr['zip'] ?? '');
        $o->billing_address = (string) ($addr['line1'] ?? $addr['address'] ?? '');
        $o->exists = false;                              // MUST never be written to `orders`

        // Payer stub — drivers read optional($order->user)->{email,phone_number,…}.
        $u = new User();
        $u->name         = (string) $order->customer_name;
        $u->email        = $email;
        $u->phone_number = (string) $order->customer_phone;
        $u->phone        = (string) $order->customer_phone;
        $u->mobile       = (string) $order->customer_phone;
        $u->exists = false;
        $o->setRelation('user', $u);
        $o->setRelation('package', null);                // optional($order->package)->pname → null-safe

        return $o;
    }
}
