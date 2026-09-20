<?php

namespace App\Models;

use App\Support\MoneyFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A frozen invoice document. Number + amounts + rendered PDF never change after
 * issue (see the auto-invoice plan §8). Amounts are integer minor units in the
 * invoice's OWN currency exponent.
 */
class Invoice extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';
    public const STATUS_CREDITED = 'credited';

    // send_status lifecycle
    public const SEND_PENDING = 'pending';
    public const SEND_RENDERING = 'rendering';
    public const SEND_READY = 'ready';
    public const SEND_SENDING = 'sending';
    public const SEND_SENT = 'sent';
    public const SEND_FAILED = 'send_failed';
    public const SEND_SKIPPED = 'skipped';

    protected $fillable = [
        'workspace_id', 'user_id', 'source', 'doc_type', 'wa_order_id',
        'external_order_id', 'external_order_number', 'series', 'invoice_number', 'seq',
        'status', 'send_status', 'send_attempts', 'send_reason', 'sent_at', 'trigger',
        'issued_at', 'due_at', 'paid_at', 'currency', 'currency_exponent',
        'subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor', 'total_minor', 'tax_inclusive',
        'buyer_name', 'buyer_email', 'buyer_phone', 'billing_json', 'shipping_json', 'seller_snapshot_json', 'notes',
        'pdf_disk', 'pdf_path', 'pdf_sha256', 'pdf_bytes',
        'delivery_channel', 'delivered_at', 'wa_message_id', 'send_error',
        'public_token', 'meta_json',
    ];

    protected $casts = [
        'billing_json'         => 'array',
        'shipping_json'        => 'array',
        'seller_snapshot_json' => 'array',
        'meta_json'            => 'array',
        'currency_exponent'    => 'int',
        'send_attempts'        => 'int',
        'tax_inclusive'        => 'bool',
        'issued_at'            => 'datetime',
        'due_at'               => 'datetime',
        'paid_at'              => 'datetime',
        'delivered_at'         => 'datetime',
        'sent_at'              => 'datetime',
        'subtotal_minor'       => 'integer',
        'discount_minor'       => 'integer',
        'shipping_minor'       => 'integer',
        'tax_minor'            => 'integer',
        'total_minor'          => 'integer',
        'pdf_bytes'            => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort');
    }

    public function taxSummary(): HasMany
    {
        return $this->hasMany(InvoiceTaxSummary::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WaOrder::class, 'wa_order_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    /** Rows still worth a delivery attempt (not sent, under the retry cutoff). */
    public function scopeUnsent($q)
    {
        return $q->whereIn('send_status', [self::SEND_READY, self::SEND_FAILED, self::SEND_PENDING])
            ->where('send_attempts', '<', 5)
            ->whereNull('sent_at');
    }

    public function getTotalDisplayAttribute(): string
    {
        return MoneyFormat::display((int) $this->total_minor, (string) $this->currency);
    }

    public function getPdfUrlAttribute(): ?string
    {
        return $this->pdf_path ? media_url($this->pdf_path) : null;
    }

    /** The public capability link (hosted PDF viewer). */
    public function publicUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/i/'.$this->public_token;
    }

    /** True once the document has been issued (frozen — see plan §8). */
    public function isImmutable(): bool
    {
        return in_array($this->send_status, [self::SEND_SENT], true) || $this->sent_at !== null
            || ($this->pdf_path !== null && $this->send_status !== self::SEND_PENDING);
    }

    /** Persistent duplicate-send guard (plan §6.5). */
    public function alreadySent(): bool
    {
        return $this->send_status === self::SEND_SENT || $this->sent_at !== null;
    }
}
