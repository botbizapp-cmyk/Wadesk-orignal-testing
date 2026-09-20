<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BSP module — core tables (P0). All idempotent (guarded by hasTable) so the
 * self-contained module deploys cleanly via the updater ZIP + can re-run. When
 * the addon folder is absent/disabled these tables simply aren't created and
 * the core "customer pays Meta directly" flow is untouched.
 *
 *  bsp_rate_cards         platform rate card: Meta base cost per country×category×tier
 *  bsp_accounts           per-workspace enrolment: mode (bsp|tech) + wallet + markup
 *  bsp_wallet_ledger      append-only money ledger (delete-proof) — bsp mode only
 *  bsp_usage_events       one row per delivered message (wamid, unique) — both modes
 *  bsp_credit_allocations per-WABA credit-line attach record — bsp mode
 *  bsp_topup_packages     admin buy-credit bundles
 *  bsp_topups             top-up payment lifecycle (credit wallet once, on paid)
 *
 * Money is stored in MINOR units (paise/cents) as integers — never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bsp_rate_cards')) {
            Schema::create('bsp_rate_cards', function (Blueprint $t) {
                $t->id();
                $t->string('country_code', 8)->index();           // region, e.g. IN, US
                $t->string('category', 32)->index();              // marketing|utility|authentication|authentication_international|marketing_lite|service|referral_conversion
                $t->unsignedBigInteger('tier_min')->default(0);   // volume tier lower bound
                $t->unsignedBigInteger('tier_max')->nullable();   // upper bound (null = open-ended)
                $t->bigInteger('base_rate_minor')->default(0);    // Meta base cost in minor units of `currency`
                $t->string('currency', 8)->default('USD');
                $t->date('effective_from')->nullable();
                $t->string('source', 64)->nullable();             // manual|csv|manager
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['country_code', 'category', 'tier_min']);
            });
        }

        if (! Schema::hasTable('bsp_accounts')) {
            Schema::create('bsp_accounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->unique();
                $t->string('mode', 8)->default('bsp');            // bsp | tech
                $t->string('status', 16)->default('active');      // active | suspended | off
                $t->string('currency', 8)->default('USD');
                $t->string('credit_source', 16)->nullable();      // meta | aggregator | null
                // Markup (null = fall back to platform default SystemSetting).
                $t->string('markup_type', 8)->nullable();         // percent | fixed
                $t->decimal('markup_value', 12, 4)->nullable();
                // Wallet (bsp mode only).
                $t->bigInteger('balance_minor')->default(0);
                $t->bigInteger('low_balance_threshold_minor')->default(0);
                $t->boolean('auto_recharge_enabled')->default(false);
                $t->bigInteger('auto_recharge_amount_minor')->default(0);
                $t->bigInteger('spend_limit_daily_minor')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('bsp_wallet_ledger')) {
            Schema::create('bsp_wallet_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->index();
                $t->string('direction', 8);                       // debit | credit
                $t->bigInteger('amount_minor');
                $t->string('reason', 24);                         // message | topup | refund | adjustment
                $t->string('ref_type', 32)->nullable();           // wamid | order | manual
                $t->string('ref_id', 191)->nullable();
                $t->bigInteger('balance_after_minor');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['account_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('bsp_usage_events')) {
            Schema::create('bsp_usage_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('waba_id', 64)->nullable();
                $t->string('phone_number_id', 64)->nullable();
                $t->string('wamid', 191)->unique();               // idempotency: one billable event per message
                $t->string('recipient_country', 8)->nullable();
                $t->string('category', 32)->nullable();
                $t->string('tier', 32)->nullable();
                $t->string('pricing_type', 32)->nullable();       // regular | free_customer_service | free_entry_point
                $t->boolean('billable')->default(false);
                $t->bigInteger('base_rate_minor')->default(0);    // Meta cost
                $t->bigInteger('customer_rate_minor')->default(0);// after markup (bsp mode)
                $t->bigInteger('markup_minor')->default(0);
                $t->string('currency', 8)->default('USD');
                $t->timestamp('delivered_at')->nullable();
                $t->timestamps();
                $t->index(['workspace_id', 'category', 'delivered_at']);
            });
        }

        if (! Schema::hasTable('bsp_credit_allocations')) {
            Schema::create('bsp_credit_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('waba_id', 64)->index();
                $t->string('allocation_config_id', 64)->nullable();
                $t->string('credit_source', 16)->nullable();      // meta | aggregator
                $t->string('currency', 8)->default('USD');
                $t->string('status', 16)->default('attached');    // attached | revoked | failed
                $t->text('last_error')->nullable();
                $t->timestamp('attached_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('bsp_topup_packages')) {
            Schema::create('bsp_topup_packages', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->bigInteger('amount_minor');                   // what the user pays
                $t->bigInteger('bonus_minor')->default(0);        // extra credit granted on top
                $t->string('currency', 8)->default('USD');
                $t->boolean('is_popular')->default(false);
                $t->integer('sort_order')->default(0);
                $t->boolean('status')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('bsp_topups')) {
            Schema::create('bsp_topups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedBigInteger('package_id')->nullable();
                $t->bigInteger('amount_minor');                   // paid amount
                $t->bigInteger('bonus_minor')->default(0);
                $t->bigInteger('credited_minor')->default(0);     // amount + bonus, on success
                $t->string('currency', 8)->default('USD');
                $t->string('gateway', 40)->nullable();            // stripe/razorpay/… (existing drivers)
                $t->string('gateway_ref', 191)->nullable();
                $t->string('status', 16)->default('pending');     // pending | paid | failed | refunded
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
                $t->index(['workspace_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'bsp_topups', 'bsp_topup_packages', 'bsp_credit_allocations',
            'bsp_usage_events', 'bsp_wallet_ledger', 'bsp_accounts', 'bsp_rate_cards',
        ] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
