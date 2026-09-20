<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-message billing ledger — ONE row per outbound WhatsApp message,
 * created the moment the message is confirmed sent/delivered by the engine.
 *
 * This is the single source of truth for "was this message billed?", which
 * makes the whole billing system:
 *   - LEAK-PROOF: every delivered message (any feature, any engine) lands here
 *     via the delivery-status webhook, so no send path can forget to charge.
 *   - IDEMPOTENT: wamid is UNIQUE, so a re-delivered / retried status webhook
 *     can never double-charge.
 *   - REFUNDABLE: a later 'failed' status finds the row by wamid and reverses
 *     the exact charge.
 *   - REPORTABLE: the admin P&L reads revenue/cost straight from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('message_charges')) {
            return;
        }

        Schema::create('message_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // wallet owner who paid
            $table->string('provider', 16)->nullable();                   // waba | baileys | twilio
            $table->string('wamid', 191)->unique();                       // engine message id = idempotency key
            $table->string('to_country', 2)->nullable();
            $table->string('category', 24)->nullable();                   // marketing|utility|authentication|service
            $table->string('source', 32)->nullable();                     // chat|campaign|broadcast|flow|scheduled|autoreply|api
            $table->unsignedInteger('credits')->default(0);               // credits actually charged (0 = free)
            $table->bigInteger('revenue_minor')->default(0);              // what customer paid, platform minor units
            $table->bigInteger('cost_minor')->nullable();                 // Meta wholesale cost, platform minor units
            $table->enum('status', ['free', 'charged', 'refunded'])->default('charged')->index();
            $table->unsignedBigInteger('wallet_tx_id')->nullable();       // link to the debit wallet_transactions row
            $table->json('meta')->nullable();
            $table->timestamps();

            // Monthly per-workspace rollups (P&L + quota counting).
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_charges');
    }
};
