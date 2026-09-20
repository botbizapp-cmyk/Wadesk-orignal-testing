<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow analytics — retry records.
 *
 * Execution history and error reasons already live on `flow_subscribers`
 * (enrolled_at / completed_at / failed_at / failure_reason / status). The one
 * thing the analytics surface needs that has never been tracked is RETRIES:
 * who re-ran a failed run, when, what it had failed with, and what happened.
 *
 * `flow_retry_logs` is that audit trail — one row per retry attempt, written
 * by App\Services\Flow\FlowRetryService before the re-enrolment and stamped
 * with the outcome after it.
 *
 * The two columns added to `flow_subscribers` are the denormalised answer to
 * "how many times has this run been retried / when was the last attempt", so
 * the execution-history table can show it without a per-row join, and so the
 * abuse guard can reject a double-click without reading the log table.
 *
 * The `enrolled_at` column-attribute repair that these retry writes exposed
 * lives on its own in
 * 2026_08_31_120000_fix_flow_subscribers_enrolled_at_auto_update, so it also
 * reaches installs that already ran this migration, and so rolling this table
 * back cannot re-arm the data loss.
 *
 * Additive + guarded (hasTable / hasColumn) — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_retry_logs')) {
            Schema::create('flow_retry_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('flow_id')->index();
                $table->unsignedBigInteger('flow_subscriber_id')->index();
                $table->unsignedBigInteger('contact_id')->nullable();
                // NULL = system/automatic retry (no operator behind it).
                $table->unsignedBigInteger('retried_by_user_id')->nullable();
                // State the run was in immediately BEFORE the retry fired.
                $table->string('previous_status', 16)->nullable();
                $table->text('previous_failure_reason')->nullable();
                // queued | succeeded | failed
                $table->string('outcome', 16)->default('queued')->index();
                $table->text('outcome_reason')->nullable();
                $table->timestamps();
                $table->index(['workspace_id', 'created_at'], 'flow_retry_ws_created_idx');
            });
        }

        if (Schema::hasTable('flow_subscribers')) {
            Schema::table('flow_subscribers', function (Blueprint $table) {
                if (! Schema::hasColumn('flow_subscribers', 'retry_count')) {
                    $table->unsignedInteger('retry_count')->default(0)->after('status');
                }
                if (! Schema::hasColumn('flow_subscribers', 'last_retried_at')) {
                    $table->timestamp('last_retried_at')->nullable()->after('retry_count');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_retry_logs');

        if (Schema::hasTable('flow_subscribers')) {
            Schema::table('flow_subscribers', function (Blueprint $table) {
                foreach (['retry_count', 'last_retried_at'] as $col) {
                    if (Schema::hasColumn('flow_subscribers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
