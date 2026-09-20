<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-CRM Phase 7 — sales_docs: unified table backing both Proposals and
 * Estimates (doc_type). A lightweight pre-invoice quote: line items live in
 * items_json, money is integer minor units in the doc's own currency exponent.
 * public_token makes it shareable; convert-to-invoice hands the totals to the
 * existing InvoiceService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_docs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('doc_type', 20)->default('proposal'); // proposal | estimate
            $table->string('number', 40)->nullable();            // PRO-0001 / EST-0001
            $table->unsignedBigInteger('seq')->default(0);
            $table->string('status', 20)->default('draft');      // draft|sent|accepted|rejected|expired|invoiced
            $table->string('title')->nullable();

            // Buyer + links (nullable — a doc can stand alone)
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('deal_id')->nullable()->index();
            $table->text('buyer_name')->nullable();              // encrypted
            $table->text('buyer_email')->nullable();             // encrypted
            $table->text('buyer_phone')->nullable();             // encrypted

            // Money (doc's own currency)
            $table->string('currency', 8)->default('USD');
            $table->unsignedTinyInteger('currency_exponent')->default(2);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);  // basis points, whole-doc tax
            $table->longText('items_json')->nullable();          // [{description, qty, unit_price_minor, line_total_minor}]

            $table->text('notes')->nullable();                   // encrypted
            $table->date('valid_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();         // accepted/rejected stamp
            $table->unsignedBigInteger('invoice_id')->nullable(); // set once converted
            $table->string('public_token', 64)->nullable()->unique();

            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'doc_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_docs');
    }
};
