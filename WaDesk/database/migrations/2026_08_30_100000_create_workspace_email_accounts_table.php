<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workspace_email_accounts — a workspace's MIRROR of an email account that
 * physically lives on the linked MailTrixy install. The real email engine
 * (mailbox OAuth/IMAP, sending, sync) stays on MailTrixy; WaDesk stores only
 * what it needs to render the mailbox as a channel on /devices and resolve an
 * inbound push to a workspace: the MailTrixy account id + a cached snapshot.
 * `mailtrixy_account_id` is the MailTrixy Account row id; `mtx_workspace_id`
 * is the MailTrixy-side workspace that owns it (informational — the WaDesk
 * workspace is this row's workspace_id). One row per
 * (workspace, mailtrixy_account_id). Idempotent for re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_email_accounts')) return;

        Schema::create('workspace_email_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            // MailTrixy's Account id — the inbound push resolves on this.
            $table->unsignedBigInteger('mailtrixy_account_id')->index();
            $table->unsignedBigInteger('mtx_workspace_id')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('status', 32)->default('connected');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // One mirror row per workspace + MailTrixy account.
            $table->unique(['workspace_id', 'mailtrixy_account_id'], 'ws_email_accounts_ws_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_email_accounts');
    }
};
