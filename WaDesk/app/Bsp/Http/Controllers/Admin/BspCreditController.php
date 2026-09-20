<?php

namespace App\Bsp\Http\Controllers\Admin;

use App\Bsp\Models\BspCreditAllocation;
use App\Bsp\Services\CreditAllocationService;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin credit allocations (P5). Attach WaDesk's own Meta credit line to a
 * customer WABA (so Meta bills us, not them) and revoke it. Path A only.
 */
class BspCreditController extends Controller
{
    public function __construct(private CreditAllocationService $svc) {}

    public function index(): Response
    {
        $rows = BspCreditAllocation::orderByDesc('id')->paginate(50);

        return response()->view('bsp.credit.index', [
            'rows'         => $rows,
            'configured'   => $this->svc->source()->isConfigured(),
            'businessId'   => (string) SystemSetting::get('bsp_meta_business_id', ''),
            'creditId'     => (string) SystemSetting::get('bsp_meta_extended_credit_id', ''),
            'tokenSet'     => trim((string) SystemSetting::get('bsp_meta_system_user_token', '')) !== '',
            'graphVersion' => (string) SystemSetting::get('bsp_graph_version', 'v26.0'),
            'ratesEndpoint'=> (string) SystemSetting::get('bsp_meta_rates_endpoint', ''),
            // Every connected WhatsApp Business (Cloud API) number, so the
            // reseller PICKS the customer from a list by name — no hand-typed
            // workspace / WABA IDs. Each entry carries the real workspace_id +
            // waba_id the attach() call needs, hidden behind a readable label.
            'wabaAccounts' => $this->connectedWabaAccounts(),
            // id => name, so the attached-accounts table shows a workspace by
            // name instead of a bare numeric id.
            'workspaceNames' => \App\Models\Workspace::pluck('name', 'id'),
            // Auto-attach state for the "hands-off" toggle.
            'autoAttach'         => (string) SystemSetting::get('bsp_auto_attach_credit', '0') === '1',
            'autoAttachCurrency' => strtoupper((string) SystemSetting::get('bsp_auto_attach_currency', '')),
        ]);
    }

    /**
     * Connected WABA numbers across all workspaces, shaped for the picker.
     * waba_id lives inside the encrypted credentials blob, so decrypt each
     * row's creds() and surface only what the dropdown needs.
     *
     * @return array<int,array{workspace_id:int,workspace_name:string,waba_id:string,phone:string,label:string}>
     */
    private function connectedWabaAccounts(): array
    {
        $out = [];
        \App\Models\WaProviderConfig::query()
            ->where('provider', 'waba')
            ->with('workspace:id,name')
            ->orderByDesc('id')
            ->get()
            ->each(function ($cfg) use (&$out) {
                $creds  = $cfg->creds();
                $wabaId = trim((string) ($creds['waba_id'] ?? ''));
                if ($wabaId === '') return; // no WABA id yet — can't attach a credit line

                $wsName = $cfg->workspace?->name ?: ('Workspace #' . $cfg->workspace_id);
                $phone  = $cfg->phone_number ?: ($cfg->display_label ?: '');
                $out[]  = [
                    'workspace_id'   => (int) $cfg->workspace_id,
                    'workspace_name' => $wsName,
                    'waba_id'        => $wabaId,
                    'phone'          => $phone,
                    'label'          => trim($wsName . ($phone ? '  ·  ' . $phone : '') . '  ·  WABA …' . substr($wabaId, -4)),
                ];
            });

        return $out;
    }

    /** Save the Solution-Partner connection settings (token stored, not echoed). */
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bsp_meta_business_id'        => 'nullable|string|max:64',
            'bsp_meta_extended_credit_id' => 'nullable|string|max:64',
            'bsp_meta_system_user_token'  => 'nullable|string|max:1000',
            'bsp_graph_version'           => 'nullable|string|max:12',
            'bsp_meta_rates_endpoint'     => 'nullable|url|max:500',
        ]);

        // Type 'string' on every call — SystemSetting::set defaults to 'int',
        // which would zero a business id / token / version.
        SystemSetting::set('bsp_meta_business_id', trim((string) ($data['bsp_meta_business_id'] ?? '')), 'string');
        SystemSetting::set('bsp_meta_extended_credit_id', trim((string) ($data['bsp_meta_extended_credit_id'] ?? '')), 'string');
        SystemSetting::set('bsp_graph_version', trim((string) ($data['bsp_graph_version'] ?? 'v26.0')) ?: 'v26.0', 'string');
        SystemSetting::set('bsp_meta_rates_endpoint', trim((string) ($data['bsp_meta_rates_endpoint'] ?? '')), 'string');
        // Only overwrite the token when a new value is actually supplied.
        if (trim((string) ($data['bsp_meta_system_user_token'] ?? '')) !== '') {
            SystemSetting::set('bsp_meta_system_user_token', trim($data['bsp_meta_system_user_token']), 'string');
        }

        // Auto-attach preferences — checkbox + default currency for the
        // "share my credit line automatically when a customer connects" mode.
        SystemSetting::set('bsp_auto_attach_credit', $request->boolean('bsp_auto_attach_credit') ? '1' : '0', 'string');
        // Any currency code is allowed — nothing hardcoded.
        $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $request->input('bsp_auto_attach_currency', '')));
        SystemSetting::set('bsp_auto_attach_currency', mb_substr($cur, 0, 8), 'string');

        return back()->with('success', __('Meta connection saved.'));
    }

    /**
     * Backfill: attach the credit line to every connected number that isn't
     * attached yet — one click instead of picking customers one by one, and
     * the retry path once Meta approval lands.
     */
    public function attachAll(Request $request): RedirectResponse
    {
        $r = $this->svc->attachAllConnected($request->user()->id);

        if (! ($r['ok'] ?? false)) {
            return back()->with('error', $r['error'] ?? __('Could not attach.'));
        }

        return back()->with('success', __('Done — :a attached, :f failed, :s already set up.', [
            'a' => $r['attached'], 'f' => $r['failed'], 's' => $r['skipped'],
        ]));
    }

    public function attach(Request $request): RedirectResponse
    {
        // Primary path: one picker whose value is "workspaceId|wabaId" (chosen
        // by name, no hand-typed IDs). Legacy separate fields still accepted.
        $account = trim((string) $request->input('account', ''));
        if ($account !== '' && str_contains($account, '|')) {
            [$wsId, $wabaId] = array_pad(explode('|', $account, 2), 2, '');
            $request->merge(['workspace_id' => (int) $wsId, 'waba_id' => $wabaId]);
        }

        $data = $request->validate([
            'workspace_id' => 'required|integer|min:1',
            'waba_id'      => 'required|string|max:64',
            'currency'     => 'required|string|max:8',
        ]);

        $alloc = $this->svc->attach(
            (int) $data['workspace_id'],
            $data['waba_id'],
            $data['currency'],
            $request->user()->id
        );

        return $alloc->status === 'attached'
            ? back()->with('success', __('Credit line attached to WABA :w.', ['w' => $alloc->waba_id]))
            : back()->with('error', __('Attach failed: :e', ['e' => $alloc->last_error]));
    }

    public function revoke(Request $request, int $id): RedirectResponse
    {
        $alloc = BspCreditAllocation::findOrFail($id);
        return $this->svc->revoke($alloc, $request->user()->id)
            ? back()->with('success', __('Credit allocation revoked.'))
            : back()->with('error', __('Revoke failed: :e', ['e' => $alloc->fresh()->last_error]));
    }
}
