<?php

namespace App\Services\LeadFinder;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lead Finder data engine — 100% free, no API key, no billing:
 *   - Geocode "city / area"  -> OpenStreetMap Nominatim.
 *   - Pull businesses         -> OpenStreetMap Overpass API (by city bbox,
 *     by the current MAP VIEW bbox, or in a RADIUS around a clicked point).
 *   - Best-effort email       -> scrape the business website.
 *
 * (Google Maps/Places is NOT usable here — its JS + Places APIs require an API
 * key, and Places needs billing enabled; there is no keyless Google data path.)
 */
class LeadFinderService
{
    private const UA = 'WaDesk-LeadFinder/1.0 (+self-hosted CRM; contact admin)';

    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /**
     * Friendly category -> one or more OSM tag filters (broad = more hits).
     * Matched by substring against the typed category. Falls back to a name
     * regex when nothing matches.
     */
    private const CATEGORY_TAGS = [
        'restaurant'  => ['amenity=restaurant', 'amenity=fast_food'],
        'food'        => ['amenity=restaurant', 'amenity=fast_food', 'amenity=cafe'],
        'cafe'        => ['amenity=cafe'],
        'coffee'      => ['amenity=cafe'],
        'hotel'       => ['tourism=hotel', 'tourism=guest_house'],
        'bar'         => ['amenity=bar', 'amenity=pub'],
        'pharmacy'    => ['amenity=pharmacy', 'shop=chemist'],
        'medical'     => ['amenity=hospital', 'amenity=clinic', 'amenity=doctors', 'amenity=pharmacy'],
        'hospital'    => ['amenity=hospital', 'amenity=clinic'],
        'clinic'      => ['amenity=clinic', 'amenity=doctors'],
        'dentist'     => ['amenity=dentist'],
        'doctor'      => ['amenity=doctors', 'amenity=clinic'],
        'gym'         => ['leisure=fitness_centre', 'leisure=sports_centre'],
        'salon'       => ['shop=hairdresser', 'shop=beauty', 'shop=massage', 'leisure=spa'],
        'parlour'     => ['shop=beauty', 'shop=hairdresser'],
        'beauty'      => ['shop=beauty', 'shop=hairdresser', 'leisure=spa'],
        'spa'         => ['leisure=spa', 'shop=massage'],
        'lawyer'      => ['office=lawyer'],
        'legal'       => ['office=lawyer'],
        'accountant'  => ['office=accountant', 'office=tax_advisor'],
        'real estate' => ['office=estate_agent'],
        'realtor'     => ['office=estate_agent'],
        'property'    => ['office=estate_agent'],
        'insurance'   => ['office=insurance'],
        'school'      => ['amenity=school', 'amenity=college'],
        'coaching'    => ['amenity=school', 'office=educational_institution'],
        'car'         => ['shop=car', 'shop=car_repair', 'shop=car_parts'],
        'car repair'  => ['shop=car_repair'],
        'garage'      => ['shop=car_repair'],
        'supermarket' => ['shop=supermarket', 'shop=convenience'],
        'grocery'     => ['shop=supermarket', 'shop=convenience', 'shop=greengrocer'],
        'bakery'      => ['shop=bakery', 'shop=confectionery'],
        'sweet'       => ['shop=confectionery'],
        'clothing'    => ['shop=clothes', 'shop=boutique'],
        'boutique'    => ['shop=boutique', 'shop=clothes'],
        'jewel'       => ['shop=jewelry'],
        'mobile'      => ['shop=mobile_phone'],
        'electronics' => ['shop=electronics'],
        'hardware'    => ['shop=hardware', 'shop=doityourself'],
        'furniture'   => ['shop=furniture'],
        'travel'      => ['shop=travel_agency', 'tourism=information'],
        'plumber'     => ['craft=plumber'],
        'electrician' => ['craft=electrician'],
        'photographer'=> ['craft=photographer', 'shop=photo'],
        'shop'        => ['shop'],   // any shop
        'store'       => ['shop'],
        'office'      => ['office'], // any office
    ];

    /* ─────────────────── public search entrypoints ─────────────────── */

