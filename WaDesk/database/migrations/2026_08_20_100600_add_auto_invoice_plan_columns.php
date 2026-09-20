<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto-invoice plan-feature flags (admin-settable, resolved via PlanLimitGuard). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'auto_invoice')) {
                $table->boolean('auto_invoice')->default(false);
            }
            if (! Schema::hasColumn('packages', 'auto_invoice_max_monthly')) {
                $table->integer('auto_invoice_max_monthly')->default(0); // 0 = unlimited
            }
            if (! Schema::hasColumn('packages', 'auto_invoice_branding')) {
                $table->boolean('auto_invoice_branding')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            foreach (['auto_invoice', 'auto_invoice_max_monthly', 'auto_invoice_branding'] as $c) {
                if (Schema::hasColumn('packages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
