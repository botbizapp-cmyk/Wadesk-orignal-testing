<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent monotonic counter, decoupled from order id, one row per
 * (workspace, series). Allocation uses a short lockForUpdate() txn that commits
 * BEFORE any rendering/upload/send — so the number sequence stays gap-free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->string('series', 48);
            $table->unsignedInteger('next_seq')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'series'], 'inv_seq_ws_series_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