    /** Search by a place NAME (geocode -> its bbox). */
    public function search(string $category, string $place, int $limit = 60, int $scrapeEmails = 6): array
    {
        $geo = $this->geocode($place);
        if (! $geo) {
            return ['ok' => false, 'error' => 'location_not_found', 'leads' => [], 'center' => null];
        }
        [$s, $w, $n, $e] = $geo['bbox'];
        $out = $this->searchBbox($category, $s, $w, $n, $e, $limit, $scrapeEmails);
        $out['center'] = $geo;

        return $out;
    }

    /** Search inside an explicit bounding box (the current MAP VIEW). */
    public function searchBbox(string $category, float $s, float $w, float $n, float $e, int $limit = 60, int $scrapeEmails = 6): array
    {
        $area  = "($s,$w,$n,$e)";
        $query = $this->buildQuery($category, $area, $limit);

        return $this->run($query, $scrapeEmails);
    }

    /** Search within a RADIUS (metres) around a clicked point. */
    public function searchAround(string $category, float $lat, float $lng, int $radiusM = 3000, int $limit = 60, int $scrapeEmails = 6): array
    {
        $radiusM = max(200, min(50000, $radiusM));
        $area    = "(around:$radiusM,$lat,$lng)";
        $query   = $this->buildQuery($category, $area, $limit);
        $out     = $this->run($query, $scrapeEmails);
        $out['center'] = ['lat' => $lat, 'lng' => $lng];

        return $out;
    }

    /* ─────────────────── query builder + runner ─────────────────── */

    /** Overpass QL: union of every category filter within the area clause. */
    private function buildQuery(string $category, string $area, int $limit): string
    {
        $filters = $this->tagFilters($category);
        $parts   = '';
        foreach ($filters as $f) {
            // nwr = node + way + relation, for max coverage.
            $parts .= "nwr{$f}{$area};";
        }

        return "[out:json][timeout:40];({$parts});out center {$limit};";
    }

