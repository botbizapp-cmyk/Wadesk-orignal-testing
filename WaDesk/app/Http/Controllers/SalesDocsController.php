<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDoc;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Invoice\InvoiceDraft;
use App\Services\Invoice\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * AI-CRM Phase 7 — Proposals and Estimates share this controller. The concrete
 * doc_type is fixed per route group (proposals vs estimates) so the two nav
 * entries feel like separate modules while the storage stays unified.
 */
class SalesDocsController extends Controller
{
    private const ZERO_DEC = ['JPY', 'KRW', 'VND', 'IDR', 'CLP', 'ISK', 'HUF', 'XAF', 'XOF', 'PYG', 'RWF', 'UGX', 'KMF'];

    /** Resolve the doc_type this request is scoped to from the route name. */
    private function docType(Request $request): string
    {
        return Str::contains($request->route()->getName() ?? '', 'estimates')
            ? SalesDoc::TYPE_ESTIMATE
            : SalesDoc::TYPE_PROPOSAL;
    }

    private function wsCurrency(): string
    {
        $ws = Workspace::find(auth()->user()->current_workspace_id ?? 0);
        return strtoupper($ws->currency ?? 'USD');
    }

    private function expFor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DEC, true) ? 0 : 2;
    }

    public function index(Request $request)
    {
        $type = $this->docType($request);

        // Lazy-expire: flip past-due sent docs to expired for display honesty.
        SalesDoc::query()->forCurrentWorkspace()->type($type)
            ->whereIn('status', [SalesDoc::STATUS_DRAFT, SalesDoc::STATUS_SENT])
            ->whereNotNull('valid_until')->whereDate('valid_until', '<', now()->toDateString())
            ->update(['status' => SalesDoc::STATUS_EXPIRED]);

        $docs = SalesDoc::query()->forCurrentWorkspace()->type($type)
            ->orderByRaw("FIELD(status,'draft','sent','accepted','rejected','expired','invoiced')")
            ->orderByDesc('id')->paginate(30);

        $currency = $this->wsCurrency();
        $sumMinor = (int) SalesDoc::query()->forCurrentWorkspace()->type($type)
            ->whereIn('status', [SalesDoc::STATUS_SENT, SalesDoc::STATUS_ACCEPTED])->sum('total_minor');

        $companies = Company::query()->forCurrentWorkspace()->orderBy('id', 'desc')->limit(500)->get(['id', 'name']);
        $members = $this->members();

        return view('user.salesdocs.index', [
            'type'       => $type,
            'typeLabel'  => $type === SalesDoc::TYPE_ESTIMATE ? 'Estimate' : 'Proposal',
            'typePlural' => $type === SalesDoc::TYPE_ESTIMATE ? 'Estimates' : 'Proposals',
            'docs'       => $docs,
            'currency'   => $currency,
            'exponent'   => $this->expFor($currency),
            'openMinor'  => $sumMinor,
            'companies'  => $companies,
            'members'    => $members,
            'me'         => (int) auth()->id(),
        ]);
    }

    public function store(Request $request)
    {
        $type = $this->docType($request);
        $data = $request->validate([
            'title'        => ['nullable', 'string', 'max:255'],
            'buyer_name'   => ['nullable', 'string', 'max:255'],
            'buyer_email'  => ['nullable', 'string', 'max:255'],
            'buyer_phone'  => ['nullable', 'string', 'max:60'],
            'company_id'   => ['nullable', 'integer'],
            'owner_id'     => ['nullable', 'integer'],
            'valid_until'  => ['nullable', 'date'],
            'tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:5000'],
            'items'        => ['required', 'array', 'min:1'],
            'items.*.description'      => ['required', 'string', 'max:255'],
            'items.*.qty'              => ['required', 'numeric', 'min:0'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0'],
        ]);

        $currency = $this->wsCurrency();
        $exp = $this->expFor($currency);
        $mult = 10 ** $exp;

        $items = [];
        $subtotal = 0;
        foreach ($data['items'] as $row) {
            $qty = (float) $row['qty'];
            $unitMinor = (int) round(((float) $row['unit_price']) * $mult);
            $lineMinor = (int) round($qty * $unitMinor);
            $subtotal += $lineMinor;
            $items[] = [
                'description'      => trim($row['description']),
                'qty'              => $qty,
                'unit_price_minor' => $unitMinor,
                'line_total_minor' => $lineMinor,
            ];
        }
        $taxRateBp = (int) round(((float) ($data['tax_rate'] ?? 0)) * 100);
        $taxMinor = (int) round($subtotal * $taxRateBp / 10000);
        $total = $subtotal + $taxMinor;

        $companyId = $data['company_id'] ?? null;
        if ($companyId && ! Company::query()->forCurrentWorkspace()->whereKey($companyId)->exists()) {
            $companyId = null;
        }

        $wsId = (int) auth()->user()->current_workspace_id;
        $seq = (int) SalesDoc::query()->where('workspace_id', $wsId)->where('doc_type', $type)->max('seq') + 1;
        $prefix = $type === SalesDoc::TYPE_ESTIMATE ? 'EST-' : 'PRO-';

        SalesDoc::create([
            'workspace_id'      => $wsId,
            'doc_type'          => $type,
            'number'            => $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'seq'               => $seq,
            'status'            => SalesDoc::STATUS_DRAFT,
            'title'             => $data['title'] ?? null,
            'company_id'        => $companyId,
            'owner_id'          => $data['owner_id'] ?? auth()->id(),
            'created_by'        => auth()->id(),
            'buyer_name'        => $data['buyer_name'] ?? null,
            'buyer_email'       => $data['buyer_email'] ?? null,
            'buyer_phone'       => $data['buyer_phone'] ?? null,
            'currency'          => $currency,
            'currency_exponent' => $exp,
            'subtotal_minor'    => $subtotal,
            'discount_minor'    => 0,
            'tax_minor'         => $taxMinor,
            'total_minor'       => $total,
            'tax_rate_bp'       => $taxRateBp,
            'items_json'        => $items,
            'notes'             => $data['notes'] ?? null,
            'valid_until'       => $data['valid_until'] ?? null,
            'public_token'      => Str::random(40),
        ]);

        $label = $type === SalesDoc::TYPE_ESTIMATE ? 'Estimate' : 'Proposal';
        return back()->with('success', $label . ' created.');
    }

    /** Status transitions: mark sent / accepted / rejected. */
    public function update(Request $request, int $id)
    {
        $doc = SalesDoc::query()->forCurrentWorkspace()->findOrFail($id);
        $status = (string) $request->input('status');
        if (! in_array($status, [SalesDoc::STATUS_SENT, SalesDoc::STATUS_ACCEPTED, SalesDoc::STATUS_REJECTED], true)) {
            return back()->with('error', 'Unknown status.');
        }
        $doc->status = $status;
        if ($status === SalesDoc::STATUS_SENT && ! $doc->sent_at) {
            $doc->sent_at = now();
        }
        if (in_array($status, [SalesDoc::STATUS_ACCEPTED, SalesDoc::STATUS_REJECTED], true)) {
            $doc->decided_at = now();
        }
        $doc->save();

        return back()->with('success', $doc->typeLabel() . ' marked ' . $status . '.');
    }

    /** Convert an accepted/sent doc into a real Invoice via the existing engine. */
    public function convert(Request $request, int $id, InvoiceService $invoices)
    {
        $doc = SalesDoc::query()->forCurrentWorkspace()->findOrFail($id);
        if ($doc->invoice_id) {
            return back()->with('error', 'Already converted to invoice #' . $doc->invoice_id . '.');
        }

        $ws = Workspace::find($doc->workspace_id);
        $items = [];
        foreach (($doc->items_json ?? []) as $row) {
            $items[] = [
                'description'        => $row['description'] ?? '',
                'qty'                => $row['qty'] ?? 1,
                'unit_price_minor'   => (int) ($row['unit_price_minor'] ?? 0),
                'line_subtotal_minor' => (int) ($row['line_total_minor'] ?? 0),
                'line_discount_minor' => 0,
                'tax_rate'           => 0,
                'tax_amount_minor'   => 0,
            ];
        }

        $draft = new InvoiceDraft(
            source: 'manual',
            docType: 'tax_invoice',
            currency: $doc->currency,
            currencyExponent: $doc->currency_exponent,
            buyer: array_filter([
                'name'  => $doc->buyer_name,
                'email' => $doc->buyer_email,
                'phone' => $doc->buyer_phone,
            ]),
            items: $items,
            taxSummary: $doc->tax_minor > 0 ? [[
                'label'       => 'Tax',
                'rate'        => $doc->tax_rate_bp / 100,
                'base_minor'  => $doc->subtotal_minor,
                'amount_minor' => $doc->tax_minor,
            ]] : [],
            subtotalMinor: $doc->subtotal_minor,
            discountMinor: $doc->discount_minor,
            shippingMinor: 0,
            taxMinor: $doc->tax_minor,
            totalMinor: $doc->total_minor,
            waOrderId: null,
            trigger: 'manual',
            meta: ['from_sales_doc' => $doc->id, 'sales_doc_number' => $doc->number],
        );

        $invoice = $invoices->issue($draft, $ws, (int) auth()->id());
        if (! $invoice instanceof Invoice) {
            return back()->with('error', 'Invoicing is not available on your current plan.');
        }
        $invoices->renderAndSend($invoice);

        $doc->invoice_id = $invoice->id;
        $doc->status = SalesDoc::STATUS_INVOICED;
        $doc->save();

        return redirect()->route('user.invoices.index')->with('success', $doc->typeLabel() . ' converted to invoice ' . ($invoice->invoice_number ?? ('#' . $invoice->id)) . '.');
    }

    /**
     * Send the quote's public link to the buyer over WhatsApp — through the
     * workspace's OWN engine (device or WABA), logged as an outbound message,
     * exactly like checkout links. Not a wa.me hand-off.
     */
    public function sendWhatsApp(int $id, \App\Services\WhatsAppDispatcher $dispatcher)
    {
        $doc = SalesDoc::query()->forCurrentWorkspace()->findOrFail($id);
        $to = preg_replace('/\D+/', '', (string) $doc->buyer_phone);
        if ($to === '') {
            return back()->with('error', 'Add a buyer phone number first, then send.');
        }

        $brand = function_exists('brand_name') ? brand_name() : config('app.name');
        $body = $doc->typeLabel() . ' ' . $doc->number
            . ($doc->title ? (' — ' . $doc->title) : '')
            . "\n" . 'Total: ' . $doc->total_display
            . "\n" . 'View & accept: ' . $doc->publicUrl();

        $msg = \App\Models\Message::create([
            'user_id'      => auth()->id(),
            'workspace_id' => $doc->workspace_id,   // dispatcher routes by the workspace engine
            'direction'    => 'out',
            'to_number'    => $to,
            'body'         => $body,
            'status'       => 'pending',
        ]);

        try {
            $res = $dispatcher->send($msg);
            $ok = (bool) ($res['ok'] ?? false);
            $msg->forceFill([
                'status'         => $ok ? 'sent' : 'failed',
                'failure_reason' => $ok ? null : ($res['error'] ?? null),
                'sent_at'        => $ok ? now() : null,
            ])->save();

            if (! $ok) {
                return back()->with('error', 'Could not send: ' . ($res['error'] ?? 'no WhatsApp engine connected. Connect a device or WABA first.'));
            }
        } catch (\Throwable $e) {
            $msg->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)])->save();
            return back()->with('error', 'Send failed: ' . $e->getMessage());
        }

        // Mark it sent (so the pipeline reflects reality) if still a draft.
        if ($doc->status === SalesDoc::STATUS_DRAFT) {
            $doc->status = SalesDoc::STATUS_SENT;
        }
        if (! $doc->sent_at) {
            $doc->sent_at = now();
        }
        $doc->save();

        return back()->with('success', $doc->typeLabel() . ' sent on WhatsApp to ' . $to . '.');
    }

    public function destroy(int $id)
    {
        $doc = SalesDoc::query()->forCurrentWorkspace()->findOrFail($id);
        $label = $doc->typeLabel();
        $doc->delete();
        return back()->with('success', $label . ' deleted.');
    }

    /** Public read-only share page (no auth). */
    public function publicShow(string $token)
    {
        $doc = SalesDoc::query()->where('public_token', $token)->firstOrFail();
        return view('user.salesdocs.public', ['doc' => $doc]);
    }

    /** Customer self-accepts the quote from the public link (no auth). */
    public function publicAccept(string $token)
    {
        return $this->publicDecide($token, SalesDoc::STATUS_ACCEPTED);
    }

    /** Customer declines the quote from the public link (no auth). */
    public function publicDecline(string $token)
    {
        return $this->publicDecide($token, SalesDoc::STATUS_REJECTED);
    }

    private function publicDecide(string $token, string $status)
    {
        $doc = SalesDoc::query()->where('public_token', $token)->firstOrFail();

        // Only an open (not yet decided / invoiced) quote can be acted on.
        $openStates = [SalesDoc::STATUS_DRAFT, SalesDoc::STATUS_SENT, SalesDoc::STATUS_EXPIRED];
        if (in_array($doc->status, $openStates, true)) {
            $doc->status = $status;
            $doc->decided_at = now();
            $doc->save();

            // Notify the owner that the customer responded.
            try {
                $dispatcher = app(\App\Services\Inbox\NotificationDispatcher::class);
                if (method_exists($dispatcher, 'notifySalesDocDecided')) {
                    $dispatcher->notifySalesDocDecided($doc);
                }
            } catch (\Throwable $e) {
                // best-effort — the status change is what matters
            }
        }

        return redirect()->route('salesdoc.public.show', $token)
            ->with('decided', $doc->status);
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    private function members()
    {
        $wsId = (int) auth()->user()->current_workspace_id;
        return User::query()
            ->whereIn('id', function ($q) use ($wsId) {
                $q->select('user_id')->from('workspace_user')->where('workspace_id', $wsId);
            })
            ->orderBy('name')->get(['id', 'name']);
    }
}
