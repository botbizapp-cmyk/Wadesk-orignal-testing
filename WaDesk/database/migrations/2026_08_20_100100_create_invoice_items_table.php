<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('description', 255);
            $table->string('sku', 64)->nullable();
            $table->string('hsn_sac', 16)->nullable();          // India GST, tax-invoice only
            $table->decimal('qty', 12, 3)->default(1);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->bigInteger('line_subtotal_minor')->default(0);
            $table->bigInteger('line_discount_minor')->default(0);
            $table->decimal('tax_rate', 6, 3)->nullable();       // percent
            $table->bigInteger('tax_amount_minor')->default(0);
            $table->string('tax_code', 24)->nullable();          // CGST|SGST|IGST|VAT|GST
            $table->string('currency', 3)->default('USD');
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
