<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questionnaire fields per booking type — asked in the chat booking flow after
 * the slot is reserved. Answers with map_to_contact_field update/create the
 * Contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('booking_type_id')->constrained('booking_types')->cascadeOnDelete();
            $table->string('label', 191);
            $table->enum('type', ['text', 'textarea', 'select', 'number', 'email', 'phone', 'date']);
            $table->json('options')->nullable();
            $table->boolean('required')->default(true);
            $table->string('map_to_contact_field', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_questions');
    }
};
