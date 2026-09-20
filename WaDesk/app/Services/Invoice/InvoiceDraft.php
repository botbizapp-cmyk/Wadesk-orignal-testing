<?php

namespace App\Services\Invoice;

/**
 * Canonical, source-blind invoice draft. Every mapper (own store / Woo / Shopify)
 * produces one of these; the engine + renderer + sender only ever see this shape.
 * All *_minor are integer minor units in the draft's own currency exponent.
 */
class InvoiceDraft
{
    public function __construct(
        public string $source,                 // woocommerce|shopify|own|manual
        public string $docType,                // tax_invoice|receipt|proforma|credit_note
        public string $currency,
        public int $currencyExponent,
        public array $buyer = [],              // ['name','email','phone']
        public array $items = [],              // [['description','sku','hsn_sac','qty','unit_price_minor','line_subtotal_minor','line_discount_minor','tax_rate','tax_amount_minor','tax_code']]
        public array $taxSummary = [],         // [['label','rate','base_minor','amount_minor']]
        public int $subtotalMinor = 0,
        public int $discountMinor = 0,
        public int $shippingMinor = 0,
        public int $taxMinor = 0,
        public int $totalMinor = 0,
        public bool $taxInclusive = false,
        public array $billing = [],
        public array $shipping = [],
        public ?int $waOrderId = null,
        public ?string $externalOrderId = null,
        public ?string $externalOrderNumber = null,
        public ?string $paidAt = null,
        public string $trigger = 'manual',     // on_placed|on_paid|on_fulfilled|manual
        public array $meta = [],
    ) {}

    public function seriesPrefix(string $invPrefix, string $proPrefix): string
    {
        return $this->docType === 'proforma' ? $proPrefix : $invPrefix;
    }
}
