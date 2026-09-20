<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA REPAIR — Auto-responder (welcome / away / out_of_hours) rules that are
 * bound to a real Unofficial (Baileys) device but were mis-stamped with another
 * engine (e.g. 'waba' on a WABA-default workspace) never fired: the Baileys
 * lookup filters `provider = baileys OR NULL OR ''`, so a 'waba'-stamped rule on
 * a Baileys device was silently excluded.
 *
 * A keyword_replies row whose `device_id` points at a real `devices` row is,
 * by definition, a Baileys rule — WABA/Twilio senders live in
 * wa_provider_configs, never `devices`. So we can safely re-stamp any such row
 * to 'baileys'. Idempotent + guarded; leaves NULL/'' and correctly-stamped rows
 * untouched. The store()-side fallback fix prevents new rows from mis-stamping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keyword_replies') || ! Schema::hasTable('devices')) {
            return;
        }
        if (! Schema::hasColumn('keyword_replies', 'trigger_type')
            || ! Schema::hasColumn('keyword_replies', 'provider')) {
            return;
        }

        DB::table('keyword_replies')
            ->whereIn('trigger_type', ['welcome', 'away', 'out_of_hours'])
            ->whereNotNull('device_id')
            ->whereNotNull('provider')
            ->where('provider', '!=', 'baileys')
            ->whereIn('device_id', function ($q) {
                $q->select('id')->from('devices');
            })
            ->update(['provider' => 'baileys']);
    }

    public function down(): void
    {
        // No-op — the original (incorrect) provider value is not recoverable and
        // restoring it would re-break the auto-responder. Intentionally empty.
    }
};
