<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WeChat Phase 2 — flow-session + broadcast tables. Mirrors the LINE Phase 2
 * schema, keyed on the WeChat OpenID.
 *
 *  - wechat_flow_sessions — durable mirror of a live flow parked on a node (the
 *    Node engine keeps its own in-memory map, like every other channel).
 *  - wechat_broadcasts / wechat_broadcast_recipients — WeChat's own broadcast
 *    pipeline (mass-send / customer-service). recipient openid stays plaintext so
 *    it is queryable; body is encrypted by the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wechat_flow_sessions')) {
            Schema::create('wechat_flow_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('conversation_id')->unique();
                $table->unsignedBigInteger('wechat_channel_id')->index();
                $table->string('openid', 64);
                $table->unsignedBigInteger('flow_id')->nullable()->index();
                $table->string('node_id', 64)->nullable();
                $table->string('status', 16)->default('running')->index();
                $table->string('await_var', 64)->nullable();
                $table->text('await_options')->nullable();
                $table->text('vars')->nullable();                  // encrypted:array
                $table->string('last_error', 255)->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->string('last_message_id', 64)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wechat_broadcasts')) {
            Schema::create('wechat_broadcasts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('wechat_channel_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name', 191)->nullable();
                $table->unsignedBigInteger('template_id')->nullable();
                $table->longText('body')->nullable();              // encrypted
                $table->text('buttons')->nullable();               // encrypted:array
                $table->string('audience', 16)->default('list');   // list|tag|all
                $table->string('tag_id', 32)->nullable();
                $table->string('mass_msg_id', 64)->nullable();     // WeChat's returned msg_id
                $table->string('status', 16)->default('draft')->index(); // draft|sending|done|failed
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('sent')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->string('last_error', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wechat_broadcast_recipients')) {
            Schema::create('wechat_broadcast_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wechat_broadcast_id')->index();
                $table->string('openid', 64)->index();             // queryable — not encrypted
                $table->string('title', 191)->nullable();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('status', 16)->default('pending')->index();
                $table->string('error', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['wechat_broadcast_id', 'status'], 'wc_bcast_recip_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_broadcast_recipients');
        Schema::dropIfExists('wechat_broadcasts');
        Schema::dropIfExists('wechat_flow_sessions');
    }
};
