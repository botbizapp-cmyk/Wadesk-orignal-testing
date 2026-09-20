<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\SystemSetting;
use App\Services\LeadFinder\GooglePlacesLeadSource;
use App\Services\LeadFinder\LeadFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Lead Finder — search a map source (free OpenStreetMap) for "category + city",
 * save the results per workspace, and one-click push them into Contacts or a
 * campaign. No paid API / key required.
 */
class LeadFinderController extends Controller
{
    public function index(Request $request): View
    {
        $wsId  = (int) $request->user()->current_workspace_id;
        $leads = Lead::where('workspace_id', $wsId)->latest()->limit(500)->get();
        $gkey  = $this->googleKey($wsId);

        return view('user.lead-finder.index', [
            'leads'     => $leads,
            'hasGoogle' => (bool) $gkey,
            'gmapsKey'  => $gkey,   // exposed to the browser only for the Google map display
            'counts' => [
                'all'      => $leads->count(),
                'whatsapp' => $leads->whereNotNull('phone_e164')->count(),
                'email'    => $leads->whereNotNull('email')->count(),
                'not_crm'  => $leads->where('in_crm', false)->count(),
            ],
        ]);
    }

    /**
     * Run a live search and upsert results. Three modes:
     *   place  — category + city name (geocode -> bbox)
     *   bbox   — the current map view [south,west,north,east]  ("Scan this area")
     *   around — a clicked point + radius km                   ("scan here")
     */
    public function search(Request $request, LeadFinderService $svc): JsonResponse
    {
        $data = $request->validate([
            'mode'     => 'nullable|in:place,bbox,around',
            'category' => 'nullable|string|max:120',
            'place'    => 'nullable|string|max:160',
            'bbox'     => 'nullable|array|size:4',
            'bbox.*'   => 'numeric',
            'lat'      => 'nullable|numeric',
            'lng'      => 'nullable|numeric',
            'radius'   => 'nullable|numeric', // metres
        ]);

        $wsId   = (int) $request->user()->current_workspace_id;
        $userId = (int) $request->user()->id;
        $mode   = $data['mode'] ?? 'place';
        $cat    = trim((string) ($data['category'] ?? ''));

        // BYOK: if the workspace pasted a Google Places key, use the richer
        // Google engine; otherwise the free OpenStreetMap engine ($svc).
        $gkey   = $this->googleKey($wsId);
        $engine = $gkey ? new GooglePlacesLeadSource($gkey) : $svc;

        if ($mode === 'bbox' && ! empty($data['bbox'])) {
            [$s, $w, $n, $e] = array_map('floatval', $data['bbox']);
            $result = $engine->searchBbox($cat, $s, $w, $n, $e);
        } elseif ($mode === 'around' && isset($data['lat'], $data['lng'])) {
            $result = $engine->searchAround($cat, (float) $data['lat'], (float) $data['lng'], (int) ($data['radius'] ?? 3000));
        } else {
            if ($cat === '' || empty($data['place'])) {
                return response()->json(['ok' => false, 'error' => 'need_category_and_place', 'leads' => []], 200);
            }
            $result = $engine->search($cat, $data['place']);
            // Google's text search returns no explicit centre — use the first hit.
            if ($gkey && empty($result['center']) && ! empty($result['leads'][0]['lat'])) {
                $result['center'] = ['lat' => $result['leads'][0]['lat'], 'lng' => $result['leads'][0]['lng']];
            }
        }

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok'    => false,
                'error' => $result['error'] ?? 'search_failed',
                'center'=> $result['center'] ?? null,
            ], 200);
        }

        $saved = [];
        foreach ($result['leads'] as $l) {
            $row = Lead::updateOrCreate(
                ['workspace_id' => $wsId, 'source' => $l['source'], 'external_id' => $l['external_id']],
                array_merge($l, ['user_id' => $userId, 'raw' => $l]),
            );
            // Reflect existing CRM state without clobbering it on re-scan.
            $saved[] = $this->present($row);
        }

        return response()->json([
            'ok'     => true,
            'leads'  => $saved,
            'center' => $result['center'],
        ]);
    }

    /** Deep-scrape one lead's website on demand to find its phone/WhatsApp + email. */
    public function enrich(Request $request, Lead $lead, LeadFinderService $svc): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        if (! $lead->website) {
            return response()->json(['ok' => false, 'error' => 'no_website'], 200);
        }
        $c = $svc->enrichWebsite($lead->website);
        $phone = $c['phone'] ?? null;
        $email = $c['email'] ?? null;
        if (! $phone && ! $email) {
            return response()->json(['ok' => false, 'error' => 'nothing_found'], 200);
        }
        $lead->update([
            'phone'      => $lead->phone ?: $phone,
            'phone_e164' => $lead->phone_e164 ?: ($phone ? preg_replace('/\D+/', '', $phone) : null),
            'email'      => $lead->email ?: $email,
        ]);

        return response()->json(['ok' => true, 'lead' => $this->present($lead->fresh())]);
    }

    /** Add a single lead to Contacts. */
    public function addContact(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        if (! $lead->phone_e164 && ! $lead->email) {
            return response()->json(['ok' => false, 'error' => 'no_phone_or_email'], 200);
        }

        $contact = $this->toContact($request, $lead, 'Lead Finder');
        $lead->update(['in_crm' => true, 'contact_id' => $contact->id]);

        return response()->json(['ok' => true, 'contact_id' => $contact->id]);
    }

    /**
     * Bulk-add selected leads into Contacts under a named group, so the user can
     * immediately start a campaign / broadcast to that group.
     */
    public function bulkAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_ids' => 'required|array|min:1',
            'lead_ids.*' => 'integer',
            'group'    => 'nullable|string|max:80',
        ]);
        $wsId  = (int) $request->user()->current_workspace_id;
        $group = trim((string) ($data['group'] ?? '')) ?: ('Leads · ' . now()->format('d M'));

        $leads = Lead::where('workspace_id', $wsId)
            ->whereIn('id', $data['lead_ids'])
            ->whereNotNull('phone_e164')
            ->get();

        $added = 0;
        foreach ($leads as $lead) {
            $contact = $this->toContact($request, $lead, $group);
            $lead->update(['in_crm' => true, 'contact_id' => $contact->id]);
            $added++;
        }

        return response()->json([
            'ok'           => true,
            'added'        => $added,
            'group'        => $group,
            // Point the user at the campaign builder to message this fresh group.
            'campaign_url' => url('/wa-campaigns/create') . '?group=' . urlencode($group),
        ]);
    }

    /** Delete a saved lead. */
    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $lead->delete();

        return response()->json(['ok' => true]);
    }

    /** Clear ALL saved leads for the workspace. */
    public function clear(Request $request): JsonResponse
    {
        $wsId = (int) $request->user()->current_workspace_id;
        Lead::where('workspace_id', $wsId)->delete();

        return response()->json(['ok' => true]);
    }

    /** Save (validate) or the workspace's own Google Places API key. */
    public function saveKey(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|min:20|max:200']);
        $key  = trim($data['key']);

        // Probe the key against Google before storing so we fail fast.
        if (! (new GooglePlacesLeadSource($key))->validate()) {
            return response()->json(['ok' => false, 'error' => 'invalid_key'], 200);
        }

        $wsId = (int) $request->user()->current_workspace_id;
        SystemSetting::set($this->keyName($wsId), Crypt::encryptString($key), 'string', 'Lead Finder Google Places key (encrypted)');

        return response()->json(['ok' => true]);
    }

    /** Remove the stored Google key → fall back to the free OSM engine. */
    public function removeKey(Request $request): JsonResponse
    {
        $wsId = (int) $request->user()->current_workspace_id;
        SystemSetting::set($this->keyName($wsId), '', 'string', 'Lead Finder Google Places key (cleared)');

        return response()->json(['ok' => true]);
    }

    private function keyName(int $wsId): string
    {
        return 'leadfinder.gmaps_key.' . $wsId;
    }

    /** Decrypted Google key for the workspace, or null. */
    private function googleKey(int $wsId): ?string
    {
        $raw = (string) SystemSetting::get($this->keyName($wsId), '');
        if ($raw === '') {
            return null;
        }
        try {
            return Crypt::decryptString($raw) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ---- helpers ---- */

    private function toContact(Request $request, Lead $lead, string $group): Contact
    {
        $wsId   = (int) $request->user()->current_workspace_id;
        $userId = (int) $request->user()->id;
        $name   = $lead->name ?: ($lead->phone_e164 ?: $lead->email);

        // Dedupe on (workspace, mobile) when we have a number, else on (workspace, email).
        $key = $lead->phone_e164
            ? ['workspace_id' => $wsId, 'mobile' => $lead->phone_e164]
            : ['workspace_id' => $wsId, 'email' => $lead->email];

        return Contact::updateOrCreate($key, [
            'user_id'       => $userId,
            'name'          => $name,
            'first_name'    => Str::of($name)->explode(' ')->first(),
            'email'         => $lead->email,
            'mobile'        => $lead->phone_e164,
            'address'       => $lead->address,
            'contact_group' => $group,
        ]);
    }

    private function present(Lead $l): array
    {
        return [
            'id'         => $l->id,
            'name'       => $l->name,
            'category'   => $l->category,
            'phone'      => $l->phone,
            'phone_e164' => $l->phone_e164,
            'email'      => $l->email,
            'website'    => $l->website,
            'address'    => $l->address,
            'lat'        => $l->lat,
            'lng'        => $l->lng,
            'in_crm'     => (bool) $l->in_crm,
        ];
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        abort_unless((int) $lead->workspace_id === (int) $request->user()->current_workspace_id, 403);
    }
}
