<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PipelineStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Company / Organization CRM (Phase 1). B2B layer over contacts + deals:
 * list, create/edit, and a 360 detail that rolls up the company's contacts,
 * deals and won-revenue. Workspace-scoped; name/email/phone encrypted at rest.
 */
class CompaniesController extends Controller
{
    private function wsId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? 0);
    }

    public function index(Request $request): View
    {
        $wsId = $this->wsId($request);
        $q    = trim((string) $request->query('q', ''));

        $companies = Company::where('workspace_id', $wsId)
            ->withCount(['contacts', 'deals'])
            ->latest('id')->limit(500)->get();

        if ($q !== '') {
            $companies = $companies->filter(fn ($c) => stripos((string) $c->name, $q) !== false)->values();
        }

        // Open-deal count + won value per company, in bulk (cheap over the list).
        $ids      = $companies->pluck('id');
        $openByCo = Deal::whereIn('company_id', $ids)->where('status', 'open')
            ->select('company_id', DB::raw('COUNT(*) c'))->groupBy('company_id')->pluck('c', 'company_id');
        $wonByCo  = Deal::whereIn('company_id', $ids)->where('status', 'won')
            ->select('company_id', DB::raw('SUM(value_minor) v'))->groupBy('company_id')->pluck('v', 'company_id');

        $rows = $companies->map(fn ($c) => [
            'id'         => $c->id,
            'name'       => (string) $c->name,
            'industry'   => (string) ($c->industry ?? ''),
            'website'    => (string) ($c->website ?? ''),
            'contacts'   => (int) $c->contacts_count,
            'open_deals' => (int) ($openByCo[$c->id] ?? 0),
            'won_value'  => round((int) ($wonByCo[$c->id] ?? 0) / 100, 2),
        ]);

        return view('user.companies.index', [
            'companies' => $rows,
            'q'         => $q,
            'kpis'      => [
                'total'      => $rows->count(),
                'with_deals' => $rows->where('open_deals', '>', 0)->count(),
                'won_value'  => round((int) $wonByCo->sum() / 100, 2),
            ],
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $wsId    = $this->wsId($request);
        $company = Company::where('workspace_id', $wsId)->findOrFail($id);

        $contacts   = $company->contacts()->limit(200)->get(['id', 'name', 'country_code', 'mobile', 'email']);
        $stageNames = PipelineStage::where('workspace_id', $wsId)->pluck('name', 'id');
        $deals = $company->deals()->limit(200)->get()->map(fn ($d) => [
            'id'       => $d->id,
            'title'    => $d->title,
            'value'    => round($d->value_minor / 100, 2),
            'currency' => $d->currency,
            'status'   => $d->status,
            'stage'    => $stageNames[$d->stage_id] ?? '—',
        ]);

        return view('user.companies.show', [
            'company'  => $company,
            'contacts' => $contacts->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => (string) ($c->name ?: '(no name)'),
                'phone' => Contact::canonicalizePhone($c->country_code, $c->mobile),
                'email' => (string) ($c->email ?? ''),
            ]),
            'deals'    => $deals,
            'rollup'   => [
                'contacts'   => $contacts->count(),
                'open_deals' => $deals->where('status', 'open')->count(),
                'won_value'  => round($company->wonValueMinor() / 100, 2),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:191',
            'email'      => 'nullable|email|max:191',
            'phone'      => 'nullable|string|max:32',
            'website'    => 'nullable|string|max:191',
            'industry'   => 'nullable|string|max:100',
            'size_range' => 'nullable|string|max:40',
            'address'    => 'nullable|string|max:500',
            'notes'      => 'nullable|string|max:2000',
        ]);
        $data['workspace_id'] = $this->wsId($request);
        $data['user_id']      = $request->user()->id;

        $c = Company::create($data);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $c->id, 'name' => (string) $c->name]);
        }
        return redirect()->route('user.companies.show', $c->id)->with('status', 'Company created.');
    }

    public function update(Request $request, int $id)
    {
        $c = Company::where('workspace_id', $this->wsId($request))->findOrFail($id);
        $data = $request->validate([
            'name'       => 'sometimes|required|string|max:191',
            'email'      => 'nullable|email|max:191',
            'phone'      => 'nullable|string|max:32',
            'website'    => 'nullable|string|max:191',
            'industry'   => 'nullable|string|max:100',
            'size_range' => 'nullable|string|max:40',
            'address'    => 'nullable|string|max:500',
            'notes'      => 'nullable|string|max:2000',
        ]);
        $c->update($data);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $c->id]);
        }
        return back()->with('status', 'Company updated.');
    }

    public function destroy(Request $request, int $id)
    {
        $wsId = $this->wsId($request);
        $c = Company::where('workspace_id', $wsId)->findOrFail($id);
        // Unlink (don't delete) the company's contacts + deals so CRM history stays.
        Contact::where('workspace_id', $wsId)->where('company_id', $c->id)->update(['company_id' => null]);
        Deal::where('workspace_id', $wsId)->where('company_id', $c->id)->update(['company_id' => null]);
        $c->delete();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }
        return redirect()->route('user.companies.index')->with('status', 'Company deleted.');
    }

    /** JSON search for pickers / the AI copilot. */
    public function search(Request $request): JsonResponse
    {
        $wsId = $this->wsId($request);
        $q    = trim((string) $request->query('q', ''));
        $out  = Company::where('workspace_id', $wsId)->latest('id')->limit(500)
            ->get(['id', 'name', 'industry'])
            ->filter(fn ($c) => $q === '' || stripos((string) $c->name, $q) !== false)
            ->take(20)
            ->map(fn ($c) => ['id' => $c->id, 'name' => (string) $c->name, 'industry' => (string) ($c->industry ?? '')])
            ->values();

        return response()->json(['success' => true, 'data' => $out]);
    }
}
