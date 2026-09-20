<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * AI-CRM Phase 5 — a generated Client Brief / deck (self-contained HTML) for a
 * contact / company / deal, with a public shareable token.
 */
class CrmBrief extends Model
{
    protected $fillable = [
        'workspace_id', 'created_by', 'subject_type', 'subject_id',
        'title', 'html', 'summary', 'public_token', 'meta_json',
    ];

    protected $casts = ['meta_json' => 'array'];

    public function scopeForWorkspace(Builder $q, ?int $workspaceId): Builder
    {
        return $workspaceId ? $q->where('workspace_id', $workspaceId) : $q->whereRaw('1=0');
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/b/' . $this->public_token;
    }
}
