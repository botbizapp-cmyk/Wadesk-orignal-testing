<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI-CRM Phase 3 — a first-class task. Stands alone or links to a contact / deal
 * / company. `notes` is encrypted at rest (PII); `title` stays plaintext so the
 * board can sort/label it.
 */
class Task extends Model
{
    public const PRIORITIES = ['low', 'medium', 'high'];
    public const STATUSES   = ['open', 'done'];
    public const RELATED    = ['contact', 'deal', 'company'];

    protected $fillable = [
        'workspace_id', 'created_by', 'assignee_id', 'title', 'notes',
        'priority', 'status', 'related_type', 'related_id',
        'due_at', 'reminded_at', 'done_at',
    ];

    protected $casts = [
        'notes'       => 'encrypted',
        'due_at'      => 'datetime',
        'reminded_at' => 'datetime',
        'done_at'     => 'datetime',
    ];

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        $wsId = (int) (auth()->user()?->current_workspace_id ?? 0);
        return $wsId ? $q->where('workspace_id', $wsId) : $q->whereRaw('1=0');
    }

    public function scopeForWorkspace(Builder $q, ?int $workspaceId): Builder
    {
        return $workspaceId ? $q->where('workspace_id', $workspaceId) : $q->whereRaw('1=0');
    }

    public function scopeOpen(Builder $q): Builder { return $q->where('status', 'open'); }

    /* ── Relations ──────────────────────────────────────────────────────── */

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id')->withDefault(); }
    public function creator(): BelongsTo  { return $this->belongsTo(User::class, 'created_by')->withDefault(); }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at && $this->due_at->isPast();
    }

    /** A short label for the linked CRM object (best-effort, decrypt-safe). */
    public function relatedLabel(): ?string
    {
        if (! $this->related_type || ! $this->related_id) return null;
        try {
            return match ($this->related_type) {
                'contact' => optional(Contact::find($this->related_id))->name,
                'deal'    => optional(Deal::find($this->related_id))->title,
                'company' => class_exists(Company::class) ? optional(Company::find($this->related_id))->name : null,
                default   => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }
}
