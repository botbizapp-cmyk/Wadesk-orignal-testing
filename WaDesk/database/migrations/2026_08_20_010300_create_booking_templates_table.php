<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle message templates per booking type — four events only
 * (confirmation, cancellation, reminder, reschedule). WhatsApp/IG/FB pick a
 * WaTemplate; Telegram/plain use plain_body. Reminder rows here hold the body;
 * the cadence offsets live in booking_reminders. Channel-capable but WhatsApp
 * is the default and first-built path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->enum('event', ['confirmation', 'cancellation', 'reminder', 'reschedule']);
            $table->enum('channel', ['whatsapp', 'telegram', 'instagram', 'facebook'])->default('whatsapp');
            $table->foreignId('wa_template_id')->nullable()->constrained('wa_templates')->nullOnDelete();
            $table->text('plain_body')->nullable();
            $table->json('variable_map')->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamps();

            $table->unique(['booking_type_id', 'event', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_templates');
    }
};
