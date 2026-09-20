<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viber Phase 2 — broadcast + flow-session tables. Mirrors the LINE Phase 2 schema,
 * keyed on the Viber user id. Broadcasts use Viber's broadcast_message (≤300
 * subscribed receivers per call) drained in batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('viber_flow_sessions')) {
            Schema::create('viber_flow_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('conversation_id')->unique();
                $table->unsignedBigInteger('viber_channel_id')->index();
                $table->string('viber_user_id', 64);
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

        if (! Schema::hasTable('viber_broadcasts')) {
            Schema::create('viber_broadcasts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('viber_channel_id')->index();
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
                $table->string('last_error', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('viber_broadcast_recipients')) {
            Schema::create('viber_broadcast_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('viber_broadcast_id')->index();
                $table->string('viber_user_id', 64)->index();      // queryable — not encrypted
                $table->string('title', 191)->nullable();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('status', 16)->default('pending')->index(); // pending|sent|failed
                $table->string('error', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['viber_broadcast_id', 'status'], 'vb_bcast_recip_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('viber_broadcast_recipients');
        Schema::dropIfExists('viber_broadcasts');
        Schema::dropIfExists('viber_flow_sessions');
    }
};
