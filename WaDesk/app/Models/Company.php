<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company / Organization — the B2B layer over contacts + deals (Phase 1).
 * name / email / phone are encrypted at rest, mirroring Contact.
 */
class Company extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'owner_user_id',
        'name', 'email', 'phone', 'website', 'industry', 'size_range',
        'address', 'notes', 'custom_attributes',
    ];

    protected $casts = [
        'name'              => 'encrypted',
        'email'             => 'encrypted',
        'phone'             => 'encrypted',
        'custom_attributes' => 'array',
    ];

    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        return $q->where('workspace_id', (int) (auth()->user()->current_workspace_id ?? 0));
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class)
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id')->withDefault();
    }

    /** Total won-deal value (minor units) for a quick revenue rollup. */
    public function wonValueMinor(): int
    {
        return (int) $this->deals()->where('status', 'won')->sum('value_minor');
    }
}
