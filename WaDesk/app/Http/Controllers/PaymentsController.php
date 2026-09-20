<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Crm\PaymentLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * AI-CRM Phase 2.2 — payment ledger UI: record money-in, list the ledger,
 * outstanding invoices + aging report. Plan-gated (auto_invoice), workspace-role
 * manager. The record logic lives in PaymentLedger (shared with the copilot +
 * the WaOrderPaid bridge).
 */
class PaymentsController extends Controller
{
    public function __construct(private PaymentLedger $ledger) {}

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    public function index(Request $request)
    {
        $wsId = $this->wsId();

        $payments = Payment::forWorkspace($wsId)
            ->with(['invoice:id,invoice_number,total_minor,currency', 'contact:id,name', 'company:id,name'])
            ->orderByDesc('paid_at')->orderByDesc('id')->limit(200)->get();

        // Outstanding = issued invoices with a positive balance, newest first.
        $issued = Invoice::forWorkspace($wsId)->where('status', Invoice::STATUS_ISSUED)
            ->orderByDesc('id')->limit(200)->get(['id', 'invoice_number', 'total_minor', 'currency', 'created_at']);
        $paidByInv = Payment::whereIn('invoice_id', $issued->pluck('id'))
            ->selectRaw('invoice_id, SUM(amount_minor) as paid')->groupBy('invoice_id')->pluck('paid', 'invoice_id');
        $outstanding = $issued->map(function ($inv) use ($paidByInv) {
            $inv->paid_minor        = (int) ($paidByInv[$inv->id] ?? 0);
            $inv->outstanding_minor = max(0, (int) $inv->total_minor - $inv->paid_minor);
            return $inv;
        })->filter(fn ($inv) => $inv->outstanding_minor > 0)->values();

        $collected30 = (int) Payment::forWorkspace($wsId)
            ->where('paid_at', '>=', now()->subDays(30))->sum('amount_minor');

        return view('user.payments.index', [
            'payments'    => $payments,
            'outstanding' => $outstanding,
            'aging'       => $this->ledger->aging($wsId),
            'collected30' => $collected30,
            'currency'    => (string) (\App\Models\InvoiceSetting::forWorkspace($wsId)->currency ?? 'USD'),
        ]);
    }

    /** Record a payment (full or partial). Amount is entered in MAJOR units. */
    public function store(Request $request)
    {
        $wsId = $this->wsId();
        $data = $request->validate([
            'amount'      => 'required|numeric|min:0.001|max:100000000',
            'currency'    => 'nullable|string|size:3',
            'method'      => ['nullable', Rule::in(Payment::METHODS)],
            'paid_at'     => 'nullable|date',
            'reference'   => 'nullable|string|max:191',
            'note'        => 'nullable|string|max:2000',
            'invoice_id'  => 'nullable|integer',
            'deal_id'     => 'nullable|integer',
            'contact_id'  => 'nullable|integer',
            'company_id'  => 'nullable|integer',
        ]);

        // Resolve currency + exponent from the linked invoice when present.
        $invoice = null;
        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::forWorkspace($wsId)->find($data['invoice_id']);
        }
        $currency = strtoupper($data['currency'] ?? ($invoice->currency ?? 'USD')) ?: 'USD';
        $exp      = $invoice ? (int) ($invoice->currency_exponent ?? 2) : $this->exponent($currency);

        $payment = $this->ledger->record($wsId, [
            'amount_minor' => (int) round(((float) $data['amount']) * (10 ** $exp)),
            'currency'     => $currency,
            'method'       => $data['method'] ?? 'manual',
            'source'       => 'manual',
            'paid_at'      => $data['paid_at'] ?? now(),
            'reference'    => $data['reference'] ?? null,
            'note'         => $data['note'] ?? null,
            'invoice_id'   => $invoice?->id,
            'deal_id'      => $data['deal_id'] ?? null,
            'contact_id'   => $data['contact_id'] ?? null,
            'company_id'   => $data['company_id'] ?? null,
            'wa_order_id'  => $invoice?->wa_order_id,
            'recorded_by'  => Auth::id(),
        ]);

        return back()->with('success', 'Payment of ' . $payment->amount_display . ' recorded.');
    }

    private function exponent(string $currency): int
    {
        static $zero = ['JPY', 'KRW', 'VND', 'IDR', 'CLP', 'ISK', 'HUF', 'XAF', 'XOF', 'PYG', 'RWF', 'UGX', 'KMF'];
        return in_array(strtoupper($currency), $zero, true) ? 0 : 2;
    }
}
