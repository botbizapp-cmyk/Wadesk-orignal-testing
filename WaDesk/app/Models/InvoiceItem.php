<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'sort', 'description', 'sku', 'hsn_sac', 'qty',
        'unit_price_minor', 'line_subtotal_minor', 'line_discount_minor',
        'tax_rate', 'tax_amount_minor', 'tax_code', 'currency', 'meta_json',
    ];

    protected $casts = [
        'qty'                  => 'decimal:3',
        'tax_rate'             => 'decimal:3',
        'unit_price_minor'     => 'integer',
        'line_subtotal_minor'  => 'integer',
        'line_discount_minor'  => 'integer',
        'tax_amount_minor'     => 'integer',
        'meta_json'            => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
