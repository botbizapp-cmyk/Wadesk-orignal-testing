<?php

namespace App\Services\Crm;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * AI-CRM Phase 2.2 — the single money-in engine. Records a Payment row and keeps
 * the linked Invoice's paid state in sync. Reused by PaymentsController, the
 * WaOrderPaid bridge listener, and the copilot `record_payment` tool so the logic
 * (and idempotency) lives in exactly one place.
 */
class PaymentLedger
{
    /**
     * Record a payment. $attrs: amount_minor, currency, method, source, paid_at?,
     * reference?, note?, invoice_id?, deal_id?, contact_id?, company_id?,
     * wa_order_id?, gateway_payment_id?, recorded_by?, meta_json?.
     * Idempotent for auto-sourced rows via the (workspace, source, gateway id) key.
     */
    public function record(int $workspaceId, array $attrs): Payment
    {
        $gwId = $attrs['gateway_payment_id'] ?? null;
        if ($gwId && ($attrs['source'] ?? 'manual') !== 'manual') {
            $existing = Payment::where('workspace_id', $workspaceId)
                ->where('source', $attrs['source'])
                ->where('gateway_payment_id', $gwId)->first();
            if ($existing) {
                return $existing; // webhook retry — never double-count
            }
        }

        $payment = Payment::create([
            'workspace_id'       => $workspaceId,
            'invoice_id'         => $attrs['invoice_id']  ?? null,
            'deal_id'            => $attrs['deal_id']     ?? null,
            'contact_id'         => $attrs['contact_id']  ?? null,
            'company_id'         => $attrs['company_id']  ?? null,
            'wa_order_id'        => $attrs['wa_order_id']  ?? null,
            'amount_minor'       => max(0, (int) ($attrs['amount_minor'] ?? 0)),
            'currency'           => strtoupper((string) ($attrs['currency'] ?? 'USD')),
            'method'             => in_array($attrs['method'] ?? 'manual', Payment::METHODS, true) ? $attrs['method'] : 'manual',
            'source'             => in_array($attrs['source'] ?? 'manual', Payment::SOURCES, true) ? $attrs['source'] : 'manual',
            'paid_at'            => $attrs['paid_at'] ?? now(),
            'reference'          => $attrs['reference'] ?? null,
            'note'               => $attrs['note'] ?? null,
            'gateway_payment_id' => $gwId,
            'recorded_by'        => $attrs['recorded_by'] ?? null,
            'meta_json'          => $attrs['meta_json'] ?? null,
        ]);

        if ($payment->invoice_id) {
            $inv = Invoice::forWorkspace($workspaceId)->find($payment->invoice_id);
            if ($inv) {
                $this->syncInvoice($inv);
            }
        }

        return $payment;
    }

    /** Sum of payments applied to an invoice (minor units). */
    public function paidMinor(Invoice $invoice): int
    {
        return (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor');
    }

    /** Outstanding balance on an invoice (never negative). */
    public function outstandingMinor(Invoice $invoice): int
    {
        return max(0, (int) $invoice->total_minor - $this->paidMinor($invoice));
    }

    /**
     * Flip the invoice to 'paid' (with paid_at) once its balance clears. Only
     * touches an 'issued' invoice — never re-opens a void/credited one, and never
     * un-pays (partial refunds are a later concern).
     */
    public function syncInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::STATUS_ISSUED) {
            return;
        }
        if ($this->outstandingMinor($invoice) <= 0) {
            $invoice->forceFill([
                'status'  => Invoice::STATUS_PAID,
                'paid_at' => $invoice->paid_at ?: now(),
            ])->save();
        }
    }

    /**
     * Aging of OUTSTANDING (issued, unpaid/partly-paid) invoices for a workspace,
     * bucketed by days since issue: 0-30 / 31-60 / 61-90 / 90+. Amounts are the
     * per-invoice OUTSTANDING balance, grouped by the invoice currency.
     * Returns ['buckets'=>[...], 'total_outstanding_minor'=>int, 'count'=>int, 'currency'=>string].
     */
    public function aging(int $workspaceId): array
    {
        $labels  = ['0-30', '31-60', '61-90', '90+'];
        $buckets = array_fill_keys($labels, 0);
        $now     = Carbon::now();
        $total   = 0; $count = 0; $currency = null;

        $invoices = Invoice::forWorkspace($workspaceId)
            ->where('status', Invoice::STATUS_ISSUED)
            ->orderByDesc('id')->limit(2000)->get(['id', 'total_minor', 'currency', 'created_at']);

        // One grouped query for all applied payments, keyed by invoice.
        $paidByInv = Payment::whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, SUM(amount_minor) as paid')
            ->groupBy('invoice_id')->pluck('paid', 'invoice_id');

        foreach ($invoices as $inv) {
            $out = max(0, (int) $inv->total_minor - (int) ($paidByInv[$inv->id] ?? 0));
            if ($out <= 0) { continue; }
            $days = $now->diffInDays($inv->created_at ?? $now);
            $b = $days <= 30 ? '0-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'));
            $buckets[$b] += $out;
            $total += $out; $count++;
            $currency = $currency ?: (string) $inv->currency;
        }

        return [
            'buckets'                 => $buckets,
            'total_outstanding_minor' => $total,
            'count'                   => $count,
            'currency'                => $currency ?: 'USD',
        ];
    }
}
