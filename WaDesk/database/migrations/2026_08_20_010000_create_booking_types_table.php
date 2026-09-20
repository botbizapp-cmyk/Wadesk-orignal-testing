<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Types — one row per bookable service ("30-min Demo", "Salon Cut").
 * Each carries its own duration/buffers/availability/financials/templates.
 * Extends the existing single-config appointment scaffold into multi-service.
 * WhatsApp-based booking (channel columns default to whatsapp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->enum('location_type', ['address', 'virtual', 'phone'])->default('address');
            $table->text('location_value')->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('increment_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('min_notice_minutes')->default(240);
            $table->unsignedSmallInteger('max_advance_days')->default(30);
            $table->unsignedSmallInteger('max_per_day')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('timezone', 64)->nullable();
            $table->text('intro_message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active']);
            $table->unique(['workspace_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_types');
    }
};
