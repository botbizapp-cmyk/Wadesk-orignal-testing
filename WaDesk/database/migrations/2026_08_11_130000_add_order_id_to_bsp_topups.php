<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a BSP wallet top-up to the WaDesk Order that pays for it. The order runs
 * through the SAME gateway + callback + finalize machinery as a plan purchase;
 * finalizeOrder() then credits the BSP wallet via BillingBridge, keyed on this
 * order_id (idempotent — credit exactly once, only after real payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bsp_topups') && ! Schema::hasColumn('bsp_topups', 'order_id')) {
            Schema::table('bsp_topups', function (Blueprint $t) {
                $t->unsignedBigInteger('order_id')->nullable()->after('account_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bsp_topups') && Schema::hasColumn('bsp_topups', 'order_id')) {
            Schema::table('bsp_topups', function (Blueprint $t) {
                $t->dropColumn('order_id');
            });
        }
    }
};