    /** Category -> Overpass filter strings, or a name-regex fallback. */
    private function tagFilters(string $category): array
    {
        $key = strtolower(trim($category));

        if ($key === '') {
            // No category -> pull ALL businesses in the area (shops + offices +
            // food/health amenities). This powers "Scan this area".
            return ['["shop"]', '["office"]', '["craft"]', '["amenity"~"restaurant|fast_food|cafe|bar|pub|pharmacy|clinic|hospital|dentist|doctors|bank|fuel"]'];
        }

        // Longest-key first so "car repair" beats "car".
        $tags = self::CATEGORY_TAGS;
        uksort($tags, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($tags as $needle => $pairs) {
            if (str_contains($key, $needle)) {
                return array_map(function ($p) {
                    if (str_contains($p, '=')) {
                        [$k, $v] = explode('=', $p, 2);
                        return "[\"$k\"=\"$v\"]";
                    }
                    return "[\"$p\"]";
                }, $pairs);
            }
        }

        // Unknown category -> fuzzy name match on named POIs.
        $safe = preg_replace('/[^a-z0-9 ]/i', '', $category);
        return ["[\"name\"~\"$safe\",i]"];
    }

    /** POST the query (with a mirror fallback) and parse the elements. */
    private function run(string $query, int $scrapeEmails): array
    {
        $elements = null;
        foreach (self::ENDPOINTS as $url) {
            try {
                $res = Http::withHeaders(['User-Agent' => self::UA])
                    ->asForm()->timeout(45)
                    ->post($url, ['data' => $query]);
                if ($res->ok()) {
                    $elements = $res->json()['elements'] ?? [];
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        if ($elements === null) {
            return ['ok' => false, 'error' => 'source_unavailable', 'leads' => [], 'center' => null];
        }

        return ['ok' => true, 'leads' => $this->parse($elements, $scrapeEmails), 'center' => null];
    }

    private function parse(array $elements, int $scrapeEmails): array
    {
        $leads   = [];
        $scraped = 0;
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? ($tags['name:en'] ?? null);
            if (! $name) {
                continue;
            }
            $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $lng = $el['lon'] ?? ($el['center']['lon'] ?? null);

            // WhatsApp tags first (an actual WA number), then phone/mobile.
            $phone   = $tags['contact:whatsapp'] ?? ($tags['whatsapp'] ?? ($tags['contact:phone'] ?? ($tags['phone'] ?? ($tags['contact:mobile'] ?? ($tags['mobile'] ?? null)))));
            $email   = $tags['contact:email'] ?? ($tags['email'] ?? null);
            $website = $tags['contact:website'] ?? ($tags['website'] ?? ($tags['url'] ?? null));

            // Free enrichment: when OSM has no phone/email but the business has a
            // website, scrape it for a phone (tel: link) + email. Capped to stay fast.
            if ((! $phone || ! $email) && $website && $scraped < $scrapeEmails) {
                $c = $this->scrapeContact($website);
                $phone = $phone ?: ($c['phone'] ?? null);
                $email = $email ?: ($c['email'] ?? null);
                $scraped++;
            }

            $leads[] = [
                'source'      => 'osm',
                'external_id' => ($el['type'] ?? 'node') . '/' . ($el['id'] ?? ''),
                'name'        => $name,
                'category'    => $this->categoryLabel($tags),
                'phone'       => $phone,
                'phone_e164'  => $phone ? $this->toE164($phone) : null,
                'email'       => $email,
                'website'     => $website,
                'address'     => $this->addressLine($tags),
                'lat'         => $lat !== null ? (float) $lat : null,
                'lng'         => $lng !== null ? (float) $lng : null,
                'rating'      => null,
            ];
        }

        return $leads;
    }

    /** Public on-demand deep enrichment of one business website (contact page follow). */
    public function enrichWebsite(string $url): array
    {
        return $this->scrapeContact($url, true);
    }

    /* ─────────────────── geocode + helpers ─────────────────── */

    /** Nominatim geocode -> [lat, lng, bbox[s,w,n,e], display]. Cached 24h. */
    public function geocode(string $place): ?array
    {
        $place = trim($place);
        if ($place === '') {
            return null;
        }

        return Cache::remember('leadfinder:geo:' . md5(strtolower($place)), now()->addDay(), function () use ($place) {
            try {
                $res = Http::withHeaders(['User-Agent' => self::UA])
                    ->timeout(15)
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $place, 'format' => 'json', 'limit' => 1, 'addressdetails' => 0,
                    ]);
                $row = $res->json()[0] ?? null;
                if (! $row || ! isset($row['boundingbox'])) {
                    return null;
                }
                $bb = $row['boundingbox']; // [south, north, west, east]
                return [
                    'lat'     => (float) $row['lat'],
                    'lng'     => (float) $row['lon'],
                    'bbox'    => [(float) $bb[0], (float) $bb[2], (float) $bb[1], (float) $bb[3]], // s,w,n,e
                    'display' => $row['display_name'] ?? $place,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Advanced website enrichment. Pulls the most reliable signals for a
     * WhatsApp-first tool, in priority order:
     *   1. wa.me / api.whatsapp.com links   → a CONFIRMED WhatsApp number
     *   2. schema.org JSON-LD "telephone"    → structured, reliable
     *   3. tel: link                          → reliable
     *   4. loose phone regex near text        → fallback
     * plus email (JSON-LD → mailto → regex). When $deep and the homepage yields
     * nothing, it follows one contact/about link and retries.
     */
    private function scrapeContact(string $url, bool $deep = false): array
    {
        $out  = ['phone' => null, 'email' => null, 'whatsapp' => null];
        $base = $this->normalizeUrl($url);
        if (! $base) {
            return $out;
        }

        $html = $this->fetchHtml($base);
        if ($html === null) {
            return $out;
        }
        $out = $this->extractContact($html);

        // Deep: follow a contact/about page if we still lack a phone.
        if ($deep && empty($out['phone']) && preg_match_all('/href=["\']([^"\']*(?:contact|about|reach|connect)[^"\']*)["\']/i', $html, $lm)) {
            foreach (array_slice($lm[1], 0, 2) as $href) {
                $next = $this->absoluteUrl($base, $href);
                if (! $next) {
                    continue;
                }
                $h2 = $this->fetchHtml($next);
                if ($h2 === null) {
                    continue;
                }
                $c2 = $this->extractContact($h2);
                $out['phone']    = $out['phone'] ?: ($c2['phone'] ?? null);
                $out['email']    = $out['email'] ?: ($c2['email'] ?? null);
                $out['whatsapp'] = $out['whatsapp'] ?: ($c2['whatsapp'] ?? null);
                if ($out['phone']) {
                    break;
                }
            }
        }

        return $out;
    }

    /** Pull phone/whatsapp/email signals out of one HTML document. */
    private function extractContact(string $html): array
    {
        $out = ['phone' => null, 'email' => null, 'whatsapp' => null];

        // 1) WhatsApp link → the strongest signal (an actual WA number).
        if (preg_match('#(?:wa\.me/|api\.whatsapp\.com/send\?phone=|whatsapp\.com/send\?phone=|web\.whatsapp\.com/send\?phone=)(\+?\d{6,15})#i', $html, $wm)) {
            $out['whatsapp'] = preg_replace('/\D+/', '', $wm[1]);
            $out['phone']    = $out['whatsapp'];
        }
        // 2) schema.org JSON-LD telephone.
        if (! $out['phone'] && preg_match('/"telephone"\s*:\s*"([^"]{6,25})"/i', $html, $tm)) {
            $out['phone'] = trim($tm[1]);
        }
        // 3) tel: link.
        if (! $out['phone'] && preg_match('/href=["\']tel:([+\d][\d\s\-()]{6,})["\']/i', $html, $pm)) {
            $out['phone'] = trim($pm[1]);
        }
        // 4) loose fallback near contact words.
        if (! $out['phone'] && preg_match('/(?:phone|call|mobile|tel|whatsapp)[^0-9+]{0,12}(\+?\d[\d\s\-()]{8,}\d)/i', strip_tags($html), $pm)) {
            $digits = preg_replace('/\D+/', '', $pm[1]);
            if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                $out['phone'] = trim($pm[1]);
            }
        }

        // Email: JSON-LD → mailto → regex.
        if (preg_match('/"email"\s*:\s*"([^"]+@[^"]+)"/i', $html, $em)) {
            $out['email'] = strtolower(trim($em[1]));
        } elseif (preg_match('/href=["\']mailto:([^"\'?]+@[^"\'?]+)["\']/i', $html, $em)) {
            $out['email'] = strtolower(trim($em[1]));
        } elseif (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $html, $m)) {
            foreach ($m[0] as $addr) {
                $addr = strtolower($addr);
                if (preg_match('/\.(png|jpe?g|gif|svg|webp|css|js)$/i', $addr)) {
                    continue;
                }
                if (str_contains($addr, 'example.') || str_contains($addr, 'sentry.') || str_contains($addr, 'wixpress') || str_contains($addr, 'godaddy')) {
                    continue;
                }
                $out['email'] = $addr;
                break;
            }
        }

        return $out;
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $res = Http::withHeaders(['User-Agent' => self::UA])->timeout(5)->connectTimeout(3)->get($url);
            return $res->ok() ? (string) $res->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function absoluteUrl(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (! $p || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        $root = $p['scheme'] . '://' . $p['host'];
        return $root . '/' . ltrim($href, '/');
    }

    private function toE164(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
    }

    private function categoryLabel(array $tags): ?string
    {
        foreach (['amenity', 'shop', 'office', 'craft', 'tourism', 'leisure'] as $k) {
            if (! empty($tags[$k]) && $tags[$k] !== 'yes') {
                return ucwords(str_replace('_', ' ', $tags[$k]));
            }
        }

        return null;
    }

    private function addressLine(array $tags): ?string
    {
        $parts = array_filter([
            trim(($tags['addr:housenumber'] ?? '') . ' ' . ($tags['addr:street'] ?? '')),
            $tags['addr:city'] ?? null,
            $tags['addr:state'] ?? null,
            $tags['addr:postcode'] ?? null,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }
}
