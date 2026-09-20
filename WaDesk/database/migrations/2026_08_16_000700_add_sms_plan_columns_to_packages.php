<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS channel plan flags on `packages` (settable in Admin → Packages, enforced
 * by plan:access_sms + PlanLimitGuard 'sms_monthly_limit'). Mirrors the
 * Facebook / TikTok / Telegram plan columns. sms_monthly_limit: 0 = unlimited.
 * SMS is billed to THIS cap, never the WhatsApp wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'access_sms')) {
                $table->boolean('access_sms')->default(false);
            }
            if (! Schema::hasColumn('packages', 'sms_monthly_limit')) {
                $table->integer('sms_monthly_limit')->default(0);   // 0 = unlimited
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }
        Schema::table('packages', function (Blueprint $table) {
            foreach (['access_sms', 'sms_monthly_limit'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
