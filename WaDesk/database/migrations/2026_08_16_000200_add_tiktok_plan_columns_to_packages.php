<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok channel plan flags on `packages`, so they are settable in Admin →
 * Packages and enforced by the plan gate (plan:access_tiktok). Mirrors the
 * Facebook plan columns. Guarded so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }

        $features = [
            'access_tiktok', 'tiktok_inbox', 'tiktok_posts', 'tiktok_comments',
        ];
        $limits = [
            'tiktok_accounts_limit', 'tiktok_scheduled_posts_limit',
        ];

        Schema::table('packages', function (Blueprint $table) use ($features, $limits) {
            foreach ($features as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $table->boolean($col)->default(false);
                }
            }
            foreach ($limits as $col) {
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

        $cols = [
            'access_tiktok', 'tiktok_inbox', 'tiktok_posts', 'tiktok_comments',
            'tiktok_accounts_limit', 'tiktok_scheduled_posts_limit',
        ];

        Schema::table('packages', function (Blueprint $table) use ($cols) {
            foreach ($cols as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
