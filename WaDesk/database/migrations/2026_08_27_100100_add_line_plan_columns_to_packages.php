<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINE channel plan flags on `packages` (settable in Admin → Packages, enforced
 * by plan:access_line). Mirrors the Telegram plan columns. line_broadcasts +
 * line_channels_limit are for the later broadcast/limit phases but added now so
 * the schema is stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }
        Schema::table('packages', function (Blueprint $table) {
            foreach (['access_line', 'line_broadcasts'] as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $table->boolean($col)->default(false);
                }
            }
            foreach (['line_channels_limit'] as $col) {
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
            foreach (['access_line', 'line_broadcasts', 'line_channels_limit'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
