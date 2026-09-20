<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-CRM Phase 2.2 — Payment ledger. A workspace-scoped record of money RECEIVED
 * (full or partial) against an invoice / deal / contact / company, so revenue
 * rolls up everywhere and the copilot can answer "how much has ACME paid this
 * quarter?". No collision — there is no existing `payments` table (platform SaaS
 * gateways live in `payment_gateways`, WhatsApp-Pay config in
 * `workspace_payment_configs`, merchant creds in `wa_merchant_gateways`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            // CRM links — all nullable so a payment can attach to any/all of them.
            $t->unsignedBigInteger('invoice_id')->nullable()->index();
            $t->unsignedBigInteger('deal_id')->nullable()->index();
            $t->unsignedBigInteger('contact_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('wa_order_id')->nullable()->index();

            $t->bigInteger('amount_minor');            // integer minor units (repo-wide convention)
            $t->string('currency', 8)->default('USD');
            $t->string('method', 32)->default('manual'); // manual|cash|bank|card|upi|gateway|wa_pay|storefront
            $t->string('source', 24)->default('manual'); // manual|gateway|wa_pay|storefront
            $t->timestamp('paid_at')->nullable()->index();
            $t->string('reference', 191)->nullable();   // gateway txn id / cheque no. — plaintext for lookup
            $t->text('note')->nullable();               // merchant-authored memo
            $t->string('gateway_payment_id', 191)->nullable(); // idempotency for auto-bridged rows
            $t->unsignedBigInteger('recorded_by')->nullable(); // user id (null = system/gateway)
            $t->json('meta_json')->nullable();
            $t->timestamps();

            // Idempotency for auto-written (gateway/WA-Pay) rows so the WaOrderPaid
            // listener can't double-insert on webhook retries.
            $t->unique(['workspace_id', 'source', 'gateway_payment_id'], 'payments_src_gwid_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
