<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Proposal or an Estimate (doc_type). Lightweight pre-invoice quote — line
 * items live in items_json, money in integer minor units of the doc's own
 * currency. Shareable via public_token; convertible into a real Invoice.
 * AI-CRM Phase 7.
 */
class SalesDoc extends Model
{
    public const TYPE_PROPOSAL = 'proposal';
    public const TYPE_ESTIMATE = 'estimate';
    public const TYPES = [self::TYPE_PROPOSAL, self::TYPE_ESTIMATE];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVOICED = 'invoiced';
    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED,
        self::STATUS_REJECTED, self::STATUS_EXPIRED, self::STATUS_INVOICED,
    ];

    protected $fillable = [
        'workspace_id', 'doc_type', 'number', 'seq', 'status', 'title',
        'contact_id', 'company_id', 'deal_id', 'buyer_name', 'buyer_email', 'buyer_phone',
        'currency', 'currency_exponent', 'subtotal_minor', 'discount_minor', 'tax_minor',
        'total_minor', 'tax_rate_bp', 'items_json', 'notes', 'valid_until',
        'sent_at', 'decided_at', 'invoice_id', 'public_token', 'owner_id', 'created_by',
    ];

    protected $casts = [
        'buyer_name'        => 'encrypted',
        'buyer_email'       => 'encrypted',
        'buyer_phone'       => 'encrypted',
        'notes'             => 'encrypted',
        'items_json'        => 'array',
        'currency_exponent' => 'int',
        'subtotal_minor'    => 'integer',
        'discount_minor'    => 'integer',
        'tax_minor'         => 'integer',
        'total_minor'       => 'integer',
        'tax_rate_bp'       => 'integer',
        'valid_until'       => 'date',
        'sent_at'           => 'datetime',
        'decided_at'        => 'datetime',
    ];

    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        return $q->where('workspace_id', (int) (auth()->user()->current_workspace_id ?? 0));
    }

    public function scopeType(Builder $q, string $type): Builder
    {
        return $q->where('doc_type', $type);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withDefault();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withDefault();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withDefault();
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast()
            && ! in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_INVOICED, self::STATUS_REJECTED], true);
    }

    public function getTotalDisplayAttribute(): string
    {
        $sym = \App\Models\Currency::symbolFor($this->currency);
        $amount = $this->total_minor / (10 ** $this->currency_exponent);
        return $sym . number_format($amount, $this->currency_exponent);
    }

    public function publicUrl(): string
    {
        return url('/q/' . $this->public_token);
    }

    public function typeLabel(): string
    {
        return $this->doc_type === self::TYPE_ESTIMATE ? 'Estimate' : 'Proposal';
    }
}
