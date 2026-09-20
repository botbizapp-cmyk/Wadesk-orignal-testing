<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source-agnostic invoice document, hung off wa_orders. One immutable invoice
 * per (source, external_order_id, doc_type). Amounts are integer minor units in
 * the invoice's OWN currency exponent. The PDF lives at an unguessable
 * public_token path; the sequential number never appears in a public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source', 24)->index();                 // woocommerce|shopify|own|manual
            $table->string('doc_type', 16)->index()->default('tax_invoice'); // tax_invoice|receipt|proforma|credit_note
            $table->unsignedBigInteger('wa_order_id')->nullable()->index();
            $table->string('external_order_id', 64)->nullable();
            $table->string('external_order_number', 64)->nullable();
            $table->string('series', 48)->index();
            $table->string('invoice_number', 64);
            $table->unsignedInteger('seq');
            $table->string('status', 16)->index()->default('issued'); // issued|paid|void|credited
            $table->string('send_status', 16)->index()->default('pending'); // pending|rendering|ready|sending|sent|send_failed|skipped
            $table->unsignedTinyInteger('send_attempts')->default(0);
            $table->string('send_reason', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('trigger', 16)->default('manual');       // on_placed|on_paid|on_fulfilled|manual
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('currency_exponent')->default(2);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('buyer_name', 191)->nullable();
            $table->string('buyer_email', 191)->nullable();
            $table->string('buyer_phone', 32)->nullable();
            $table->json('billing_json')->nullable();
            $table->json('shipping_json')->nullable();
            $table->json('seller_snapshot_json')->nullable();
            $table->text('notes')->nullable();
            $table->string('pdf_disk', 32)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->unsignedInteger('pdf_bytes')->nullable();
            $table->string('delivery_channel', 16)->nullable();     // whatsapp (WABA/Baileys)
            $table->timestamp('delivered_at')->nullable();
            $table->string('wa_message_id', 128)->nullable();
            $table->text('send_error')->nullable();
            $table->string('public_token', 48)->unique();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            // one invoice per source order PER document type (proforma + tax invoice coexist)
            $table->unique(['source', 'external_order_id', 'doc_type'], 'inv_src_ext_doc_unique');
            $table->unique(['workspace_id', 'invoice_number'], 'inv_ws_number_unique');
            $table->unique(['workspace_id', 'series', 'seq'], 'inv_ws_series_seq_unique');
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'send_status']);
            $table->index(['workspace_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
