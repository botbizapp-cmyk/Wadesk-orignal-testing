<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook Messenger broadcasts — parity with Telegram broadcasts. Messenger has
 * no phone-number audience and Meta's standard messaging only permits sends to a
 * PSID that messaged the Page within the last 24h, so this ships its own pipeline
 * separate from core WhatsApp campaigns (which target phone numbers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('facebook_page_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 191)->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->longText('body')->nullable();      // encrypted
            $table->json('buttons')->nullable();        // encrypted:array
            $table->string('status', 16)->default('draft')->index(); // draft|sending|done|failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('facebook_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('facebook_broadcast_id')->index();
            $table->string('psid', 64)->index();        // queryable — not encrypted
            $table->string('title', 191)->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending|sent|failed|blocked
            $table->string('provider_message_id', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['facebook_broadcast_id', 'status'], 'fb_bcast_recip_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_broadcast_recipients');
        Schema::dropIfExists('facebook_broadcasts');
    }
};
