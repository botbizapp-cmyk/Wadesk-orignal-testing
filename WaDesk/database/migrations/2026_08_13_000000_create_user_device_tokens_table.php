<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-app push registration. One row per (device, token) a signed-in user
 * has. Powers FCM/APNs notifications when the app is backgrounded/killed — the
 * "app closed → message still notifies" leg of the live-chat plan.
 *
 * Scoped by workspace_id + device_id so the inbound push handler only notifies
 * the operators pinned to the number that received the message (same scoping
 * the /inbox delta + campaign preflight already use). The FCM token itself can
 * exceed a safe index length, so uniqueness rides on a sha256 `token_hash`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('workspace_id')->nullable()->index();
            // The mobile device the user has pinned (X-Device-Id). Null = all.
            $t->unsignedBigInteger('device_id')->nullable()->index();
            $t->text('fcm_token');                    // full token (can be long)
            $t->char('token_hash', 64)->unique();     // sha256(fcm_token) — dedupe/upsert key
            $t->string('platform', 16)->nullable();   // android | ios
            $t->json('device_info')->nullable();      // model / os_version (optional)
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'device_id']); // inbound push recipient lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
