<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI-CRM Phase 6 — a Project (post-sale work tracking). Status is
 * in_progress|completed; "overdue" is derived. `description` encrypted at rest.
 */
class Project extends Model
{
    public const STATUSES = ['in_progress', 'completed'];

    protected $fillable = [
        'workspace_id', 'name', 'description', 'status', 'progress',
        'contact_id', 'company_id', 'deal_id', 'owner_id', 'created_by',
        'start_date', 'due_date', 'completed_at',
    ];

    protected $casts = [
        'description'  => 'encrypted',
        'progress'     => 'integer',
        'start_date'   => 'date',
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        $wsId = (int) (auth()->user()?->current_workspace_id ?? 0);
        return $wsId ? $q->where('workspace_id', $wsId) : $q->whereRaw('1=0');
    }

    public function scopeForWorkspace(Builder $q, ?int $workspaceId): Builder
    {
        return $workspaceId ? $q->where('workspace_id', $workspaceId) : $q->whereRaw('1=0');
    }

    public function owner(): BelongsTo   { return $this->belongsTo(User::class, 'owner_id')->withDefault(); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class)->withDefault(); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class)->withDefault(); }
    public function deal(): BelongsTo    { return $this->belongsTo(Deal::class)->withDefault(); }

    public function isOverdue(): bool
    {
        return $this->status === 'in_progress' && $this->due_date && $this->due_date->isPast();
    }
}
