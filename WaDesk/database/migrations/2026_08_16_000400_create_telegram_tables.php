<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram channel — merged from the standalone WaDesk Telegram integration and
 * adapted to our core-channel pattern (like Facebook/TikTok). Consolidates the
 * source's ~13 incremental migrations into the final schema. Bot + payment
 * tokens + MTProto session are encrypted at rest by the model casts.
 *
 * A workspace can connect several bots and several user accounts. Threads live
 * in the shared `conversations` table as channel='telegram', raw_jid
 * 'tg:<botId>:<chatId>' (the bot id is encoded in the jid — this repo has no
 * channel_connection columns, so we route the reply by parsing the jid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->text('bot_token');                       // encrypted
            $table->string('bot_username', 64)->nullable();
            $table->string('bot_name', 128)->nullable();
            $table->string('bot_id', 32)->nullable()->index();
            $table->string('webhook_token', 64)->unique();   // routes /api/telegram/inbound/{token}
            $table->string('secret_token', 64)->nullable();  // verifies X-Telegram-Bot-Api-Secret-Token
            $table->boolean('active')->default(true)->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->text('payment_provider_token')->nullable(); // encrypted
            $table->string('payment_provider', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->longText('session')->nullable();          // encrypted MTProto session
            $table->string('tg_user_id', 32)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('first_name', 128)->nullable();
            $table->string('phone', 24)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'tg_user_id']);
        });

        Schema::create('telegram_flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('conversation_id')->unique(); // one live session per thread
            $table->unsignedBigInteger('telegram_bot_id')->index();
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('flow_id')->nullable()->index();
            $table->string('node_id', 64)->nullable();
            $table->string('status', 16)->default('running')->index(); // running|waiting|sleeping|done|failed
            $table->string('await_var', 64)->nullable();
            $table->text('await_options')->nullable();
            $table->text('vars')->nullable();                 // encrypted:array
            $table->string('last_error', 255)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('last_message_id', 32)->nullable();
            $table->string('poll_id', 64)->nullable()->index();
            $table->string('invoice_payload', 64)->nullable()->index();
            $table->string('payment_link_id', 128)->nullable()->index();
            $table->string('payment_ref', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('telegram_bot_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 191)->nullable();
            $table->longText('body')->nullable();             // encrypted
            $table->string('media_path', 255)->nullable();
            $table->string('media_kind', 16)->nullable();
            $table->string('media_file_id', 255)->nullable(); // reused after first send
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

        Schema::create('telegram_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_broadcast_id')->index();
            $table->string('chat_id', 32)->index();           // queryable — not encrypted
            $table->string('title', 191)->nullable();
            $table->string('kind', 16)->nullable();           // private|group
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending|sent|failed|blocked
            $table->string('provider_message_id', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['telegram_broadcast_id', 'status'], 'tg_bcast_recip_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_broadcast_recipients');
        Schema::dropIfExists('telegram_broadcasts');
        Schema::dropIfExists('telegram_flow_sessions');
        Schema::dropIfExists('telegram_accounts');
        Schema::dropIfExists('telegram_bots');
    }
};
