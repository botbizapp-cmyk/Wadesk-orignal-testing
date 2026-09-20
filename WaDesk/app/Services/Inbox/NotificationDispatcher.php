<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\InboxNotification;
use App\Models\User;

/**
 * Single fan-out point for every "tell the user something happened" event.
 * Writes a row to inbox_notifications (the in-app feed reads this), and
 * dispatches a queued mail job for events the user has email-enabled.
 *
 * Wraps deduping logic — if you assign a conversation to someone twice
 * within 30s we don't create two assignment notifications.
 */
class NotificationDispatcher
{
    public function notifyAssignment(Conversation $conv, ?int $assigneeUserId, ?int $byUserId): void
    {
        if (!$assigneeUserId) return;
        $this->push(
            $assigneeUserId, $conv->workspace_id, 'assigned',
            'Conversation assigned to you',
            mask_phone($conv->title ?: 'New assignment'),
            ['conversation_id' => $conv->id, 'by_user_id' => $byUserId],
            "/team-inbox?c={$conv->id}",
            "assigned:{$conv->id}:{$assigneeUserId}",
        );
    }

    public function notifyMention(Conversation $conv, int $mentionedUserId, ?int $byUserId, string $excerpt): void
    {
        $this->push(
            $mentionedUserId, $conv->workspace_id, 'mentioned',
            'You were mentioned',
            $excerpt,
            ['conversation_id' => $conv->id, 'by_user_id' => $byUserId],
            "/team-inbox?c={$conv->id}",
        );

        ConversationParticipant::updateOrCreate(
            ['conversation_id' => $conv->id, 'user_id' => $mentionedUserId],
            ['workspace_id'    => $conv->workspace_id, 'role' => 'mentioned']
        )->increment('unread_mentions');
    }

    public function notifySlaBreach(Conversation $conv): void
    {
        $candidates = collect([$conv->assignee_user_id])->filter()->unique();
        if ($candidates->isEmpty() && $conv->assignee_team_id) {
            $candidates = \App\Models\Team::with('members')->find($conv->assignee_team_id)?->members?->pluck('id') ?? collect();
        }
        foreach ($candidates as $uid) {
            $this->push(
                $uid, $conv->workspace_id, 'sla_breach',
                'SLA breached',
                mask_phone($conv->title ?: 'Conversation'),
                ['conversation_id' => $conv->id, 'breach' => true],
                "/team-inbox?c={$conv->id}",
                "sla:{$conv->id}",
            );
        }
    }

    /**
     * Snoozed conversation woke up — remind the assignee (or the assigned
     * team) so the follow-up isn't missed. Mirrors notifySlaBreach's
     * assignee → team fallback. Deduped per conversation so a flood of
     * due rows can't double-notify.
     */
    public function notifySnoozeWake(Conversation $conv): void
    {
        $candidates = collect([$conv->assignee_user_id])->filter()->unique();
        if ($candidates->isEmpty() && $conv->assignee_team_id) {
            $candidates = \App\Models\Team::with('members')->find($conv->assignee_team_id)?->members?->pluck('id') ?? collect();
        }
        foreach ($candidates as $uid) {
            $this->push(
                (int) $uid, $conv->workspace_id, 'snooze_wake',
                'Snoozed chat reopened — follow up',
                mask_phone($conv->title ?: 'Conversation'),
                ['conversation_id' => $conv->id, 'snooze_wake' => true],
                "/team-inbox?c={$conv->id}",
                "snooze_wake:{$conv->id}",
            );
        }
    }

    public function notifyResolved(Conversation $conv, int $resolverUserId): void
    {
        $watchers = ConversationParticipant::where('conversation_id', $conv->id)
            ->where('user_id', '!=', $resolverUserId)->pluck('user_id');
        foreach ($watchers as $uid) {
            $this->push(
                $uid, $conv->workspace_id, 'resolved',
                'Conversation resolved',
                mask_phone($conv->title ?: 'Conversation'),
                ['conversation_id' => $conv->id, 'resolver_user_id' => $resolverUserId],
                "/team-inbox?c={$conv->id}",
            );
        }
    }

