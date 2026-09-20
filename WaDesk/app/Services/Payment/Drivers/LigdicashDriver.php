<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LigdiCash payment gateway driver (Francophone West Africa — Burkina Faso).
 *
 * Mobile-money + card aggregator (Orange Money, Moov Money, cards) settling in
 * XOF. Redirect flow, verified server-side — mirrors CinetPay:
 *
 *   1. initiate()  -> POST /redirect/checkout-invoice/create, redirect the
 *                    customer to the returned `response_text` URL.
 *   2. the customer pays on LigdiCash's hosted page, then LigdiCash redirects
 *      back to return_url (browser) AND POSTs the token to callback_url (IPN),
 *      both carrying the invoice `token`.
 *   3. handleCallback()/handleWebhook() -> GET /redirect/checkout-invoice/confirm
 *      ?invoiceToken=<token>; a `status:"completed"` is a real, server-verified
 *      payment. This API round-trip (signed with the merchant's own Apikey +
 *      Bearer token) IS the authenticity check — LigdiCash has no webhook HMAC,
 *      so we deliberately declare NO webhook-secret field (see credentialFields).
 *
 * There is no separate sandbox host: integration testing uses the same
 * production URL with temporary account keys.
 *
 * @see https://developers.ligdicash.com/en/sdk/php
 */
class LigdicashDriver extends AbstractGatewayDriver
{
    private const API_BASE = 'https://app.ligdicash.com/pay/v01';

    public static function credentialFields(): array
    {
        return [
            'api_key'    => ['label' => 'API Key',    'type' => 'text',     'required' => true,  'hint' => 'Your LigdiCash project API key (sent as the "Apikey" header).'],
            'api_token'  => ['label' => 'API Token',  'type' => 'password', 'required' => true,  'hint' => 'Your LigdiCash API token (sent as the "Authorization: Bearer" header).'],
            'store_name' => ['label' => 'Store name', 'type' => 'text',     'required' => false, 'hint' => 'Shown on the LigdiCash payment page. Defaults to your site name.'],
        ];
    }

