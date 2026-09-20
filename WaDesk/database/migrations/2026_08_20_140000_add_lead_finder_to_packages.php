<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Finder plan gate — the MISSING column.
 *
 * The Lead Finder feature shipped `access_lead_finder` in the Package model
 * ($fillable + $casts) and the admin plan-feature editor, but no migration ever
 * added the column to `packages`. Result: saving ANY plan throws
 * "SQLSTATE[42S22] Unknown column 'access_lead_finder' in 'SET'". This adds it.
 *
 * Dedicated access_* flag (never bundled), consistent with every other gate.
 * Guarded with hasColumn so it is safe on installs that already patched it by
 * hand, and idempotent under the resilient updater.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('packages', 'access_lead_finder')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            // No ->after() on purpose: it would require access_sales_pipeline to
            // already exist, which isn't guaranteed on every install. Appending
            // is fine — column order is cosmetic.
            $table->boolean('access_lead_finder')->default(false);
        });

        // Seed ON for the highlighted/popular tier; admin re-toggles per plan
        // from the plan-feature editor afterwards.
        try {
            DB::table('packages')->where('is_highlighted', true)->update(['access_lead_finder' => true]);
        } catch (\Throwable $e) {
            // is_highlighted absent on a custom schema — skip; admin toggles it.
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('packages', 'access_lead_finder')) {
            return;
        }
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('access_lead_finder');
        });
    }
};
