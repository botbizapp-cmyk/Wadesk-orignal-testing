<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * BSP full-merge cleanup: the reseller wallet/rate/top-up now rides the CORE
 * system (users.wallet_credits + message_rates + wallet_transactions), so the
 * parallel bsp_* tables are dropped. Only bsp_credit_allocations survives — it
 * backs the genuinely-new Meta credit-line (Path A) attach feature.
 *
 * Guarded with hasTable so it's a no-op on fresh installs where these were
 * never created (or already dropped). Irreversible by design; down() is a stub.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'bsp_wallet_ledger',
            'bsp_usage_events',
            'bsp_topups',
            'bsp_topup_packages',
            'bsp_rate_cards',
            'bsp_accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // One-way cleanup — the merged core wallet is the source of truth now.
    }
};
