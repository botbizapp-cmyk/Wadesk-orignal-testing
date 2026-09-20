<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder cadence per booking type. offset_index is a stable admin handle
 * (0..99) that drives the synthetic Node scheduler id (§7.1) so editing one
 * offset never renumbers the others. Offsets must be strictly decreasing so
 * bands are mutually exclusive (validated on save). Wizard caps ≤ 8 offsets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->unsignedTinyInteger('offset_index');   // 0..99 stable slot
            $table->unsignedInteger('offset_minutes');     // minutes BEFORE starts_at
            $table->string('template_event', 32)->default('reminder');
            $table->string('label', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['booking_type_id', 'is_active']);
            $table->unique(['booking_type_id', 'offset_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
    }
};
