<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-secret Google targeting per booking type — which calendar, whether to
 * create a Meet link, and an optional Sheets append target. Google OAuth tokens
 * stay in the encrypted workspaces.appointment_settings.google_oauth bag; this
 * table holds only targeting IDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->unique()->constrained('booking_types')->cascadeOnDelete();
            $table->string('calendar_id', 191)->nullable();
            $table->boolean('create_meet')->default(false);
            $table->string('spreadsheet_id', 191)->nullable();
            $table->string('sheet_range', 64)->default('Sheet1!A1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_integrations');
    }
};
