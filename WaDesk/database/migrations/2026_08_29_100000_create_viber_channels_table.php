<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viber channel — a workspace's connected Viber Public Account / Bot. Modelled on
 * line_channels: a STATIC auth token (X-Viber-Auth-Token, no exchange/expiry) sends
 * every request and is the HMAC key for the webhook signature. We auto-register our
 * per-channel webhook URL via set_webhook on connect.
 *
 * Threads live in the shared conversations table as channel='viber', raw_jid
 * 'viber:<channelRowId>:<viberUserId>' (row id encoded → routes the reply, no
 * channel_connection columns). auth_token is encrypted at rest by the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('viber_channels')) {
            Schema::create('viber_channels', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('connected_by')->nullable();
                $table->text('auth_token');                         // encrypted
                $table->string('webhook_token', 64)->unique();      // routes /api/viber/inbound/{token}
                $table->string('viber_id', 64)->nullable()->index();// the OA's 'pa:…' account id
                $table->string('bot_name', 128)->nullable();
                $table->string('bot_uri', 128)->nullable();
                $table->string('bot_avatar', 512)->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_inbound_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                foreach (['access_viber', 'viber_broadcasts'] as $col) {
                    if (! Schema::hasColumn('packages', $col)) {
                        $table->boolean($col)->default(false);
                    }
                }
                if (! Schema::hasColumn('packages', 'viber_channels_limit')) {
                    $table->integer('viber_channels_limit')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('viber_channels');
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                foreach (['access_viber', 'viber_broadcasts', 'viber_channels_limit'] as $col) {
                    if (Schema::hasColumn('packages', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
