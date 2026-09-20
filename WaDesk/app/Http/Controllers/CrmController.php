<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use App\Services\Crm\PaymentLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AI-CRM Phase 4 — unified CRM dashboard (/crm) + revenue reports + export.
 * Pulls deals + invoices + payments + tasks + contacts into one place and a
 * revenue report over the money tables (collected / outstanding / tax / aging).
 * Amounts are summed in stored minor units in the workspace's own currency —
 * single-currency workspaces (the common case) read exactly; a mixed-currency
 * note is shown when more than one currency is present.
 * Plan-gated (access_sales_pipeline), role manager.
 */
class CrmController extends Controller
{
    public function __construct(private PaymentLedger $ledger) {}

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function currency(int $wsId): string
    {
        return (string) (\App\Models\InvoiceSetting::forWorkspace($wsId)->currency ?? 'USD') ?: 'USD';
    }

    /** Unified CRM home — one board of every KPI. */
    /** In-app how-to guide for every CRM feature (visible, step-by-step). */
    public function guide()
    {
        return view('user.crm.guide');
    }

    public function dashboard()
    {
        $wsId = $this->wsId();
        $cur  = $this->currency($wsId);

        $openDeals   = Deal::where('workspace_id', $wsId)->where('status', 'open');
        $wonMonth    = Deal::where('workspace_id', $wsId)->where('status', 'won')->where('won_at', '>=', now()->startOfMonth());
        $aging       = $this->ledger->aging($wsId);

        $kpis = [
            'open_deals'       => (int) (clone $openDeals)->count(),
            'open_value_minor' => (int) (clone $openDeals)->sum('value_minor'),
            'won_month_minor'  => (int) $wonMonth->sum('value_minor'),
            'collected_month_minor' => (int) Payment::forWorkspace($wsId)->where('paid_at', '>=', now()->startOfMonth())->sum('amount_minor'),
            'outstanding_minor'=> (int) ($aging['total_outstanding_minor'] ?? 0),
            'invoices_open'    => (int) Invoice::forWorkspace($wsId)->where('status', Invoice::STATUS_ISSUED)->count(),
            'invoices_paid'    => (int) Invoice::forWorkspace($wsId)->where('status', Invoice::STATUS_PAID)->count(),
            'tasks_open'       => (int) Task::forWorkspace($wsId)->where('status', 'open')->count(),
            'tasks_overdue'    => (int) Task::forWorkspace($wsId)->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'contacts'         => (int) \App\Models\Contact::where('workspace_id', $wsId)->count(),
        ];

        return view('user.crm.dashboard', [
            'kpis'         => $kpis,
            'aging'        => $aging,
            'currency'     => $cur,
            'recentInvoices' => Invoice::forWorkspace($wsId)->orderByDesc('id')->limit(8)->get(['id', 'invoice_number', 'total_minor', 'currency', 'status']),
            'recentPayments' => Payment::forWorkspace($wsId)->with('contact:id,name')->orderByDesc('paid_at')->limit(8)->get(),
            'upcomingTasks'  => Task::forWorkspace($wsId)->where('status', 'open')->whereNotNull('due_at')->orderBy('due_at')->limit(6)->with('assignee')->get(),
        ]);
    }

    /** Revenue report — collected/outstanding/tax + collected-by-month timeline. */
    public function revenue(Request $request)
    {
        $data = $this->revenueData($this->wsId());
        return view('user.crm.revenue', $data);
    }

    /** CSV export of the payment ledger for the period (default: last 90 days). */
    public function revenueCsv(Request $request)
    {
        $wsId = $this->wsId();
        $rows = Payment::forWorkspace($wsId)->with(['invoice:id,invoice_number', 'contact:id,name', 'company:id,name'])
            ->orderByDesc('paid_at')->limit(5000)->get();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Amount', 'Currency', 'Method', 'Source', 'Invoice', 'Contact', 'Company', 'Reference']);
        foreach ($rows as $p) {
            fputcsv($out, [
                optional($p->paid_at)->format('Y-m-d H:i'),
                number_format($p->amount_minor / 100, 2, '.', ''),
                $p->currency, $p->method, $p->source,
                $p->invoice?->invoice_number ?? '',
                $p->contact?->name ?? '', $p->company?->name ?? '',
                $p->reference ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="revenue-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /** PDF export of the revenue report (DomPDF — same facade invoices use). */
    public function revenuePdf(Request $request)
    {
        $wsId = $this->wsId();
        $data = $this->revenueData($wsId);
        $data['brand'] = function_exists('brand_name') ? brand_name() : 'WaDesk';
        $html = view('user.crm.revenue_pdf', $data)->render();
        $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4');
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="revenue-' . now()->format('Y-m-d') . '.pdf"',
        ]);
    }

    /** Shared revenue figures for the view + PDF. */
    private function revenueData(int $wsId): array
    {
        $cur   = $this->currency($wsId);
        $aging = $this->ledger->aging($wsId);

        // Collected by month (last 6, workspace tz-agnostic — grouped by paid_at month).
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $sum = (int) Payment::forWorkspace($wsId)
                ->whereYear('paid_at', $m->year)->whereMonth('paid_at', $m->month)->sum('amount_minor');
            $months[] = ['label' => $m->format('M Y'), 'minor' => $sum];
        }

        // Tax collected = tax on PAID invoices.
        $taxMinor = (int) Invoice::forWorkspace($wsId)->where('status', Invoice::STATUS_PAID)->sum('tax_minor');

        // Mixed-currency guard.
        $currencies = Payment::forWorkspace($wsId)->distinct()->pluck('currency')->filter()->values();

        return [
            'currency'          => $cur,
            'collectedAll'      => (int) Payment::forWorkspace($wsId)->sum('amount_minor'),
            'collected30'       => (int) Payment::forWorkspace($wsId)->where('paid_at', '>=', now()->subDays(30))->sum('amount_minor'),
            'collectedByMonth'  => $months,
            'outstanding'       => (int) ($aging['total_outstanding_minor'] ?? 0),
            'aging'             => $aging,
            'taxCollected'      => $taxMinor,
            'invoicesPaid'      => (int) Invoice::forWorkspace($wsId)->where('status', Invoice::STATUS_PAID)->count(),
            'invoicesOpen'      => (int) Invoice::forWorkspace($wsId)->where('status', Invoice::STATUS_ISSUED)->count(),
            'mixedCurrency'     => $currencies->count() > 1,
            'generatedAt'       => now()->format('d M Y, H:i'),
        ];
    }
}
