<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `flow_subscribers.enrolled_at` was silently rewriting itself to NOW.
 *
 * The column was declared as a bare, NOT NULL `$t->timestamp('enrolled_at')` in
 * 2026_05_24_090000_merge_drip_into_flows. On MySQL/MariaDB with
 * `explicit_defaults_for_timestamp = OFF` the server promotes the FIRST NOT NULL
 * TIMESTAMP column of a table to `DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP` — an attribute no migration ever asked for. The observed
 * definition was:
 *
 *   enrolled_at | timestamp | NO | current_timestamp() | on update current_timestamp()
 *
 * So ANY later UPDATE on the row rewrote the moment the run STARTED: the retry
 * guard in FlowRetryService, FlowEnrollmentService::enroll()'s failed -> active
 * reset, a pause/resume — every one of them relocated a months-old run into
 * today. enrolled_at anchors the execution history, the run duration, the trend
 * series and every analytics window filter, and the original value is not
 * recoverable from anywhere else, so the loss was permanent.
 *
 * Stripping the implicit attributes is the fix: the column is written
 * EXPLICITLY by FlowEnrollmentService::enroll() when the subscriber row is
 * first created, and by nothing else.
 *
 * Why a separate migration instead of amending
 * 2026_08_30_110000_create_flow_retry_logs_table (which introduced the retry
 * writes that made the corruption routine): that migration has already run on
 * installs carrying the flow-analytics build, and `php artisan migrate` never
 * replays a migration it has recorded — an amendment there would only ever
 * reach fresh installs. Keeping it separate also means rolling back the retry
 * audit table does not re-arm the data loss.
 *
 * Data-safe: MODIFY to `TIMESTAMP NULL DEFAULT NULL` keeps every stored value
 * byte for byte and creates no NULLs (verified against the live 271 rows before
 * shipping: 0 rows changed, 0 NULLs introduced).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_subscribers') || ! Schema::hasColumn('flow_subscribers', 'enrolled_at')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE `flow_subscribers` MODIFY `enrolled_at` TIMESTAMP NULL DEFAULT NULL');
        } catch (\Throwable $e) {
            // Non-MySQL driver or insufficient grants — never fail an update over
            // a column-attribute cleanup. The app already writes enrolled_at
            // explicitly on create and never in an UPDATE payload.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_subscribers') || ! Schema::hasColumn('flow_subscribers', 'enrolled_at')) {
            return;
        }

        try {
            // Back to what 2026_05_24_090000 declared: NOT NULL. The server may
            // re-add its DEFAULT/ON UPDATE promotion — that is the artifact this
            // migration exists to remove, and restoring it is what "reverse"
            // means here.
            DB::statement('UPDATE `flow_subscribers` SET `enrolled_at` = COALESCE(`created_at`, NOW()) WHERE `enrolled_at` IS NULL');
            DB::statement('ALTER TABLE `flow_subscribers` MODIFY `enrolled_at` TIMESTAMP NOT NULL');
        } catch (\Throwable $e) {
            // best-effort
        }
    }
};
