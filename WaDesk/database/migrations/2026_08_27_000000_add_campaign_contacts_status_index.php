<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance: composite index for the campaign send/drain hot path. The paced
 * send loop and the per-recipient status callbacks filter wp_campaign_contacts
 * by (campaign_id, status[, send_attempts]) constantly, but only single-column
 * indexes existed — so those queries range-scanned the campaign_id index and
 * filtered status in memory, a major MySQL-CPU cost once the recipient table
 * grows to millions of rows. Adding the composite makes them index-served.
 *
 * Idempotent + resilient (mirrors the updater's migration policy): does nothing
 * if the table/columns are missing or the index already exists, and never hard-
 * fails on a concurrent/duplicate add.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wp_campaign_contacts')) {
            return;
        }
        if (! Schema::hasColumn('wp_campaign_contacts', 'campaign_id')
            || ! Schema::hasColumn('wp_campaign_contacts', 'status')) {
            return;
        }

        try {
            $exists = collect(DB::select('SHOW INDEX FROM `wp_campaign_contacts`'))
                ->contains(fn ($r) => $r->Key_name === 'cc_campaign_status');
            if ($exists) {
                return;
            }
        } catch (\Throwable $e) {
            return; // can't introspect → leave the schema untouched
        }

        try {
            Schema::table('wp_campaign_contacts', function (Blueprint $t) {
                $cols = ['campaign_id', 'status'];
                if (Schema::hasColumn('wp_campaign_contacts', 'send_attempts')) {
                    $cols[] = 'send_attempts';
                }
                $t->index($cols, 'cc_campaign_status');
            });
        } catch (\Throwable $e) {
            // duplicate / concurrent add on a re-run — non-fatal
        }
    }

    public function down(): void
    {
        // Intentionally keep the index; dropping it only slows queries.
    }
};