    /** Shared, authenticated HTTP client for every LigdiCash call. */
    private function http()
    {
        return Http::acceptJson()->asJson()
            ->connectTimeout(20)
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->retry(2, 500)
            ->withHeaders([
                'Apikey'        => (string) $this->cred('api_key'),
                'Authorization' => 'Bearer ' . (string) $this->cred('api_token'),
            ]);
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        $apiKey   = (string) $this->cred('api_key');
        $apiToken = (string) $this->cred('api_token');
        if ($apiKey === '' || $apiToken === '') return PaymentResult::failed('ligdicash_credentials_missing');

        // LigdiCash settles ONLY in XOF (zero-decimal) and its docs state the
        // `devise` field "must always be XOF" — any other value is rejected. So
        // we always send XOF; the amount is a whole integer (no minor units).
        $currency = 'XOF';
        if ($order->currency && strtoupper($order->currency) !== 'XOF') {
            Log::warning('[ligdicash] order currency is not XOF — sending as XOF', [
                'order' => $order->order_number, 'order_currency' => $order->currency,
            ]);
        }
        $amount = (int) round((float) $order->amount);
        if ($amount <= 0) return PaymentResult::failed('ligdicash_invalid_amount');

        $storeName = trim((string) $this->cred('store_name')) ?: (string) config('app.name', 'Store');
        $siteUrl   = rtrim((string) config('app.url', ''), '/') ?: 'https://example.com';

        $body = [
            'commande' => [
                'invoice' => [
                    'items' => [[
                        'name'        => 'Order #' . $order->order_number,
                        'description' => 'Order #' . $order->order_number,
                        'quantity'    => 1,
                        'unit_price'  => $amount,
                        'total_price' => $amount,
                    ]],
                    'total_amount'       => $amount,
                    'devise'             => $currency,
                    'description'        => 'Order #' . $order->order_number,
                    'customer'           => '',
                    'customer_firstname' => (string) ($order->customer_name ?: optional($order->user)->name ?: ''),
                    'customer_lastname'  => '',
                    'customer_email'     => (string) ($order->customer_email ?: optional($order->user)->email ?: ''),
                    'external_id'        => (string) $order->order_number,
                    'otp'                => '',
                ],
                'store' => [
                    'name'        => $storeName,
                    'website_url' => $siteUrl,
                ],
                'actions' => [
                    // Browser return + cancel land the customer back on our
                    // callback (LigdiCash appends the invoice token). The
                    // server-to-server IPN hits our webhook route.
                    'cancel_url'   => $callbackUrl,
                    'return_url'   => $callbackUrl,
                    'callback_url' => route('payment.webhook', ['gateway' => 'ligdicash']),
                ],
                'custom_data' => [
                    'order_id'       => (string) $order->id,
                    'order_number'   => (string) $order->order_number,
                    'transaction_id' => (string) $order->order_number,
                ],
            ],
        ];

        Log::info('[ligdicash] initiate', [
            'order'    => $order->order_number,
            'amount'   => $amount,
            'currency' => $currency,
        ]);

        try {
            $r    = $this->http()->post(self::API_BASE . '/redirect/checkout-invoice/create', $body);
            $json = $r->json() ?: [];

            // "00" == accepted; response_text carries the hosted-page URL and
            // `token` is the invoice id we confirm against later. We stash the
            // token as the gateway_order_id so the return/webhook (both echo
            // `token`) resolve straight back to this order.
            if ((string) ($json['response_code'] ?? '') === '00' && !empty($json['response_text'])) {
                $token = (string) ($json['token'] ?? '');
                Log::info('[ligdicash] invoice created', ['order' => $order->order_number, 'has_token' => $token !== '']);
                return PaymentResult::redirect((string) $json['response_text'], $token ?: null, $json);
            }

            $reason = trim((string) ($json['response_text'] ?? ($json['description'] ?? 'create_failed')));
            Log::warning('[ligdicash] create rejected', [
                'order' => $order->order_number,
                'http'  => $r->status(),
                'code'  => $json['response_code'] ?? null,
            ]);
            return PaymentResult::failed('ligdicash: ' . $reason);
        } catch (\Throwable $e) {
            Log::error('[ligdicash] initiate exception', ['order' => $order->order_number, 'error' => $e->getMessage()]);
            return PaymentResult::failed('ligdicash_exception: ' . $e->getMessage());
        }
    }

    public function handleCallback(array $payload): PaymentResult
    {
        // LigdiCash echoes the invoice token on both the browser return and the
        // server IPN. Accept every spelling we might receive.
        $token = $payload['token']
            ?? $payload['invoiceToken']
            ?? $payload['invoice_token']
            ?? $payload['transaction_id']
            ?? null;
        if (!$token) return PaymentResult::failed('missing_ligdicash_token');

        try {
            $r    = $this->http()->get(self::API_BASE . '/redirect/checkout-invoice/confirm', ['invoiceToken' => $token]);
            $json = $r->json() ?: [];
            $code   = (string) ($json['response_code'] ?? '');
            $status = strtolower((string) ($json['status'] ?? ''));

            Log::info('[ligdicash] confirm result', [
                'http'   => $r->status(),
                'code'   => $code,
                'status' => $status,
            ]);

            if ($code !== '00') return PaymentResult::failed("ligdicash_confirm_code: {$code}", $json);
            if ($status === 'completed') {
                return PaymentResult::paid(
                    gatewayPaymentId: (string) ($json['request_id'] ?? $token),
                    gatewayOrderId:   (string) $token,
                    payload:          $json,
                );
            }
            // pending / notcompleted / cancelled — not a terminal success.
            return PaymentResult::failed("ligdicash_status: {$status}", $json);
        } catch (\Throwable $e) {
            Log::error('[ligdicash] confirm exception', ['error' => $e->getMessage()]);
            return PaymentResult::failed('ligdicash_confirm_exception: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): PaymentResult
    {
        return $this->handleCallback($payload);
    }
}
