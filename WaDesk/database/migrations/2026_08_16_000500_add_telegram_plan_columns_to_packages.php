<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram channel plan flags on `packages` (settable in Admin → Packages,
 * enforced by plan:access_telegram). Mirrors the Facebook/TikTok plan columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }
        Schema::table('packages', function (Blueprint $table) {
            foreach (['access_telegram', 'telegram_broadcasts'] as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $table->boolean($col)->default(false);
                }
            }
            foreach (['telegram_bots_limit'] as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $table->integer($col)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }
        Schema::table('packages', function (Blueprint $table) {
            foreach (['access_telegram', 'telegram_broadcasts', 'telegram_bots_limit'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
