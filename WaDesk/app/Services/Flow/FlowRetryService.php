<?php

namespace App\Services\Flow;

use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowRetryLog;
use App\Models\FlowSubscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs a FAILED flow run.
 *
 * A "run" is one `flow_subscribers` row: enrolled_at → completed_at | failed_at.
 * Retrying does NOT re-implement enrolment — it hands the contact back to
 * FlowEnrollmentService::enroll(), which already treats a failed subscriber as
 * "clear the failure and launch again" (see its `$sub->status === 'failed'`
 * branch). That keeps engine resolution, sender lookup, Meta-agent suppression
 * and the Node handoff in exactly ONE place.
 *
 * Everything the operator needs to audit the attempt is written to
 * `flow_retry_logs`: the status + failure reason the run carried BEFORE the
 * retry, who fired it (NULL = system), and the outcome after.
 *
 * Contract: this service NEVER throws. Every path returns
 *   ['ok' => bool, 'reason' => string|null, 'message' => string, 'outcome' => string|null, ...]
 * so a bulk caller can keep going through a mixed batch.
 */
class FlowRetryService
{
    /**
     * A retry may not fire again for this many seconds on the same run.
     * Long enough to absorb a double-click / duplicated request, short enough
     * that a genuine second attempt after reading the new error is not blocked.
     */
    public const COOLDOWN_SECONDS = 30;

    /** Hard ceiling on one bulk request — never an unbounded "retry everything". */
    public const MAX_BATCH = 50;

    public function __construct(private ?FlowEnrollmentService $enrollment = null)
    {
        $this->enrollment = $enrollment ?: app(FlowEnrollmentService::class);
    }

    /**
     * Retry one failed run.
     *
     * @param  FlowSubscriber $sub          the run to re-execute
     * @param  int|null       $userId       operator behind the retry; NULL = system/auto
     * @param  int|null       $workspaceId  when given, the run's flow MUST belong to it
     * @return array{ok:bool,reason:?string,message:string,outcome:?string,log_id:?int,subscriber:?FlowSubscriber}
     */
    public function retry(FlowSubscriber $sub, ?int $userId = null, ?int $workspaceId = null): array
    {
        try {
            $flow = Flow::find($sub->flow_id);
            if (!$flow) {
                return $this->fail('flow_not_found', __('This automation no longer exists, so the run cannot be re-executed.'));
            }

            // Tenancy — never let a run from another workspace be re-launched,
            // even if a subscriber id was guessed. Pre-workspace rows (NULL
            // workspace_id) fall back to their creator, mirroring the legacy
            // branch of Flow::scopeForCurrentWorkspace.
            $flowWs = (int) ($flow->workspace_id ?? 0);
            if ($workspaceId !== null) {
                if ($flowWs > 0) {
                    if ($flowWs !== (int) $workspaceId) {
                        return $this->fail('wrong_workspace', __('This run belongs to a different workspace.'));
                    }
                } elseif ($userId === null || (int) $flow->user_id !== (int) $userId) {
                    return $this->fail('wrong_workspace', __('This run belongs to a different workspace.'));
                }
            }
            if ($userId !== null && $flowWs > 0 && !$this->userCanActOn((int) $userId, $flowWs)) {
                return $this->fail('not_permitted', __('You do not have access to this automation.'));
            }

            // Same activation gate the manual-enrolment endpoint uses: a paused
            // automation must not be restarted behind the operator's back.
            if (! $flow->is_active) {
                return $this->fail('flow_inactive', __('Turn this automation back on before re-running its failed contacts.'));
            }

            // Idempotency / abuse guard — a double-click, a duplicated bulk
            // request or an impatient operator must not fan out repeat sends.
            // Checked BEFORE the status gate: a successful retry flips the run
            // failed → active, so a second press would otherwise be reported as
            // "still in progress" instead of "just retried".
            if ($sub->last_retried_at && $sub->last_retried_at->diffInSeconds(now()) < self::COOLDOWN_SECONDS) {
                return $this->fail('too_soon', __('This run was just retried. Please wait a moment before trying again.'));
            }

            if ($sub->status !== 'failed') {
                return $this->fail('not_failed', match ($sub->status) {
                    'completed' => __('This run already finished successfully — there is nothing to retry.'),
                    'paused'    => __('This run is paused. Resume it instead of retrying.'),
                    default     => __('Only a failed run can be retried. This one is still in progress.'),
                });
            }

            $contact = Contact::find($sub->contact_id);
            if (!$contact) {
                return $this->fail('contact_not_found', __('The contact for this run no longer exists.'));
            }

            // Snapshot the pre-retry state BEFORE enrolment clears it — enroll()
            // resets status/failed_at/failure_reason on a failed subscriber, so
            // this is the only moment the original error is still readable.
            $prevStatus = (string) $sub->status;
            $prevReason = $sub->failure_reason !== null ? (string) $sub->failure_reason : null;

            // Arm the cooldown and write the audit row in ONE transaction, before
            // the launch, so a request that dies mid-flight can neither be
            // replayed instantly nor leave a log row for a retry that never
            // armed the guard. The guard is a CONDITIONAL update rather than a
            // read-then-write: two simultaneous requests both saw status=failed
            // above, but only one of them can change a row here — the other
            // gets 0 and bails, so the contact is never sent the flow twice.
            $log   = null;
            $armed = false;
            DB::transaction(function () use (&$log, &$armed, $sub, $flow, $userId, $prevStatus, $prevReason) {
                $armed = (bool) FlowSubscriber::query()
                    ->where('id', $sub->id)
                    ->where('status', 'failed')
                    ->where(function ($w) {
                        $w->whereNull('last_retried_at')
                          ->orWhere('last_retried_at', '<', now()->subSeconds(self::COOLDOWN_SECONDS));
                    })
                    ->update([
                        'retry_count'     => DB::raw('retry_count + 1'),
                        'last_retried_at' => now(),
                    ]);
                if (! $armed) return;

                $log = FlowRetryLog::create([
                    'workspace_id'            => (int) $flow->workspace_id,
                    'flow_id'                 => (int) $flow->id,
                    'flow_subscriber_id'      => (int) $sub->id,
                    'contact_id'              => (int) $sub->contact_id,
                    'retried_by_user_id'      => $userId,
                    'previous_status'         => $prevStatus,
                    'previous_failure_reason' => $prevReason,
                    'outcome'                 => 'queued',
                ]);
            });

            if (! $armed) {
                return $this->fail('too_soon', __('This run was just retried. Please wait a moment before trying again.'));
            }

            $launchError = null;
            try {
                $this->enrollment->enroll($contact, $flow);
            } catch (\Throwable $e) {
                $launchError = mb_substr($e->getMessage(), 0, 190);
                Log::warning('[FLOW-RETRY] re-enrolment threw', [
                    'subscriber_id' => $sub->id, 'flow_id' => $flow->id, 'error' => $e->getMessage(),
                ]);
            }

            // enroll() writes through its OWN model instance — re-read ours to
            // see what actually happened.
            $sub->refresh();

            if ($launchError !== null) {
                $outcome = 'failed';
                $reason  = $launchError;
            } else {
                $outcome = match ($sub->status) {
                    'failed'    => 'failed',
                    'completed' => 'succeeded',
                    default     => 'queued',
                };
                $reason = $outcome === 'failed'
                    ? ($sub->failure_reason !== null ? (string) $sub->failure_reason : null)
                    : null;
            }

            $log->update(['outcome' => $outcome, 'outcome_reason' => $reason]);

            if ($outcome === 'failed') {
                return [
                    'ok'         => false,
                    'reason'     => 'retry_failed',
                    'message'    => $reason !== null && $reason !== ''
                        ? __('The retry failed again: :reason', ['reason' => $reason])
                        : __('The retry failed again.'),
                    'outcome'    => $outcome,
                    'log_id'     => (int) $log->id,
                    'subscriber' => $sub,
                ];
            }

            return [
                'ok'         => true,
                'reason'     => null,
                'message'    => $outcome === 'succeeded'
                    ? __('The run completed on retry.')
                    : __('The run was re-started.'),
                'outcome'    => $outcome,
                'log_id'     => (int) $log->id,
                'subscriber' => $sub,
            ];
        } catch (\Throwable $e) {
            // Belt and braces — a retry must never surface a 500 to the page.
            Log::error('[FLOW-RETRY] unexpected failure: ' . $e->getMessage(), [
                'subscriber_id' => $sub->id ?? null,
            ]);
            return $this->fail('unexpected', __('The run could not be retried. Please try again.'));
        }
    }

