<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-queue message history, stored INLINE on the existing broadcasts row (no
 * new table). The mobile app presents a queue as a chat: several messages can be
 * sent to the same queue over time and each must persist as a bubble when the
 * queue is reopened. A Broadcast previously kept only ONE current body
 * (overwritten on every send), so past messages vanished. Each send/schedule now
 * appends an entry here; QueueController::getQueueMessages returns them in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->json('messages_log')->nullable()->after('temp_caption');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn('messages_log');
        });
    }
};
