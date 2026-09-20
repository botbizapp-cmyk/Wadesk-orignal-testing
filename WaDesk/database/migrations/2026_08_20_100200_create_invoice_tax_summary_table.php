<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Aggregated tax rows for the PDF footer (CGST/SGST/IGST split or single VAT line). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_tax_summary', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('tax_label', 24);
            $table->decimal('rate', 6, 3)->nullable();
            $table->bigInteger('base_minor')->default(0);
            $table->bigInteger('amount_minor')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_tax_summary');
    }
};
