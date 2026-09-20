<?php

namespace App\Http\Controllers;

use App\Models\WaOrder;
use App\Services\Checkout\CustomerPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public "thank you" page the CUSTOMER lands on after paying:
 *   GET /wa/checkout/return/{slug}/{token}
 *
 * Confirms the payment server-side (works even when the webhook can't reach the
 * host — LAN/dev) and shows a proper Payment-successful / pending / cancelled
 * page instead of dumping the buyer on the app home. No auth — resolved by the
 * order's unguessable recovery_token.
 */
class CustomerCheckoutReturnController extends Controller
{
    public function __construct(private CustomerPaymentService $payments) {}

    public function handle(Request $request, string $slug, string $token): View
    {
        $order = WaOrder::query()->where('recovery_token', $token)->first();
        if (!$order) {
            return view('checkout.return', ['state' => 'notfound', 'order' => null]);
        }

        // Customer explicitly cancelled on the gateway (…?cancelled=1).
        if ($request->boolean('cancelled') && $order->status !== 'paid') {
            return view('checkout.return', ['state' => 'cancelled', 'order' => $order]);
        }

        try {
            $res = $this->payments->confirmFromReturn($order, $request->query());
            Log::info('[WA-CHECKOUT-RETURN] confirm', ['order' => $order->id, 'slug' => $slug] + $res);
        } catch (\Throwable $e) {
            Log::warning('[WA-CHECKOUT-RETURN] confirm threw', ['order' => $order->id, 'err' => $e->getMessage()]);
        }

        $order->refresh();
        $state = $order->status === 'paid' ? 'paid' : 'pending';
        return view('checkout.return', ['state' => $state, 'order' => $order]);
    }
}
