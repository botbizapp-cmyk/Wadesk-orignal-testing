<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per (flow, contact) — the unified replacement for DripSubscriber.
 * Tracks which contacts are currently inside which flow, regardless of how
 * they got enrolled (keyword trigger, tag added, group join, manual).
 *
 * UNIQUE(flow_id, contact_id) — re-enrollment is idempotent. Subscribers
 * stay on the row even after the flow ends so an operator can re-enroll.
 *
 * This row IS the flow execution-history record read by /flows/analytics:
 * enrolled_at → completed_at | failed_at + failure_reason is the whole life
 * of one run, and retry_count / last_retried_at say how many times it was
 * re-run (full per-attempt detail lives in `flow_retry_logs`).
 */
class FlowSubscriber extends Model
{
    public const STATUSES = ['active', 'paused', 'completed', 'failed'];

    protected $fillable = [
        'flow_id', 'contact_id',
        'enrolled_at', 'completed_at', 'failed_at',
        'failure_reason', 'status',
        'retry_count', 'last_retried_at',
    ];

    protected $casts = [
        'enrolled_at'     => 'datetime',
        'completed_at'    => 'datetime',
        'failed_at'       => 'datetime',
        'last_retried_at' => 'datetime',
        'retry_count'     => 'int',
    ];

    public function flow(): BelongsTo    { return $this->belongsTo(Flow::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }

    /** Every retry attempt made on this run, newest first. */
    public function retryLogs(): HasMany
    {
        return $this->hasMany(FlowRetryLog::class, 'flow_subscriber_id')->orderByDesc('id');
    }

    /** Only a failed run can be retried — see FlowRetryService. */
    public function isRetryable(): bool
    {
        return $this->status === 'failed';
    }
}