    /* ---------------- Sales Pipeline (deal) notifications ---------------- */

    /**
     * A deal was assigned to someone (owner_user_id set/changed) — tell the
     * new owner so the pipeline isn't a place deals quietly pile up unseen.
     * Deduped so a rapid re-assign can't double-notify the same owner.
     */
    public function notifyDealAssigned(Deal $deal, ?int $byUserId = null): void
    {
        $ownerId = (int) $deal->owner_user_id;
        if ($ownerId <= 0 || $ownerId === (int) $byUserId) return;
        $this->push(
            $ownerId, (int) $deal->workspace_id, 'deal_assigned',
            'Deal assigned to you',
            $deal->title . ' · ' . $deal->value_display,
            ['deal_id' => $deal->id, 'by_user_id' => $byUserId],
            "/deals?deal={$deal->id}",
            "deal_assigned:{$deal->id}:{$ownerId}",
        );
    }

    /**
     * A deal was Won or Lost — tell the owner. The win is worth celebrating;
     * the loss is worth a follow-up. Deduped per deal+status so the model's
     * updated() hook can't fire twice on one transition.
     */
    public function notifyDealOutcome(Deal $deal, ?int $actorId = null): void
    {
        $ownerId = (int) $deal->owner_user_id;
        if ($ownerId <= 0 || !in_array($deal->status, ['won', 'lost'], true)) return;
        // Don't ping the owner about their own action (they dragged it to
        // Won / clicked Mark Lost themselves) — only when someone else did.
        if ($actorId !== null && $ownerId === (int) $actorId) return;
        $won = $deal->status === 'won';
        $this->push(
            $ownerId, (int) $deal->workspace_id, $won ? 'deal_won' : 'deal_lost',
            $won ? 'Deal won' : 'Deal lost',
            $deal->title . ' · ' . $deal->value_display . ($deal->lost_reason ? ' — ' . $deal->lost_reason : ''),
            ['deal_id' => $deal->id, 'status' => $deal->status],
            "/deals?deal={$deal->id}",
            "deal_outcome:{$deal->id}:{$deal->status}",
        );
    }

    /**
     * A deal task hit its due time — remind the deal owner (fallback: the
     * teammate who logged the task). Called by DealReminderService::sweep,
     * which stamps reminded_at so this only fires once per task.
     */
    public function notifyDealTaskDue(Deal $deal, DealActivity $task): void
    {
        $userId = (int) ($deal->owner_user_id ?: $task->user_id);
        if ($userId <= 0) return;
        $this->push(
            $userId, (int) $deal->workspace_id, 'deal_task_due',
            'Deal task due',
            $deal->title . ' — ' . mb_substr((string) $task->body, 0, 120),
            ['deal_id' => $deal->id, 'activity_id' => $task->id],
            "/deals?deal={$deal->id}",
            "deal_task_due:{$task->id}",
        );
    }

    /* ── AI-CRM Phase 3 — first-class tasks (in-app + web push) ───────────── */

    public function notifyTaskDue(\App\Models\Task $task): void
    {
        $userId = (int) ($task->assignee_id ?: $task->created_by);
        if ($userId <= 0) return;
        $this->push(
            $userId, (int) $task->workspace_id, 'task_due',
            'Task due',
            (string) $task->title,
            ['task_id' => $task->id],
            '/tasks',
            "task_due:{$task->id}",
        );
    }

    public function notifyTaskAssigned(\App\Models\Task $task, ?int $byUserId = null): void
    {
        $userId = (int) $task->assignee_id;
        if ($userId <= 0 || $userId === (int) $byUserId) return; // don't ping yourself
        $this->push(
            $userId, (int) $task->workspace_id, 'task_assigned',
            'Task assigned to you',
            (string) $task->title,
            ['task_id' => $task->id],
            '/tasks',
            "task_assigned:{$task->id}",
        );
    }

