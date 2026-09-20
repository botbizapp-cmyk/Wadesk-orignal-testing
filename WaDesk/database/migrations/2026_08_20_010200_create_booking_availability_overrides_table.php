<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date-specific availability overrides per booking type — closed dates
 * (vacation/blackout) or special hours that replace that weekday's windows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason', 191)->nullable();
            $table->timestamps();

            $table->unique(['booking_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_availability_overrides');
    }
};
