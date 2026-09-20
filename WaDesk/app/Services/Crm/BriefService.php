<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CrmBrief;
use App\Models\Currency;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * AI-CRM Phase 5 — builds a Client Brief / deck for a contact / company / deal by
 * gathering its real CRM data (deals, invoices, payments, tasks) into a set of
 * narrative sections, then rendering a branded, self-contained HTML deck stored
 * with a public token. Deterministic (no external AI call) so it always renders;
 * an AI-written narrative can layer on top later.
 */
class BriefService
{
    private string $currency = 'USD';

    public function generate(int $workspaceId, string $subjectType, int $subjectId, ?int $userId = null): ?CrmBrief
    {
        if (! in_array($subjectType, ['contact', 'company', 'deal'], true)) {
            return null;
        }
        $this->currency = (string) (InvoiceSetting::forWorkspace($workspaceId)->currency ?? 'USD') ?: 'USD';
        $built = match ($subjectType) {
            'company' => $this->forCompany($workspaceId, $subjectId),
            'contact' => $this->forContact($workspaceId, $subjectId),
            'deal'    => $this->forDeal($workspaceId, $subjectId),
        };
        if (! $built) {
            return null;
        }

        $brand = function_exists('brand_name') ? brand_name() : 'WaDesk';

        $html = view('user.crm.brief', array_merge($built, [
            'brand'       => $brand,
            'currency'    => $this->currency,
            'generatedAt' => now()->format('d M Y'),
        ]))->render();

        return CrmBrief::create([
            'workspace_id' => $workspaceId,
            'created_by'   => $userId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'title'        => $built['title'],
            'html'         => $html,
            'summary'      => $built['summary'] ?? null,
            'public_token' => Str::random(40),
        ]);
    }

    private function money(int $minor): string
    {
        return Currency::symbolFor($this->currency) . number_format($minor / 100, 2);
    }

    private function forCompany(int $wsId, int $id): ?array
    {
        $company = Company::where('workspace_id', $wsId)->find($id);
        if (! $company) return null;

        $contacts   = Contact::where('workspace_id', $wsId)->where('company_id', $id)->count();
        $openDeals  = Deal::where('workspace_id', $wsId)->where('company_id', $id)->where('status', 'open');
        $wonValue   = (int) Deal::where('workspace_id', $wsId)->where('company_id', $id)->where('status', 'won')->sum('value_minor');
        $collected  = (int) Payment::where('workspace_id', $wsId)->where('company_id', $id)->sum('amount_minor');
        $recentDeals= Deal::where('workspace_id', $wsId)->where('company_id', $id)->orderByDesc('id')->limit(6)->get(['title', 'value_minor', 'status']);

        return [
            'title'    => (string) $company->name . ' — ' . __('Account brief'),
            'subject'  => (string) $company->name,
            'summary'  => $contacts . ' contacts, ' . (clone $openDeals)->count() . ' open deals.',
            'stats'    => [
                [__('Contacts'), (string) $contacts],
                [__('Open deals'), (string) (clone $openDeals)->count()],
                [__('Open pipeline'), $this->money((int) (clone $openDeals)->sum('value_minor'))],
                [__('Won revenue'), $this->money($wonValue)],
                [__('Collected'), $this->money($collected)],
            ],
            'deals'    => $recentDeals->map(fn ($d) => ['title' => $d->title, 'value' => $d->value_minor, 'status' => $d->status])->all(),
        ];
    }

    private function forContact(int $wsId, int $id): ?array
    {
        $contact = Contact::where('workspace_id', $wsId)->find($id);
        if (! $contact) return null;

        $deals     = Deal::where('workspace_id', $wsId)->where('contact_id', $id)->orderByDesc('id')->limit(6)->get(['title', 'value_minor', 'status']);
        $collected = (int) Payment::where('workspace_id', $wsId)->where('contact_id', $id)->sum('amount_minor');
        $openValue = (int) Deal::where('workspace_id', $wsId)->where('contact_id', $id)->where('status', 'open')->sum('value_minor');

        return [
            'title'   => (string) ($contact->name ?: __('Contact')) . ' — ' . __('Account brief'),
            'subject' => (string) ($contact->name ?: __('Contact')),
            'summary' => $deals->count() . ' deals on record.',
            'stats'   => [
                [__('Open deals'), (string) Deal::where('workspace_id', $wsId)->where('contact_id', $id)->where('status', 'open')->count()],
                [__('Open value'), $this->money($openValue)],
                [__('Collected'), $this->money($collected)],
            ],
            'deals'   => $deals->map(fn ($d) => ['title' => $d->title, 'value' => $d->value_minor, 'status' => $d->status])->all(),
        ];
    }

    private function forDeal(int $wsId, int $id): ?array
    {
        $deal = Deal::where('workspace_id', $wsId)->find($id);
        if (! $deal) return null;

        return [
            'title'   => (string) $deal->title . ' — ' . __('Deal brief'),
            'subject' => (string) $deal->title,
            'summary' => __('Status') . ': ' . $deal->status . '.',
            'stats'   => [
                [__('Value'), $this->money((int) $deal->value_minor)],
                [__('Status'), ucfirst((string) $deal->status)],
            ],
            'deals'   => [],
        ];
    }
}
