<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per retry attempt on a failed flow run.
 *
 * Written by App\Services\Flow\FlowRetryService — never by a controller
 * directly — so every retry (manual from the analytics page, or automatic
 * from a future scheduler) lands in the same audit trail with the state the
 * run carried BEFORE the attempt.
 *
 * `retried_by_user_id` NULL means the retry was system-initiated.
 */
class FlowRetryLog extends Model
{
    public const OUTCOMES = ['queued', 'succeeded', 'failed'];

    protected $table = 'flow_retry_logs';

    protected $fillable = [
        'workspace_id', 'flow_id', 'flow_subscriber_id', 'contact_id',
        'retried_by_user_id',
        'previous_status', 'previous_failure_reason',
        'outcome', 'outcome_reason',
    ];

    protected $casts = [
        'workspace_id'       => 'int',
        'flow_id'            => 'int',
        'flow_subscriber_id' => 'int',
        'contact_id'         => 'int',
        'retried_by_user_id' => 'int',
    ];

    public function flow(): BelongsTo       { return $this->belongsTo(Flow::class); }
    public function subscriber(): BelongsTo { return $this->belongsTo(FlowSubscriber::class, 'flow_subscriber_id'); }
    public function contact(): BelongsTo    { return $this->belongsTo(Contact::class); }
    public function retriedBy(): BelongsTo  { return $this->belongsTo(User::class, 'retried_by_user_id'); }

    /**
     * Workspace-shared visibility — same contract as the sibling log models
     * (AiCrmAction et al). Rows always carry a workspace_id, stamped from the
     * flow at write time, so no legacy NULL-owner fallback is needed.
     */
    public function scopeForCurrentWorkspace($q)
    {
        return $q->where('workspace_id', (int) (auth()->user()->current_workspace_id ?? 0));
    }

    /** 'manual' when an operator pressed Retry, 'system' for an automatic one. */
    public function getSourceAttribute(): string
    {
        return $this->retried_by_user_id ? 'manual' : 'system';
    }
}