    /**
     * Retry an EXPLICIT list of runs. Never "everything" — the caller must name
     * the ids and the batch is hard-capped at self::MAX_BATCH.
     *
     * @param  int[] $subscriberIds
     * @return array{ok:bool,requested:int,attempted:int,queued:int,succeeded:int,failed:int,skipped:int,capped:bool,results:array}
     */
    public function retryMany(array $subscriberIds, ?int $userId = null, ?int $workspaceId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $subscriberIds))));
        $requested = count($ids);
        $capped    = $requested > self::MAX_BATCH;
        $ids       = array_slice($ids, 0, self::MAX_BATCH);

        $rows = FlowSubscriber::whereIn('id', $ids)->get()->keyBy('id');

        $results = [];
        $queued = $succeeded = $failed = $skipped = 0;

        foreach ($ids as $id) {
            $sub = $rows->get($id);
            if (!$sub) {
                $skipped++;
                $results[] = [
                    'id' => $id, 'ok' => false, 'outcome' => null,
                    'reason' => 'not_found', 'message' => __('This run no longer exists.'),
                ];
                continue;
            }

            $res = $this->retry($sub, $userId, $workspaceId);
            match ($res['outcome']) {
                'queued'    => $queued++,
                'succeeded' => $succeeded++,
                'failed'    => $failed++,
                default     => $skipped++,
            };
            $results[] = [
                'id'      => $id,
                'ok'      => (bool) $res['ok'],
                'outcome' => $res['outcome'],
                'reason'  => $res['reason'],
                'message' => $res['message'],
            ];
        }

        return [
            'ok'        => true,
            'requested' => $requested,
            'attempted' => count($ids),
            'queued'    => $queued,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'capped'    => $capped,
            'results'   => $results,
        ];
    }

    /** The acting user must actually belong to the flow's workspace. */
    private function userCanActOn(int $userId, int $workspaceId): bool
    {
        if ($workspaceId <= 0) return false;
        $user = User::find($userId);
        if (!$user) return false;
        if ((int) $user->current_workspace_id === $workspaceId) return true;
        return $user->workspaces()->where('workspaces.id', $workspaceId)->exists();
    }

    /** @return array{ok:false,reason:string,message:string,outcome:null,log_id:null,subscriber:null} */
    private function fail(string $reason, string $message): array
    {
        return [
            'ok'         => false,
            'reason'     => $reason,
            'message'    => $message,
            'outcome'    => null,
            'log_id'     => null,
            'subscriber' => null,
        ];
    }
}
