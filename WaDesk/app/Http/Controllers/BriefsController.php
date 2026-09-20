<?php

namespace App\Http\Controllers;

use App\Models\CrmBrief;
use App\Services\Crm\BriefService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * AI-CRM Phase 5 — generate a Client Brief / deck for a contact / company / deal
 * and serve its PUBLIC shareable view + PDF (token-only, like invoices /i/{token}).
 * Generation is plan-gated (access_sales_pipeline); the public token routes are
 * token-only so a brief can be shared / sent over WhatsApp.
 */
class BriefsController extends Controller
{
    public function __construct(private BriefService $briefs) {}

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Generate a brief and redirect to its public deck. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['contact', 'company', 'deal'])],
            'subject_id'   => 'required|integer',
        ]);

        $brief = $this->briefs->generate($this->wsId(), $data['subject_type'], (int) $data['subject_id'], Auth::id());
        if (! $brief) {
            return back()->with('error', 'Could not generate a brief for that record.');
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'url' => $brief->publicUrl(), 'token' => $brief->public_token]);
        }
        return redirect()->away($brief->publicUrl());
    }

    /** Public: render the stored self-contained deck HTML. */
    public function publicShow(string $token)
    {
        $brief = CrmBrief::where('public_token', $token)->firstOrFail();
        return response($brief->html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Public: PDF of the deck via DomPDF. */
    public function publicPdf(string $token)
    {
        $brief = CrmBrief::where('public_token', $token)->firstOrFail();
        $pdf   = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($brief->html)->setPaper('a4');
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="brief-' . $brief->id . '.pdf"',
        ]);
    }
}
