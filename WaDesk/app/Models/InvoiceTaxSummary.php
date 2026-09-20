<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTaxSummary extends Model
{
    protected $table = 'invoice_tax_summary';

    protected $fillable = [
        'invoice_id', 'tax_label', 'rate', 'base_minor', 'amount_minor',
    ];

    protected $casts = [
        'rate'         => 'decimal:3',
        'base_minor'   => 'integer',
        'amount_minor' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
