<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the AI CRM Copilot.
 *
 * Every tool the copilot RUNS (read or write) is logged here so a workspace
 * has a tamper-evident record of what the AI did on their CRM, who triggered
 * it, from which channel, and what the outcome was. Write/money tools require
 * confirm-before-act; the confirmation state is recorded too. Params are
 * stored already-masked (phone/email) — never raw PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_crm_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // staff member who commanded
            $table->string('channel', 24)->default('dashboard');        // dashboard | whatsapp
            $table->string('tool', 64)->index();                        // e.g. create_deal
            $table->string('kind', 8)->default('read');                 // read | write
            $table->string('status', 16)->default('ok');                // ok | error | needs_confirm | confirmed | cancelled
            $table->json('params')->nullable();                         // masked args
            $table->text('result_summary')->nullable();                 // short human summary
            $table->string('provider', 24)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->string('subject_type', 64)->nullable();             // Deal | Contact | ...
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_crm_actions');
    }
};
