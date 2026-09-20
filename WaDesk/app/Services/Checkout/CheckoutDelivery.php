<?php

namespace App\Services\Checkout;

use App\Models\Message;
use App\Models\WaOrder;
use App\Models\Workspace;
use App\Services\WhatsAppDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Sends the native-checkout messages to the CUSTOMER on whatever engine the
 * workspace runs — WhatsAppDispatcher routes WABA / Unofficial(Baileys) / Twilio
 * by the workspace's engine, so BOTH engines are covered by construction:
 *   - sendCheckoutLink() : mint the hosted pay URL (any of the 30 merchant
 *     gateways) and deliver it as a message. This IS the Baileys checkout path
 *     (Baileys can't render a native Flow) and the WABA fallback.
 *   - sendReceipt()      : the "payment received" confirmation, fired from the
 *     WaOrderPaid listener after the webhook confirms payment.
 *
 * A native WABA Flow (in-chat cart Screens) is a later enhancement layered on top
 * of sendCheckoutLink(); the payment core underneath is identical.
 */
class CheckoutDelivery
{
    public function __construct(
        private CustomerPaymentService $payments,
        private WhatsAppDispatcher $dispatcher,
    ) {}

    /**
     * Create the hosted checkout on the merchant's gateway and send the pay link
     * to the customer. Returns ['ok'=>bool, 'url'=>?string, 'reason'=>?string].
     */
    public function sendCheckoutLink(WaOrder $order, string $slug, ?string $callbackUrl = null): array
    {
        $callbackUrl ??= $this->returnUrl($order, $slug);
        $result = $this->payments->createCheckout($order, $slug, $callbackUrl);

        if (!$result->redirectUrl) {
            return ['ok' => false, 'reason' => $result->error ?: 'no_checkout_url'];
        }

        $body = trim("Hi {$order->customer_name}, complete your payment for "
            . $this->amountLabel($order) . " here:\n\n{$result->redirectUrl}");

        $sent = $this->sendText($order, $body, 'checkout_link');
        return ['ok' => $sent, 'url' => $result->redirectUrl];
    }

    /** "Payment received" confirmation — the receipt after WaOrderPaid. */
    public function sendReceipt(WaOrder $order): bool
    {
        $ref  = 'WA-' . $order->id;
        $body = trim("Payment received — thank you, {$order->customer_name}! "
            . "Your order {$ref} for " . $this->amountLabel($order) . " is confirmed.");
        return $this->sendText($order, $body, 'receipt');
    }

    /** Outbound WhatsApp text to the order's customer via the workspace engine. */
    private function sendText(WaOrder $order, string $body, string $kind): bool
    {
        $to = preg_replace('/\D+/', '', (string) $order->customer_phone);
        if ($to === '') {
            Log::warning('[WA-CHECKOUT] no customer phone to deliver ' . $kind, ['order' => $order->id]);
            return false;
        }

        $msg = Message::create(array_merge([
            'user_id'      => $this->ownerUserId($order),
            'workspace_id' => $order->workspace_id,   // dispatcher routes by the workspace's engine
            'direction'    => 'out',
            'to_number'    => $to,
            'body'         => $body,
            'status'       => 'pending',
        ], $order->shopSenderFields()));   // ...FROM the shop's own chosen number

        try {
            $result = $this->dispatcher->send($msg);
            $ok = (bool) ($result['ok'] ?? false);
            $msg->forceFill([
                'status'         => $ok ? 'sent' : 'failed',
                'failure_reason' => $ok ? null : ($result['error'] ?? null),
                'sent_at'        => $ok ? now() : null,
            ])->save();
            return $ok;
        } catch (\Throwable $e) {
            $msg->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)])->save();
            Log::warning('[WA-CHECKOUT] ' . $kind . ' send threw', ['order' => $order->id, 'err' => $e->getMessage()]);
            return false;
        }
    }

    /** The workspace owner — the "sender" a system-triggered message is attributed to. */
    private function ownerUserId(WaOrder $order): ?int
    {
        return (int) (Workspace::whereKey($order->workspace_id)->value('owner_user_id') ?: 0) ?: null;
    }

    /**
     * The URL the gateway returns the customer to after paying — our public
     * thank-you page, which ALSO confirms the payment server-side (webhook
     * fallback). Ensures the order has a recovery_token to key it on.
     */
    private function returnUrl(WaOrder $order, string $slug): string
    {
        if (empty($order->recovery_token)) {
            $order->forceFill(['recovery_token' => 'tok_' . \Illuminate\Support\Str::random(20)])->save();
        }
        return route('wa.checkout.return', ['slug' => $slug, 'token' => $order->recovery_token]);
    }

    /** Currency-aware amount ("Rp33,900,000" / "KSh3,390.00") — honours precision. */
    private function amountLabel(WaOrder $order): string
    {
        $major = ((int) $order->total_minor) / 100;
        return \App\Support\FormatSettings::formatIn($major, $order->currency_code);
    }
}