    /**
     * The customer accepted or declined a proposal/estimate from its public
     * link. Pings the doc owner (and creator) so they can act on it.
     */
    public function notifySalesDocDecided(\App\Models\SalesDoc $doc): void
    {
        $accepted = $doc->status === \App\Models\SalesDoc::STATUS_ACCEPTED;
        $label = $doc->typeLabel();
        $route = $doc->doc_type === \App\Models\SalesDoc::TYPE_ESTIMATE ? '/estimates' : '/proposals';
        $targets = array_values(array_unique(array_filter([(int) $doc->owner_id, (int) $doc->created_by])));
        foreach ($targets as $uid) {
            $this->push(
                $uid, (int) $doc->workspace_id,
                'sales_doc_decided',
                $label . ' ' . ($accepted ? 'accepted' : 'declined'),
                trim(($doc->number ?: '') . ' ' . ($doc->title ?: '')) . ' — ' . ($accepted ? 'customer accepted' : 'customer declined'),
                ['sales_doc_id' => $doc->id, 'status' => $doc->status],
                $route,
                'salesdoc_decided:' . $doc->id . ':' . $doc->status,
            );
        }
    }

    /**
     * A new lead landed (webhook capture, first-time inbound, form). Alerts the
     * workspace owner + every agent subscribed to the team-inbox — in-app AND
     * browser push (via push()). Deduped per phone so one lead can't spam.
     */
    public function notifyNewLead(int $workspaceId, ?int $ownerUserId, string $name, string $phone, string $source = ''): void
    {
        if ($workspaceId <= 0) return;
        $title = 'New lead' . ($source !== '' ? ' · ' . $source : '');
        $body  = trim($name . ' ' . $phone);
        if ($body === '') $body = 'A new lead was captured';

        $userIds = $ownerUserId ? [(int) $ownerUserId] : [];
        $userIds = array_merge($userIds, \App\Models\PushSubscription::query()
            ->where('workspace_id', $workspaceId)
            ->where('channel', 'team-inbox')
            ->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());

        foreach (array_values(array_unique(array_filter($userIds))) as $uid) {
            $this->push(
                (int) $uid, $workspaceId, 'new_lead',
                $title, $body,
                ['source' => $source, 'phone' => $phone],
                '/contacts',
                'new_lead:' . preg_replace('/\D+/', '', $phone)
            );
        }
    }

    private function push(int $userId, int $workspaceId, string $type, string $title, string $body, array $data, string $link, ?string $dedupeKey = null): void
    {
        if ($dedupeKey) {
            $cacheKey = "inbox_notif_dedupe:$dedupeKey";
            if (cache()->has($cacheKey)) return;
            cache()->put($cacheKey, 1, now()->addSeconds(30));
        }

        InboxNotification::create([
            'user_id'      => $userId,
            'workspace_id' => $workspaceId,
            'type'         => $type,
            'title'        => $title,
            'body'         => mb_substr(strip_tags($body), 0, 200),
            'data'         => $data,
            'link'         => $link,
        ]);

        // Browser web push (VAPID) so the alert reaches the agent even when the
        // inbox tab isn't focused/open — covers assignment, @mention, SLA, and
        // snooze-wake (the "follow-up" reminder) + resolved. The in-app feed row
        // above always lands; this is best-effort and never blocks it.
        try {
            if (\App\Models\SystemSetting::get('ti_pwa_enabled', false)) {
                app(\App\Services\Push\WebPushService::class)->sendToUser($userId, [
                    'title' => mb_substr($title, 0, 100),
                    'body'  => mb_substr(strip_tags($body), 0, 180),
                    'url'   => $link ?: '/team-inbox',
                    'tag'   => 'inbox-' . ($dedupeKey ?: $type),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[INBOX-PUSH] web push failed: ' . $e->getMessage());
        }
    }
}
