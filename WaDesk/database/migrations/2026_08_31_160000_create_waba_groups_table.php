<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Cloud API groups (Meta's official Groups API).
 *
 * Deliberately SEPARATE from `wa_groups`, which holds Unofficial-API groups
 * keyed on `device_phone` + a `…@g.us` jid. A Cloud API group is keyed on the
 * provider account + an opaque base64 `group_id` (no jid at all), and carries
 * invite link / approval mode / suspension state that the Unofficial side has
 * no concept of. Sharing one table would guarantee a routing bug the first time
 * an id from one engine reached the other's send path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('waba_groups')) {
            return;
        }

        Schema::create('waba_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            // The WABA account that owns the group. Groups belong to a phone
            // number, so a workspace with two WABA numbers has two group sets.
            $table->unsignedBigInteger('provider_config_id')->index();

            // Meta's opaque group id, e.g.
            // Y2FwaV9ncm91cDoxNzA1NTU1MDEzOToxMjAzNjM0MDQ2OTQyMzM4MjAZD
            // Not a phone, not a jid — never parse it.
            $table->string('group_id', 191);

            $table->string('subject', 191)->nullable();
            $table->text('description')->nullable();

            // Arrives via the group_lifecycle_update WEBHOOK, not in the create
            // response — so a freshly created group is legitimately link-less
            // for a moment.
            $table->string('invite_link', 255)->nullable();

            // approval_required | auto_approve
            $table->string('join_approval_mode', 32)->nullable();

            $table->unsignedInteger('participant_count')->default(0);

            // group_status_update: group_suspend / group_suspend_cleared. A
            // suspended group cannot be used until Meta clears it.
            $table->boolean('suspended')->default(false);

            $table->timestamp('creation_timestamp')->nullable();
            $table->timestamp('synced_at')->nullable();

            // Everything else Meta returns (participants sample, raw webhook
            // payloads, last error) without a column per field.
            $table->json('meta_json')->nullable();

            $table->timestamps();

            // One row per group per account. Meta's group_id is unique per
            // phone number, not globally, so the account is part of the key.
            $table->unique(['provider_config_id', 'group_id'], 'waba_groups_account_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waba_groups');
    }
};
