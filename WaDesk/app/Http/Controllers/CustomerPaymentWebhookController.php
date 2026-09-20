<?php

namespace App\Http\Controllers;

use App\Services\Checkout\CustomerPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Native-checkout CUSTOMER payment webhook — one endpoint per gateway slug:
 *   POST /wa/checkout/webhook/{slug}
 *
 * The gateway (the MERCHANT's own account) calls this when a customer pays for a
 * WaOrder. No session — authed by the driver's per-merchant HMAC signature.
 * Always returns 200 (even on a miss) so the gateway doesn't retry-storm; the
 * response body carries the real outcome for debugging.
 */
class CustomerPaymentWebhookController extends Controller
{
    public function __construct(private CustomerPaymentService $payments) {}

    public function handle(Request $request, string $slug): JsonResponse
    {
        $slug    = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $slug));
        $rawBody = (string) $request->getContent();

        $order = $this->payments->resolveOrderFromWebhook($slug, $rawBody);
        if (!$order) {
            Log::info('[WA-CHECKOUT-WEBHOOK] order not resolved', ['slug' => $slug, 'len' => strlen($rawBody)]);
            return response()->json(['ok' => false, 'reason' => 'order_not_resolved'], 200);
        }

        // Same per-gateway signature-header chain the platform CheckoutController
        // uses — each driver reads only the header it expects; the rest are null.
        $sig = $request->header('Stripe-Signature')
            ?? $request->header('X-Razorpay-Signature')
            ?? $request->header('Paypal-Transmission-Sig')
            ?? $request->header('x-paystack-signature')
            ?? $request->header('X-CC-Webhook-Signature')
            ?? $request->header('x-callback-token')
            ?? $request->header('x-square-hmacsha256-signature')
            ?? $request->header('verif-hash');

        try {
            $res = $this->payments->applyWebhook($order, $slug, $rawBody, $sig);
        } catch (\Throwable $e) {
            Log::error('[WA-CHECKOUT-WEBHOOK] apply failed', ['slug' => $slug, 'order' => $order->id, 'err' => $e->getMessage()]);
            return response()->json(['ok' => false, 'reason' => 'exception'], 200);
        }

        Log::info('[WA-CHECKOUT-WEBHOOK] result', ['slug' => $slug, 'order' => $order->id] + $res);
        return response()->json($res, 200);
    }
}
