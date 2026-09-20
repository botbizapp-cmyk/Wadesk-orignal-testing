<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Server-side payment operations for the mobile app.
 *
 * WHY THIS EXISTS (CodeCanyon security fix): the app used to receive gateway
 * SECRET keys (Stripe secret_key, Razorpay key_secret, PayPal client_secret)
 * and call the providers directly from the device — anyone could extract those
 * secrets from the APK / network traffic and take over the merchant account.
 * Now the app receives ONLY publishable/public keys, and every secret-key
 * operation happens here, on the server. The app calls these endpoints and
 * drives the payment SDKs with the returned client secret / order id only.
 *
 * All routes are under `/api/app` and require a Sanctum user token
 * (auth:sanctum + app.workspace) — see routes/app.php.
 */
class MobilePaymentController extends Controller
{
    private const HTTP_TIMEOUT = 30;

    /** Stripe mobile SDK ephemeral-key API version. App may override via `stripe_version`. */
    private const STRIPE_API_VERSION = '2024-06-20';

    /**
     * Resolve an active gateway by slug and return its decrypted credentials.
     * Throws a JsonResponse-carrying exception path via the caller's guard.
     *
     * @return array{0: PaymentGateway, 1: array<string,mixed>}|null
     */
    private function gateway(string $slug): ?array
    {
        $gw = PaymentGateway::query()->where('slug', $slug)->where('is_active', true)->first();
        if (! $gw) return null;
        return [$gw, $gw->getDecryptedCredentials()];
    }

