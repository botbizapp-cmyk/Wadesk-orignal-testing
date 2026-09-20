<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public payment callback for booking deposits — the gateway redirects the
 * customer here after paying. Self-contained: verifies via the driver, marks
 * the Order paid, then commits the booking through BookingApiController
 * (hard UNIQUE guard + paid-but-slot-gone refund branch). No package checkout
 * involved. GET + POST (gateways differ).
 */
class BookingPaymentController extends Controller
{
    public function __construct(private readonly PaymentGatewayManager $manager) {}

    public function callback(string $gateway, Request $request)
    {
        $gw = PaymentGateway::where('slug', $gateway)->first();
        if (! $gw) {
            return $this->result('error', __('Unknown payment provider.'));
        }
        $driver  = $this->manager->driverFromModel($gw);
        $payload = array_merge($request->query() ?: [], $request->post() ?: []);

        // Resolve the order: prefer our own ?bo= param (session-less, cross-site
        // safe), then the gateway's own order id echoed back.
        $order = null;
        if (ctype_digit((string) $request->query('bo'))) {
            $order = Order::find((int) $request->query('bo'));
        }
        if (! $order) {
            $hint = $payload['razorpay_order_id'] ?? $payload['token'] ?? $payload['txnid']
                ?? $payload['merchantTransactionId'] ?? $payload['merchantOrderId'] ?? $payload['session_id'] ?? null;
            if ($hint) {
                $order = Order::where('gateway_order_id', $hint)->first();
            }
        }
        if (! $order) {
            return $this->result('error', __('We could not find that booking payment.'));
        }

        try {
            $res = $driver->handleCallback($payload);
        } catch (\Throwable $e) {
            Log::warning('[BOOKING-PAY] handleCallback failed order='.$order->id.': '.$e->getMessage());

            return $this->result('error', __('We could not verify the payment. If you were charged, contact the business.'));
        }

        if ($res->gatewayOrderId) {
            $order->update([
                'gateway_order_id' => $res->gatewayOrderId,
                'gateway_payload'  => array_merge((array) $order->gateway_payload, (array) $res->payload),
            ]);
        }

        if ($res->status === 'paid') {
            if ($order->status !== 'paid') {
                $order->forceFill([
                    'status'             => 'paid',
                    'paid_at'            => now(),
                    'gateway_payment_id' => $res->gatewayPaymentId,
                ])->save();
            }

            $confirm = app(BookingApiController::class)->confirmFromPayment($order, $driver);
            if (! empty($confirm['ok']) && ! empty($confirm['manage_token'])) {
                return redirect()->route('booking.manage.show', $confirm['manage_token'])
                    ->with('success', __('Payment received — your booking is confirmed.'));
            }
            if (($confirm['error'] ?? '') === 'slot_taken') {
                return $this->result('taken', ! empty($confirm['refunded'])
                    ? __('Your payment went through, but that time was just taken — a refund has been issued. Please book again.')
                    : __('Your payment went through, but that time was just taken. The business will refund you and reach out to rebook.'));
            }

            return $this->result('error', __('Payment received but the booking could not be finalised. The business will contact you.'));
        }

        if ($res->status === 'failed') {
            $order->forceFill(['status' => 'failed', 'failure_reason' => $res->error])->save();

            return $this->result('failed', __('Payment failed. Please try again.'));
        }

        // Pending / needs another redirect.
        if ($res->redirectUrl) {
            return redirect()->away($res->redirectUrl);
        }

        return $this->result('pending', __('Your payment is being processed. You will get a confirmation shortly.'));
    }

    private function result(string $state, string $message)
    {
        return response()->view('public.booking-manage.payment-result', compact('state', 'message'));
    }
}
