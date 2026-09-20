<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-lock: holds slot seats for ~10 min while a chat booker answers
 * questions / pays. Slot generation subtracts live (non-expired) reservation
 * seats. Expired rows are swept inline on the heartbeat. This is a best-effort
 * capacity-aware hold; the HARD anti-double-book guarantee is the
 * UNIQUE(active_slot_key) on appointments, not this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');
            $table->string('session_ref', 191); // conversation_id or flow session id
            $table->string('channel', 32);
            $table->unsignedSmallInteger('seats')->default(1);
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['booking_type_id', 'starts_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_reservations');
    }
};
