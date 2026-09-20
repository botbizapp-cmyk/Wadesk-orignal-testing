<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WABA campaigns: store the click count Meta itself reports via the
 * template_analytics API, separate from the redirect-based `clicked_count`.
 *
 * Meta gives NO click webhook, so template button clicks were always 0. The
 * template_analytics endpoint returns them — but aggregate per template, not
 * per recipient — so we keep it in its OWN column and the UI shows the greater
 * of the two, never summing (both measure the same taps two different ways).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wpcampaigns')) return;

        Schema::table('wpcampaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('wpcampaigns', 'meta_clicked_count')) {
                $table->unsignedInteger('meta_clicked_count')->default(0)->after('clicked_count');
            }
            if (!Schema::hasColumn('wpcampaigns', 'meta_analytics_synced_at')) {
                $table->timestamp('meta_analytics_synced_at')->nullable()->after('meta_clicked_count');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('wpcampaigns')) return;
        Schema::table('wpcampaigns', function (Blueprint $table) {
            foreach (['meta_clicked_count', 'meta_analytics_synced_at'] as $col) {
                if (Schema::hasColumn('wpcampaigns', $col)) $table->dropColumn($col);
            }
        });
    }
};
