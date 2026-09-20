<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-booking-type financials — fee, tax, deposit, gateway. Amounts in minor
 * units (zero-decimal-safe). currency is nullable → seeded from the workspace's
 * package currency on first save (never hardcoded). cancel/no_show fees are
 * STORED amounts only in this release; automatic charging (card-on-file) is
 * out of scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_financials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->unique()->constrained('booking_types')->cascadeOnDelete();
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->decimal('tax_pct', 5, 2)->default(0);
            $table->char('currency', 3)->nullable();
            $table->string('gateway_slug', 64)->nullable();
            $table->enum('deposit_mode', ['none', 'partial', 'full'])->default('none');
            $table->unsignedBigInteger('deposit_value_minor')->default(0);
            $table->boolean('auto_send_link')->default(true);
            $table->unsignedBigInteger('cancel_fee_minor')->default(0);
            $table->unsignedBigInteger('no_show_fee_minor')->default(0);
            $table->unsignedInteger('cancel_window_minutes')->default(1440);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_financials');
    }
};
