<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-MERCHANT payment gateway credentials — the money for a CUSTOMER checkout
 * (WaOrder) must land in the MERCHANT's own gateway account, not the platform's
 * (the admin PaymentGateway rows are the platform's SaaS-billing accounts). Today
 * only Razorpay is supported per-storefront (WaStorefront.payment_config_json);
 * this generalises it so a workspace can hold its OWN keys for ANY of the 30
 * gateway drivers. A transient PaymentGateway is built from these creds and fed
 * to the existing driver (zero driver changes) — see CustomerPaymentService.
 *
 * Shared by BOTH engines: WABA (native Flow checkout) and Unofficial/Baileys
 * (payment-link checkout) route payment through the same merchant gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_merchant_gateways', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            // 0 = workspace-wide default; >0 pins the config to one storefront so
            // a workspace running several storefronts can route each to its own
            // account. Resolution prefers a storefront match, then the 0 default.
            $table->unsignedBigInteger('storefront_id')->default(0);
            $table->string('slug', 64);                 // gateway driver slug: razorpay|stripe|paystack|flutterwave|…
            $table->text('credentials')->nullable();    // Crypt::encryptString(json) — same convention as PaymentGateway
            $table->string('mode', 16)->default('live'); // live|test
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            // One credential row per (workspace, storefront, gateway).
            $table->unique(['workspace_id', 'storefront_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_merchant_gateways');
    }
};
