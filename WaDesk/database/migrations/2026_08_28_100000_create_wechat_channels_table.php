<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WeChat channel — a workspace's connected WeChat Official Account (certified
 * Service Account). Modelled on line_channels, with the WeChat-specific pieces:
 *   - app_id / app_secret          → credentials for the shared access_token
 *   - verify_token / encoding_aes_key / enc_mode → webhook signature + optional
 *     AES "safe mode" decryption (SHA1(sort(token,timestamp,nonce)))
 *   - cached_access_token / token_expires_at → the CENTRALLY cached token (7200s
 *     TTL; fetching a new one invalidates the old, so it must be cached + locked)
 *   - wx_id (原始ID / ToUserName) → identifies the OA on inbound
 *
 * Threads live in the shared conversations table as channel='wechat', raw_jid
 * 'wechat:<channelRowId>:<openid>' (the row id is encoded → routes the reply,
 * no channel_connection columns, identical to LINE/Telegram). Secrets are
 * encrypted at rest by the model casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wechat_channels')) {
            Schema::create('wechat_channels', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('connected_by')->nullable();
                $table->string('app_id', 64)->nullable()->index();
                $table->text('app_secret');                         // encrypted
                $table->string('verify_token', 128);                // webhook Token (signature)
                $table->text('encoding_aes_key')->nullable();       // encrypted (safe mode)
                $table->string('enc_mode', 12)->default('compat');  // plain|compat|safe
                $table->string('webhook_token', 64)->unique();      // routes /api/wechat/inbound/{token}
                $table->string('wx_id', 64)->nullable()->index();   // the OA's original id (ToUserName)
                $table->string('account_name', 191)->nullable();
                $table->string('avatar_url', 512)->nullable();
                $table->text('cached_access_token')->nullable();    // encrypted, refreshed by the token manager
                $table->timestamp('token_expires_at')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_inbound_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                foreach (['access_wechat', 'wechat_broadcasts'] as $col) {
                    if (! Schema::hasColumn('packages', $col)) {
                        $table->boolean($col)->default(false);
                    }
                }
                if (! Schema::hasColumn('packages', 'wechat_channels_limit')) {
                    $table->integer('wechat_channels_limit')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_channels');
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                foreach (['access_wechat', 'wechat_broadcasts', 'wechat_channels_limit'] as $col) {
                    if (Schema::hasColumn('packages', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
