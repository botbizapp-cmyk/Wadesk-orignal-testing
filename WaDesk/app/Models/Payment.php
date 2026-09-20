<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI-CRM Phase 2.2 — a single money-in record (full/partial) against an invoice
 * / deal / contact / company. Money is integer minor units, repo-wide.
 */
class Payment extends Model
{
    public const METHODS = ['manual', 'cash', 'bank', 'card', 'upi', 'gateway', 'wa_pay', 'storefront'];
    public const SOURCES = ['manual', 'gateway', 'wa_pay', 'storefront'];

    protected $fillable = [
        'workspace_id', 'invoice_id', 'deal_id', 'contact_id', 'company_id', 'wa_order_id',
        'amount_minor', 'currency', 'method', 'source', 'paid_at', 'reference', 'note',
        'gateway_payment_id', 'recorded_by', 'meta_json',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'paid_at'      => 'datetime',
        'meta_json'    => 'array',
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

    /* ── Relations ──────────────────────────────────────────────────────── */

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function deal(): BelongsTo    { return $this->belongsTo(Deal::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function order(): BelongsTo   { return $this->belongsTo(WaOrder::class, 'wa_order_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }

    /* ── Display ────────────────────────────────────────────────────────── */

    public function getAmountDisplayAttribute(): string
    {
        return Currency::symbolFor((string) $this->currency) . number_format($this->amount_minor / 100, 2);
    }
}
