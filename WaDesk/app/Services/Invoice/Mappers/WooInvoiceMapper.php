<?php

namespace App\Services\Invoice\Mappers;

use App\Models\WaOrder;
use App\Services\Invoice\InvoiceDraft;
use App\Support\MoneyFormat;

/**
 * WooCommerce order payload → InvoiceDraft. Parses the RAW webhook body (which is
 * the REST order representation) so tax lines, HSN, per-line amounts and the
 * currency-exponent scaling are correct — NOT the flattened WaOrder mirror.
 * Woo sends decimal strings ("19.99", "15000", "1.500"); we scale by the
 * currency's TRUE ISO exponent (MoneyFormat), so IDR/JPY never 100× and KWD ×1000.
 */
class WooInvoiceMapper
{
    public function toDraft(array $o, WaOrder $mirror, string $trigger = 'on_paid'): InvoiceDraft
    {
        $currency = strtoupper((string) ($o['currency'] ?? $mirror->currency_code ?? 'USD'));
        $exp      = MoneyFormat::ingestExponent($currency);
        $toMinor  = fn ($v) => MoneyFormat::toMinor((string) ($v ?? '0'), $currency);

        $billing = (array) ($o['billing'] ?? []);
        $phone   = \App\Support\Woo\WooPhone::fromOrder($o) ?: ($mirror->customer_phone ?: '');

        // Line items — Woo gives per-line `total` (ex-tax) + `total_tax`.
        $items = [];
        $subtotal = 0;
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $qty     = (float) ($li['quantity'] ?? 1) ?: 1;
            $lineSub = $toMinor($li['total'] ?? $li['subtotal'] ?? 0);      // line total ex-tax
            $lineTax = $toMinor($li['total_tax'] ?? 0);
            $unit    = $qty > 0 ? (int) round($lineSub / $qty) : $lineSub;
            $subtotal += $lineSub;
            $items[] = [
                'description'         => (string) ($li['name'] ?? 'Item'),
                'sku'                 => (string) ($li['sku'] ?? ''),
                'hsn_sac'             => (string) (data_get($li, 'meta_data.0.value', '') ?: ''),
                'qty'                 => $qty,
                'unit_price_minor'    => $unit,
                'line_subtotal_minor' => $lineSub,
                'line_discount_minor' => 0,
                'tax_rate'            => null,
                'tax_amount_minor'    => $lineTax,
                'tax_code'            => null,
            ];
        }

        // Tax summary from tax_lines (label + rate + total).
        $taxSummary = [];
        $taxTotal = 0;
        foreach ((array) ($o['tax_lines'] ?? []) as $t) {
            $amt = $toMinor($t['tax_total'] ?? 0) + $toMinor($t['shipping_tax_total'] ?? 0);
            $taxTotal += $amt;
            $taxSummary[] = [
                'label' => (string) ($t['label'] ?? 'Tax'),
                'rate'  => isset($t['rate_percent']) ? (float) $t['rate_percent'] : null,
                'base_minor'   => $subtotal,
                'amount_minor' => $amt,
            ];
        }
        if ($taxTotal === 0) {
            $taxTotal = $toMinor($o['total_tax'] ?? 0);
        }

        $shipping = $toMinor($o['shipping_total'] ?? 0);
        $discount = $toMinor($o['discount_total'] ?? 0);
        $total    = $toMinor($o['total'] ?? $mirror->total_minor);

        return new InvoiceDraft(
            source: 'woocommerce',
            docType: $taxTotal > 0 ? 'tax_invoice' : 'receipt',
            currency: $currency,
            currencyExponent: $exp,
            buyer: [
                'name'  => trim((string) ($billing['first_name'] ?? '').' '.(string) ($billing['last_name'] ?? '')) ?: ($mirror->customer_name ?: ''),
                'email' => (string) ($billing['email'] ?? $mirror->customer_email ?? ''),
                'phone' => preg_replace('/\D+/', '', (string) $phone),
            ],
            items: $items,
            taxSummary: $taxSummary,
            subtotalMinor: $subtotal,
            discountMinor: $discount,
            shippingMinor: $shipping,
            taxMinor: $taxTotal,
            totalMinor: $total,
            taxInclusive: filter_var($o['prices_include_tax'] ?? false, FILTER_VALIDATE_BOOL),
            billing: array_filter([
                'address' => trim((string) ($billing['address_1'] ?? '').' '.(string) ($billing['city'] ?? '').' '.(string) ($billing['postcode'] ?? '')),
            ]),
            waOrderId: (int) $mirror->id,
            externalOrderId: (string) ($o['id'] ?? $mirror->woo_order_id ?? ''),
            externalOrderNumber: (string) ($o['number'] ?? $o['id'] ?? ''),
            paidAt: (string) ($o['date_paid'] ?? '') ?: now()->toIso8601String(),
            trigger: $trigger,
            meta: ['woo_status' => $o['status'] ?? null],
        );
    }
}
