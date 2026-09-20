<?php

namespace App\Services\Invoice\Mappers;

use App\Models\WaOrder;
use App\Services\Invoice\InvoiceDraft;
use App\Support\MoneyFormat;

/**
 * Shopify order payload → InvoiceDraft, parsed from the RAW webhook body. Uses
 * the presentment currency + its ISO exponent (so JPY/IDR don't 100×), reads
 * per-line + order tax_lines, and normalizes the recipient phone to digits.
 */
class ShopifyInvoiceMapper
{
    public function toDraft(array $o, WaOrder $mirror, string $trigger = 'on_paid'): InvoiceDraft
    {
        $currency = strtoupper((string) ($o['presentment_currency'] ?? $o['currency'] ?? $mirror->currency_code ?? 'USD'));
        $exp      = MoneyFormat::ingestExponent($currency);
        $toMinor  = fn ($v) => MoneyFormat::toMinor((string) ($v ?? '0'), $currency);

        $cust  = (array) ($o['customer'] ?? []);
        $ship  = (array) ($o['shipping_address'] ?? []);
        $bill  = (array) ($o['billing_address'] ?? []);
        $phone = $ship['phone'] ?? $cust['phone'] ?? $bill['phone'] ?? $o['phone'] ?? $mirror->customer_phone ?? '';

        // Line items — Shopify `price` is per-unit; tax_lines nest per line.
        $items = [];
        $subtotal = 0;
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $qty     = (float) ($li['quantity'] ?? 1) ?: 1;
            $unit    = $toMinor($li['price'] ?? 0);
            $lineSub = (int) round($unit * $qty);
            $lineTax = 0;
            foreach ((array) ($li['tax_lines'] ?? []) as $tl) {
                $lineTax += $toMinor($tl['price'] ?? 0);
            }
            $subtotal += $lineSub;
            $items[] = [
                'description'         => (string) ($li['title'] ?? 'Item'),
                'sku'                 => (string) ($li['sku'] ?? ''),
                'hsn_sac'             => null,
                'qty'                 => $qty,
                'unit_price_minor'    => $unit,
                'line_subtotal_minor' => $lineSub,
                'line_discount_minor' => $toMinor($li['total_discount'] ?? 0),
                'tax_rate'            => null,
                'tax_amount_minor'    => $lineTax,
                'tax_code'            => null,
            ];
        }

        // Order-level tax_lines → summary (de-duped by title+rate).
        $taxSummary = [];
        $taxTotal = 0;
        foreach ((array) ($o['tax_lines'] ?? []) as $t) {
            $amt = $toMinor($t['price'] ?? 0);
            $taxTotal += $amt;
            $taxSummary[] = [
                'label' => (string) ($t['title'] ?? 'Tax'),
                'rate'  => isset($t['rate']) ? round(((float) $t['rate']) * 100, 3) : null,
                'base_minor'   => $subtotal,
                'amount_minor' => $amt,
            ];
        }
        if ($taxTotal === 0) {
            $taxTotal = $toMinor($o['total_tax'] ?? 0);
        }

        $shipping = 0;
        foreach ((array) ($o['shipping_lines'] ?? []) as $sl) {
            $shipping += $toMinor($sl['price'] ?? 0);
        }
        $discount = $toMinor($o['total_discounts'] ?? 0);
        $total    = $toMinor($o['total_price'] ?? $mirror->total_minor);

        return new InvoiceDraft(
            source: 'shopify',
            docType: $taxTotal > 0 ? 'tax_invoice' : 'receipt',
            currency: $currency,
            currencyExponent: $exp,
            buyer: [
                'name'  => trim((string) ($cust['first_name'] ?? '').' '.(string) ($cust['last_name'] ?? '')) ?: ($mirror->customer_name ?: ''),
                'email' => (string) ($cust['email'] ?? $o['email'] ?? $mirror->customer_email ?? ''),
                'phone' => preg_replace('/\D+/', '', (string) $phone),
            ],
            items: $items,
            taxSummary: $taxSummary,
            subtotalMinor: $subtotal,
            discountMinor: $discount,
            shippingMinor: $shipping,
            taxMinor: $taxTotal,
            totalMinor: $total,
            taxInclusive: filter_var($o['taxes_included'] ?? false, FILTER_VALIDATE_BOOL),
            billing: array_filter([
                'address' => trim((string) ($ship['address1'] ?? '').' '.(string) ($ship['city'] ?? '').' '.(string) ($ship['zip'] ?? '')),
            ]),
            waOrderId: (int) $mirror->id,
            externalOrderId: (string) ($o['id'] ?? $mirror->shopify_order_id ?? ''),
            externalOrderNumber: (string) ($o['name'] ?? $o['order_number'] ?? ''),
            paidAt: (string) ($o['processed_at'] ?? '') ?: now()->toIso8601String(),
            trigger: $trigger,
            meta: ['financial_status' => $o['financial_status'] ?? null],
        );
    }
}