    /** Amount → provider MINOR units (cents/paise). App sends MAJOR units (e.g. 499.00). */
    private function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Server-authoritative amount. When the app sends `package_id`, the price is
     * looked up from the PLAN server-side (offer-aware, currency-converted) and
     * the client-sent `amount` is IGNORED — so a tampered client can't post
     * amount:0.01 for a paid plan. Falls back to the client amount only when no
     * package_id is supplied (legacy calls).
     */
    private function resolveAmount(Request $request, float $clientAmount, string $currency): float
    {
        $pkgId = $request->input('package_id');
        if ($pkgId) {
            $pkg = \App\Models\Package::query()->where('status', true)->find($pkgId);
            if ($pkg) {
                $amt = (float) $pkg->chargeableAmount();
                if ($pkg->currency && strtoupper((string) $pkg->currency) !== strtoupper($currency)) {
                    $amt = (float) \App\Support\FormatSettings::convert($amt, $pkg->currency, $currency);
                }
                return round($amt, 2);
            }
        }
        return $clientAmount;
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], $code);
    }

    // ------------------------------------------------------------------
    //  Stripe — PaymentSheet (Customer + EphemeralKey + PaymentIntent)
    // ------------------------------------------------------------------

    /**
     * POST /api/app/stripe/create-payment-intent
     * body: { amount, currency, order_id, stripe_version? }
     * returns: { status, paymentIntentClientSecret, ephemeralKey, customerId, publishableKey }
     */
    public function stripeCreatePaymentIntent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.5'],
            'currency'       => ['required', 'string', 'size:3'],
            'order_id'       => ['nullable'],
            'package_id'     => ['nullable', 'integer'],
            'stripe_version' => ['nullable', 'string', 'max:20'],
        ]);

        $resolved = $this->gateway('stripe');
        if (! $resolved) return $this->fail('Stripe is not enabled.', 400);
        [$gw, $creds] = $resolved;

        $secret = (string) ($creds['secret_key'] ?? '');
        $pub    = (string) ($creds['publishable_key'] ?? '');
        if ($secret === '') return $this->fail('Stripe secret key is not configured.', 500);

        $user     = $request->user();
        $currency = strtolower($data['currency']);
        $amount   = $this->minorUnits($this->resolveAmount($request, (float) $data['amount'], $data['currency']));
        $apiVer   = (string) ($data['stripe_version'] ?? self::STRIPE_API_VERSION);

        try {
            // 1) Customer — reused per user via metadata (created fresh here is fine).
            $cust = Http::asForm()->withBasicAuth($secret, '')->timeout(self::HTTP_TIMEOUT)
                ->post('https://api.stripe.com/v1/customers', array_filter([
                    'email'             => (string) ($user->email ?? ''),
                    'name'              => (string) ($user->name ?? ''),
                    'metadata[user_id]' => (string) ($user->id ?? ''),
                ]));
            if ($cust->failed()) return $this->fail('Stripe customer failed: ' . $this->stripeErr($cust), 502);
            $customerId = (string) $cust->json('id');

            // 2) Ephemeral key — MUST be created with the API version the mobile
            //    SDK expects (the app can pass its own via `stripe_version`).
            $ek = Http::asForm()->withBasicAuth($secret, '')
                ->withHeaders(['Stripe-Version' => $apiVer])->timeout(self::HTTP_TIMEOUT)
                ->post('https://api.stripe.com/v1/ephemeral_keys', ['customer' => $customerId]);
            if ($ek->failed()) return $this->fail('Stripe ephemeral key failed: ' . $this->stripeErr($ek), 502);
            $ephemeralKey = (string) $ek->json('secret');

            // 3) PaymentIntent.
            $pi = Http::asForm()->withBasicAuth($secret, '')->timeout(self::HTTP_TIMEOUT)
                ->post('https://api.stripe.com/v1/payment_intents', [
                    'amount'                             => $amount,
                    'currency'                           => $currency,
                    'customer'                           => $customerId,
                    'automatic_payment_methods[enabled]' => 'true',
                    'metadata[order_id]'                 => (string) ($data['order_id'] ?? ''),
                    'metadata[user_id]'                  => (string) ($user->id ?? ''),
                ]);
            if ($pi->failed()) return $this->fail('Stripe payment intent failed: ' . $this->stripeErr($pi), 502);

            return response()->json([
                'status'                     => true,
                'paymentIntentClientSecret'  => (string) $pi->json('client_secret'),
                'ephemeralKey'               => $ephemeralKey,
                'customerId'                 => $customerId,
                'publishableKey'             => $pub,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Stripe error: ' . $e->getMessage(), 502);
        }
    }

    private function stripeErr($resp): string
    {
        return (string) (data_get($resp->json(), 'error.message') ?: ('HTTP ' . $resp->status()));
    }

    // ------------------------------------------------------------------
    //  Razorpay — create order + verify signature
    // ------------------------------------------------------------------

    /**
     * POST /api/app/razorpay/create-order
     * body: { amount, currency, order_id }
     * returns: { status, razorpayOrderId, keyId }
     */
    public function razorpayCreateOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'   => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'order_id' => ['nullable'],
            'package_id'     => ['nullable', 'integer'],
        ]);

        $resolved = $this->gateway('razorpay');
        if (! $resolved) return $this->fail('Razorpay is not enabled.', 400);
        [$gw, $creds] = $resolved;

        $keyId     = (string) ($creds['key_id'] ?? '');
        $keySecret = (string) ($creds['key_secret'] ?? '');
        if ($keyId === '' || $keySecret === '') return $this->fail('Razorpay keys are not configured.', 500);

        try {
            $r = Http::withBasicAuth($keyId, $keySecret)->timeout(self::HTTP_TIMEOUT)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount'   => $this->minorUnits($this->resolveAmount($request, (float) $data['amount'], $data['currency'])),
                    'currency' => strtoupper($data['currency']),
                    'receipt'  => (string) ($data['order_id'] ?? ('ord_' . $request->user()?->id)),
                    'notes'    => ['order_id' => (string) ($data['order_id'] ?? ''), 'user_id' => (string) $request->user()?->id],
                ]);
            if ($r->failed()) {
                return $this->fail('Razorpay order failed: ' . (data_get($r->json(), 'error.description') ?: ('HTTP ' . $r->status())), 502);
            }

            return response()->json([
                'status'          => true,
                'razorpayOrderId' => (string) $r->json('id'),
                'keyId'           => $keyId,   // PUBLIC key id only.
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Razorpay error: ' . $e->getMessage(), 502);
        }
    }

    /**
     * POST /api/app/razorpay/verify
     * body: { razorpay_order_id, razorpay_payment_id, razorpay_signature }
     * returns: { status, verified }
     *
     * Signature is HMAC-SHA256(order_id|payment_id, key_secret) — computed here
     * with the SECRET the app never sees. Only after this returns verified=true
     * should the order be treated as paid.
     */
    public function razorpayVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id'   => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature'  => ['required', 'string'],
        ]);

        $resolved = $this->gateway('razorpay');
        if (! $resolved) return $this->fail('Razorpay is not enabled.', 400);
        [$gw, $creds] = $resolved;

        $keySecret = (string) ($creds['key_secret'] ?? '');
        if ($keySecret === '') return $this->fail('Razorpay key secret is not configured.', 500);

        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], $keySecret);
        $verified = hash_equals($expected, $data['razorpay_signature']);

        return response()->json(['status' => true, 'verified' => $verified]);
    }

    // ------------------------------------------------------------------
    //  PayPal — create order + capture (Orders v2)
    // ------------------------------------------------------------------

    /** PayPal API base for the gateway's mode. */
    private function paypalBase(PaymentGateway $gw): string
    {
        return (string) $gw->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /** OAuth2 client-credentials access token (uses client_id + secret, server-side). */
    private function paypalToken(string $base, array $creds): ?string
    {
        $r = Http::asForm()
            ->withBasicAuth((string) ($creds['client_id'] ?? ''), (string) ($creds['client_secret'] ?? ''))
            ->timeout(self::HTTP_TIMEOUT)
            ->post($base . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        return $r->successful() ? (string) $r->json('access_token') : null;
    }

    /**
     * POST /api/app/paypal/create-order
     * body: { amount, currency, order_id }
     * returns: { status:'success', paypalOrderId, approveUrl }
     */
    public function paypalCreateOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'order_id' => ['nullable'],
            'package_id'     => ['nullable', 'integer'],
        ]);

        $resolved = $this->gateway('paypal');
        if (! $resolved) return $this->fail('PayPal is not enabled.', 400);
        [$gw, $creds] = $resolved;
        if ((string) ($creds['client_id'] ?? '') === '' || (string) ($creds['client_secret'] ?? '') === '') {
            return $this->fail('PayPal credentials are not configured.', 500);
        }

        $base  = $this->paypalBase($gw);
        try {
            $token = $this->paypalToken($base, $creds);
            if (! $token) return $this->fail('PayPal auth failed — check client id/secret and mode.', 502);

            $r = Http::withToken($token)->timeout(self::HTTP_TIMEOUT)
                ->post($base . '/v2/checkout/orders', [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'custom_id' => (string) ($data['order_id'] ?? ''),
                        'amount'    => [
                            'currency_code' => strtoupper($data['currency']),
                            'value'         => number_format($this->resolveAmount($request, (float) $data['amount'], $data['currency']), 2, '.', ''),
                        ],
                    ]],
                    // The Flutter WebView watches for these URL substrings to
                    // close itself (/paypal/return = success, /paypal/cancel).
                    'application_context' => [
                        'return_url'  => url('/api/app/paypal/return'),
                        'cancel_url'  => url('/api/app/paypal/cancel'),
                        'user_action' => 'PAY_NOW',
                    ],
                ]);
            if ($r->failed()) {
                return $this->fail('PayPal order failed: ' . (data_get($r->json(), 'message') ?: ('HTTP ' . $r->status())), 502);
            }

            $approve = collect($r->json('links', []))->firstWhere('rel', 'approve')['href'] ?? '';

            return response()->json([
                'status'        => 'success',
                'paypalOrderId' => (string) $r->json('id'),
                'approveUrl'    => (string) $approve,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('PayPal error: ' . $e->getMessage(), 502);
        }
    }

    /**
     * POST /api/app/paypal/capture-order
     * body: { paypalOrderId }
     * returns: { status:'success', transactionId, paid }
     */
    public function paypalCaptureOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paypalOrderId' => ['required', 'string'],
        ]);

        $resolved = $this->gateway('paypal');
        if (! $resolved) return $this->fail('PayPal is not enabled.', 400);
        [$gw, $creds] = $resolved;

        $base = $this->paypalBase($gw);
        try {
            $token = $this->paypalToken($base, $creds);
            if (! $token) return $this->fail('PayPal auth failed — check client id/secret and mode.', 502);

            $r = Http::withToken($token)
                ->withBody('{}', 'application/json')
                ->timeout(self::HTTP_TIMEOUT)
                ->post($base . '/v2/checkout/orders/' . $data['paypalOrderId'] . '/capture');
            if ($r->failed()) {
                return $this->fail('PayPal capture failed: ' . (data_get($r->json(), 'message') ?: ('HTTP ' . $r->status())), 502);
            }

            $status  = (string) $r->json('status');   // COMPLETED on success
            $capture = data_get($r->json(), 'purchase_units.0.payments.captures.0', []);

            return response()->json([
                'status'        => 'success',
                'transactionId' => (string) ($capture['id'] ?? $r->json('id')),
                'paid'          => $status === 'COMPLETED',
            ]);
        } catch (\Throwable $e) {
            return $this->fail('PayPal error: ' . $e->getMessage(), 502);
        }
    }

    // ------------------------------------------------------------------
    //  Paystack — initialize + verify
    // ------------------------------------------------------------------

    /**
     * POST /api/app/paystack/initialize
     * body: { amount, currency, order_id, email }
     * returns: { status, reference, authorizationUrl }
     */
    public function paystackInitialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'   => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'order_id' => ['nullable'],
            'package_id'     => ['nullable', 'integer'],
            'email'    => ['nullable', 'email'],
        ]);

        $resolved = $this->gateway('paystack');
        if (! $resolved) return $this->fail('Paystack is not enabled.', 400);
        [$gw, $creds] = $resolved;

        $secret = (string) ($creds['secret_key'] ?? '');
        if ($secret === '') return $this->fail('Paystack secret key is not configured.', 500);

        $email = (string) ($data['email'] ?? $request->user()?->email ?? '');
        if ($email === '') return $this->fail('An email is required to start a Paystack transaction.', 422);

        // Unique reference — Paystack requires uniqueness. Ties back to the app's
        // order_id via metadata for reconciliation.
        $reference = 'wadesk_' . preg_replace('/[^A-Za-z0-9_]/', '', (string) ($data['order_id'] ?? '')) . '_' . uniqid();

        try {
            $r = Http::withToken($secret)->timeout(self::HTTP_TIMEOUT)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email'        => $email,
                    'amount'       => $this->minorUnits($this->resolveAmount($request, (float) $data['amount'], $data['currency'])),   // kobo/pesewas
                    'currency'     => strtoupper($data['currency']),
                    'reference'    => $reference,
                    // The Flutter WebView watches for /paystack/callback to close.
                    'callback_url' => url('/api/app/paystack/callback'),
                    'metadata'     => ['order_id' => (string) ($data['order_id'] ?? ''), 'user_id' => (string) $request->user()?->id],
                ]);
            $json = $r->json() ?: [];
            if (($json['status'] ?? false) !== true || empty($json['data']['authorization_url'])) {
                return $this->fail('Paystack init failed: ' . ($json['message'] ?? ('HTTP ' . $r->status())), 502);
            }

            return response()->json([
                'status'           => true,
                'reference'        => (string) ($json['data']['reference'] ?? $reference),
                'authorizationUrl' => (string) $json['data']['authorization_url'],
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Paystack error: ' . $e->getMessage(), 502);
        }
    }

    /**
     * POST /api/app/paystack/verify
     * body: { reference }
     * returns: { status, paid, transactionId }
     * paid is true ONLY when Paystack returns data.status == "success".
     */
    public function paystackVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $resolved = $this->gateway('paystack');
        if (! $resolved) return $this->fail('Paystack is not enabled.', 400);
        [$gw, $creds] = $resolved;

        $secret = (string) ($creds['secret_key'] ?? '');
        if ($secret === '') return $this->fail('Paystack secret key is not configured.', 500);

        try {
            $r = Http::withToken($secret)->timeout(self::HTTP_TIMEOUT)
                ->get('https://api.paystack.co/transaction/verify/' . urlencode($data['reference']));
            $json = $r->json() ?: [];
            $paid = (($json['status'] ?? false) === true) && (($json['data']['status'] ?? '') === 'success');

            return response()->json([
                'status'        => true,
                'paid'          => $paid,
                'transactionId' => (string) ($json['data']['id'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Paystack error: ' . $e->getMessage(), 502);
        }
    }
}
