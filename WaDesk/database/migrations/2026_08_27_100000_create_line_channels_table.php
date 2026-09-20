<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINE channel (LINE Messaging API) — Phase 1 (MVP). A bot-token + webhook
 * channel modelled on Telegram: a workspace connects one or more LINE Official
 * Accounts by pasting the channel access token + channel secret. Threads live in
 * the shared `conversations` table as channel='line', raw_jid
 * 'line:<lineChannelRowId>:<userId|groupId|roomId>' (the row id is encoded in the
 * jid — this repo has no channel_connection columns, so the reply is routed by
 * parsing the jid, exactly like Telegram's 'tg:<botId>:<chatId>').
 *
 * Secrets (channel_access_token + channel_secret) are encrypted at rest by the
 * model casts. channel_secret is stored (unlike Telegram's echoed header token)
 * because LINE inbound is authenticated by verifying the X-Line-Signature HMAC of
 * the raw body with the channel secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->text('channel_access_token');              // encrypted — Bearer for all sends
            $table->text('channel_secret');                    // encrypted — HMAC key for X-Line-Signature
            $table->string('webhook_token', 64)->unique();     // routes /api/line/inbound/{token}
            $table->string('line_channel_id', 32)->nullable()->index(); // numeric LINE channel id
            $table->string('basic_id', 32)->nullable();        // OA @-id (basicId from /v2/bot/info)
            $table->string('display_name', 128)->nullable();   // OA display name
            $table->string('picture_url', 255)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_channels');
    }
};
