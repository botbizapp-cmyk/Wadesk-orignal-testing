<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly availability intervals per booking type. Multiple rows per weekday =
 * multiple intervals (09:00–12:00 and 14:00–18:00). Times stored in the booking
 * type's timezone. `staff_id` is a RESERVED nullable placeholder — no FK (no
 * staff table yet; staff scheduling is out of scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedTinyInteger('weekday'); // 0=Sun .. 6=Sat
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['booking_type_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_availability_rules');
    }
};
