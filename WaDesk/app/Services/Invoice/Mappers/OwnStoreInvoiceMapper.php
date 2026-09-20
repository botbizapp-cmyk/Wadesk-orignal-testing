<?php

namespace App\Services\Invoice\Mappers;

use App\Models\InvoiceSetting;
use App\Models\WaOrder;
use App\Services\Invoice\InvoiceDraft;
use App\Support\MoneyFormat;

/**
 * Native WhatsApp Store order (storefront / WABA catalog / AI ordering / manual)
 * → InvoiceDraft. The compliance-critical rule (plan §4.3.3): a native order is
 * issued as a **Receipt** unless a real tax line was captured — never a ₹0-tax
 * "GST invoice" on a taxable sale.
 */
class OwnStoreInvoiceMapper
{
    public function toDraft(WaOrder $order, string $trigger = 'on_paid'): InvoiceDraft
    {
        $ws       = (int) $order->workspace_id;
        $settings = InvoiceSetting::forWorkspace($ws);
        $currency = strtoupper((string) ($order->currency_code ?: 'USD'));
        $exponent = MoneyFormat::ingestExponent($currency);

        // wa_products/wa_orders store ×100 by app convention. Re-scale to the
        // currency's TRUE minor for zero/3-decimal currencies so downstream math
        // and display agree (a stored 15000 for IDR@×100 is really 150 major).
        $toTrueMinor = function (int $storedX100) use ($exponent): int {
            if ($exponent === 2) {
                return $storedX100;
            }
            $major = $storedX100 / 100;

            return (int) round($major * (10 ** $exponent));
        };

        // Line items.
        $items = [];
        $subtotal = 0;
        foreach ($order->renderable_items as $i => $row) {
            $qty   = (float) ($row['qty'] ?? 1) ?: 1;
            $unit  = $toTrueMinor((int) ($row['price_minor'] ?? 0));
            $lineSub = (int) round($unit * $qty);
            $subtotal += $lineSub;
            $items[] = [
                'description'         => (string) ($row['name'] ?? 'Item'),
                'sku'                 => (string) ($row['retailer_id'] ?? $row['product_id'] ?? ''),
                'hsn_sac'             => null,
                'qty'                 => $qty,
                'unit_price_minor'    => $unit,
                'line_subtotal_minor' => $lineSub,
                'line_discount_minor' => 0,
                'tax_rate'            => null,
                'tax_amount_minor'    => 0,
                'tax_code'            => null,
            ];
        }
        if (! $items) {
            // No line rows (denormalised order) — a single "Order total" line.
            $subtotal = $toTrueMinor((int) $order->total_minor) - $toTrueMinor((int) ($order->shipping_minor ?? 0)) + $toTrueMinor((int) ($order->discount_minor ?? 0));
            $items[] = [
                'description' => 'Order #'.$order->id,
                'sku' => null, 'hsn_sac' => null, 'qty' => 1,
                'unit_price_minor' => max(0, $subtotal), 'line_subtotal_minor' => max(0, $subtotal),
                'line_discount_minor' => 0, 'tax_rate' => null, 'tax_amount_minor' => 0, 'tax_code' => null,
            ];
        }

        $shipping = $toTrueMinor((int) ($order->shipping_minor ?? 0));
        $discount = $toTrueMinor((int) ($order->discount_minor ?? 0));
        $tax      = $toTrueMinor((int) ($order->tax_minor ?? 0));
        $total    = $toTrueMinor((int) $order->total_minor);

        // ── Receipt vs Tax Invoice (the compliance rule) ──
        $hasTax  = $tax > 0;
        $docType = ($hasTax) ? 'tax_invoice' : 'receipt';

        $taxSummary = [];
        if ($hasTax) {
            $rate = $settings->default_tax_rate ? (float) $settings->default_tax_rate : null;
            $taxSummary[] = [
                'label' => $settings->tax_label ?: 'Tax',
                'rate'  => $rate,
                'base_minor' => $subtotal,
                'amount_minor' => $tax,
            ];
            // fold the single tax onto each line proportionally is out of MVP —
            // the footer tax summary carries the total; per-line tax stays 0.
        }

        return new InvoiceDraft(
            source: 'own',
            docType: $docType,
            currency: $currency,
            currencyExponent: $exponent,
            buyer: [
                'name'  => (string) ($order->customer_name ?: ''),
                'email' => (string) ($order->customer_email ?: ''),
                'phone' => (string) ($order->customer_phone ?: ''),
            ],
            items: $items,
            taxSummary: $taxSummary,
            subtotalMinor: $subtotal,
            discountMinor: $discount,
            shippingMinor: $shipping,
            taxMinor: $tax,
            totalMinor: $total,
            taxInclusive: (bool) ($order->tax_inclusive ?? false),
            billing: array_filter(['address' => (string) ($order->customer_address ?? '')]),
            shipping: array_filter(['address' => (string) ($order->customer_address ?? '')]),
            waOrderId: (int) $order->id,
            externalOrderId: null,      // native orders have no external id → manual dedupe via ws+number
            externalOrderNumber: (string) $order->id,
            paidAt: $order->updated_at?->toIso8601String(),
            trigger: $trigger,
            meta: ['native_source' => $order->source],
        );
    }
}
