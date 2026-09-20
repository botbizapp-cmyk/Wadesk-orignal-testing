<?php

namespace App\Services\Crm;

use App\Models\Task;
use App\Services\Inbox\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * AI-CRM Phase 3 — task reminder sweep. Picks OPEN tasks that are due and not yet
 * reminded, fires one in-app + web-push reminder to the assignee, then stamps
 * `reminded_at` (idempotent — one reminder per task). Mirrors DealReminderService;
 * invoked inline on the Team-Inbox AJAX poll + the `crm:remind-tasks` command
 * (repo has no scheduler).
 */
class TaskReminderService
{
    public function sweep(?int $workspaceId = null, int $limit = 200): int
    {
        $q = Task::query()
            ->where('status', 'open')
            ->whereNull('done_at')
            ->whereNull('reminded_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit);

        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }

        $tasks = $q->get();
        if ($tasks->isEmpty()) {
            return 0;
        }

        $disp  = app(NotificationDispatcher::class);
        $count = 0;
        foreach ($tasks as $task) {
            try {
                $disp->notifyTaskDue($task);
            } catch (\Throwable $e) {
                Log::warning('[CRM] task reminder failed (task ' . $task->id . '): ' . $e->getMessage());
            }
            // Stamp regardless so a notify glitch never re-fires the same task.
            $task->forceFill(['reminded_at' => now()])->save();
            $count++;
        }

        return $count;
    }
}
