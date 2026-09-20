<?php

namespace App\Console\Commands;

use App\Services\Crm\TaskReminderService;
use Illuminate\Console\Command;

/**
 * AI-CRM Phase 3 — nudge assignees about first-class tasks whose due_at has
 * passed. TeamInboxController::queue() also runs this sweep inline (cache-gated
 * per workspace), so hosts without cron still get reminders within ~60s while an
 * operator has the inbox open. Idempotent: only matches reminded_at IS NULL.
 */
class CrmRemindTasksCommand extends Command
{
    protected $signature = 'crm:remind-tasks {--limit=500}';
    protected $description = 'Notify assignees of first-class CRM tasks whose due date has passed (once per task).';

    public function handle(TaskReminderService $svc): int
    {
        $n = $svc->sweep(null, (int) $this->option('limit'));
        $this->info("Reminded {$n} due task(s).");

        return self::SUCCESS;
    }
}
