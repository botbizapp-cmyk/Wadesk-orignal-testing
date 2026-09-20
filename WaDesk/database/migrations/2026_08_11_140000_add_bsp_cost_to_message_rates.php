<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BSP full-merge: instead of a parallel bsp_rate_cards table, the BSP layer
 * rides the EXISTING per-country/category `message_rates` table (the one the
 * /admin/settings/wallet-rules page already edits). Each rate row already holds
 * the CUSTOMER price (`credits`). We add two nullable columns so the same row
 * can also carry Meta's WHOLESALE cost — enabling margin (revenue − cost) without
 * a second wallet or a second rate page.
 *
 *   - meta_cost_minor : what Meta bills WaDesk for this country×category, in
 *                       MINOR units of `currency` (paise/cents). NULL = unknown
 *                       (margin simply not computed for that row).
 *   - currency        : ISO currency of meta_cost_minor (e.g. USD, INR).
 *
 * Both are nullable and unused by the core credits engine, so when the BSP
 * add-on is absent the wallet-rules page behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_rates')) {
            return; // core table not present yet — nothing to extend
        }
        Schema::table('message_rates', function (Blueprint $table) {
            if (! Schema::hasColumn('message_rates', 'meta_cost_minor')) {
                $table->unsignedBigInteger('meta_cost_minor')->nullable()->after('credits');
            }
            if (! Schema::hasColumn('message_rates', 'currency')) {
                $table->string('currency', 8)->nullable()->after('meta_cost_minor');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('message_rates')) {
            return;
        }
        Schema::table('message_rates', function (Blueprint $table) {
            foreach (['meta_cost_minor', 'currency'] as $col) {
                if (Schema::hasColumn('message_rates', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
