<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Idempotency ledger for Woo/Shopify webhook retries — short-circuits replays. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_webhook_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source', 24);
            $table->string('delivery_id', 128); // X-WC-Webhook-Delivery-ID / X-Shopify-Webhook-Id
            $table->string('topic', 64)->nullable();
            $table->string('external_order_id', 64)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'delivery_id'], 'inv_wh_src_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_webhook_events');
    }
};
