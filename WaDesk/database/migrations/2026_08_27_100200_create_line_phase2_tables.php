<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINE Phase 2 — parity tables. Mirrors the Telegram broadcast + flow-session
 * schema (2026_08_16_000400_create_telegram_tables.php), swapping Telegram's
 * chat_id for LINE's userId and dropping Telegram-only columns
 * (media_file_id/secret_token/payment tokens — LINE re-sends media by URL and
 * has no bot-payment token).
 *
 *  - line_broadcasts / line_broadcast_recipients — LINE's own broadcast pipeline
 *    (a LINE OA has no phone-number audience, so it ships separate from core WA
 *    campaigns, exactly like Telegram). recipient line_user_id stays plaintext so
 *    it is queryable; body is encrypted by the model cast.
 *  - line_flow_sessions — parity with telegram_flow_sessions for the LINE flow
 *    runtime bridge (the live Node flow engine parks a waiting node here).
 *
 * conversations.channel + flows.flow_type are string columns → 'line' is written
 * directly, no enum change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('line_flow_sessions')) {
            Schema::create('line_flow_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('conversation_id')->unique(); // one live session per thread
                $table->unsignedBigInteger('line_channel_id')->index();
                $table->string('line_user_id', 64);
                $table->unsignedBigInteger('flow_id')->nullable()->index();
                $table->string('node_id', 64)->nullable();
                $table->string('status', 16)->default('running')->index(); // running|waiting|sleeping|done|failed
                $table->string('await_var', 64)->nullable();
                $table->text('await_options')->nullable();
                $table->text('vars')->nullable();                  // encrypted:array
                $table->string('last_error', 255)->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->string('last_message_id', 64)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('line_broadcasts')) {
            Schema::create('line_broadcasts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('line_channel_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name', 191)->nullable();
                $table->unsignedBigInteger('template_id')->nullable();
                $table->longText('body')->nullable();              // encrypted
                $table->text('buttons')->nullable();               // encrypted:array
                $table->string('media_path', 255)->nullable();
                $table->string('media_kind', 16)->nullable();
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
        }

        if (! Schema::hasTable('line_broadcast_recipients')) {
            Schema::create('line_broadcast_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('line_broadcast_id')->index();
                $table->string('line_user_id', 64)->index();       // queryable — not encrypted
                $table->string('title', 191)->nullable();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('status', 16)->default('pending')->index(); // pending|sent|failed|blocked
                $table->string('provider_message_id', 64)->nullable();
                $table->string('error', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['line_broadcast_id', 'status'], 'line_bcast_recip_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('line_broadcast_recipients');
        Schema::dropIfExists('line_broadcasts');
        Schema::dropIfExists('line_flow_sessions');
    }
};
