<?php

namespace App\Services\LeadFinder;

use Illuminate\Support\Facades\Http;

/**
 * Google Places lead source — activated when the workspace has pasted its own
 * Google Maps / Places API key (BYOK). Far richer than OSM: real business
 * phone numbers, websites, ratings and near-complete coverage.
 *
 * Uses the classic Places web-service endpoints (Text Search / Nearby Search +
 * Place Details for phone/website). The workspace's key + billing govern usage.
 */
class GooglePlacesLeadSource
{
    private const BASE = 'https://maps.googleapis.com/maps/api/place';

    public function __construct(private string $apiKey)
    {
    }

    /** Text search: "category in place". */
    public function search(string $category, string $place, int $limit = 60, int $detailsCap = 24): array
    {
        $q = trim($category . ' in ' . $place);

        return $this->collect(
            fn () => $this->textSearch($q),
            $limit,
            $detailsCap,
        );
    }

    /** Bbox → nearby search around its centre with a radius covering it. */
    public function searchBbox(string $category, float $s, float $w, float $n, float $e, int $limit = 60, int $detailsCap = 24): array
    {
        $lat = ($s + $n) / 2;
        $lng = ($w + $e) / 2;
        // Rough radius (m) = half the box diagonal, clamped to Google's 50km max.
        $radius = (int) min(50000, max(500, $this->haversine($s, $w, $n, $e) / 2));

        return $this->searchAround($category, $lat, $lng, $radius, $limit, $detailsCap);
    }

    /** Nearby search in a radius around a point. */
    public function searchAround(string $category, float $lat, float $lng, int $radius = 3000, int $limit = 60, int $detailsCap = 24): array
    {
        $radius = max(200, min(50000, $radius));

        return $this->collect(
            fn () => $this->nearby($category, $lat, $lng, $radius),
            $limit,
            $detailsCap,
        );
    }

    /* ---- internals ---- */

    private function collect(callable $fetch, int $limit, int $detailsCap): array
    {
        try {
            $results = $fetch();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'source_unavailable', 'leads' => []];
        }
        if ($results === null) {
            return ['ok' => false, 'error' => 'google_error', 'leads' => []];
        }

        $leads   = [];
        $details = 0;
        foreach (array_slice($results, 0, $limit) as $p) {
            $phone = null;
            $website = null;
            // A details call gets phone + website (cost per call — capped).
            if ($details < $detailsCap && ! empty($p['place_id'])) {
                $d = $this->details($p['place_id']);
                $phone   = $d['phone'] ?? null;
                $website = $d['website'] ?? null;
                $details++;
            }

            $leads[] = [
                'source'      => 'google',
                'external_id' => (string) ($p['place_id'] ?? ($p['reference'] ?? '')),
                'name'        => $p['name'] ?? null,
                'category'    => isset($p['types'][0]) ? ucwords(str_replace('_', ' ', $p['types'][0])) : null,
                'phone'       => $phone,
                'phone_e164'  => $phone ? preg_replace('/\D+/', '', $phone) : null,
                'email'       => null, // Google Places doesn't expose email.
                'website'     => $website,
                'address'     => $p['formatted_address'] ?? ($p['vicinity'] ?? null),
                'lat'         => $p['geometry']['location']['lat'] ?? null,
                'lng'         => $p['geometry']['location']['lng'] ?? null,
                'rating'      => $p['rating'] ?? null,
            ];
        }

        return ['ok' => true, 'leads' => $leads];
    }

    private function textSearch(string $query): ?array
    {
        $res = Http::timeout(20)->get(self::BASE . '/textsearch/json', [
            'query' => $query, 'key' => $this->apiKey,
        ]);
        $j = $res->json();
        if (($j['status'] ?? '') !== 'OK' && ($j['status'] ?? '') !== 'ZERO_RESULTS') {
            return null;
        }

        return $j['results'] ?? [];
    }

    private function nearby(string $category, float $lat, float $lng, int $radius): ?array
    {
        $params = ['location' => "$lat,$lng", 'radius' => $radius, 'key' => $this->apiKey];
        if (trim($category) !== '') {
            $params['keyword'] = $category;
        }
        $res = Http::timeout(20)->get(self::BASE . '/nearbysearch/json', $params);
        $j = $res->json();
        if (($j['status'] ?? '') !== 'OK' && ($j['status'] ?? '') !== 'ZERO_RESULTS') {
            return null;
        }

        return $j['results'] ?? [];
    }

    private function details(string $placeId): array
    {
        try {
            $res = Http::timeout(12)->get(self::BASE . '/details/json', [
                'place_id' => $placeId,
                'fields'   => 'formatted_phone_number,international_phone_number,website',
                'key'      => $this->apiKey,
            ]);
            $r = $res->json()['result'] ?? [];

            return [
                'phone'   => $r['international_phone_number'] ?? ($r['formatted_phone_number'] ?? null),
                'website' => $r['website'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Quick validity probe for the settings screen. */
    public function validate(): bool
    {
        try {
            $res = Http::timeout(12)->get(self::BASE . '/textsearch/json', [
                'query' => 'restaurant', 'key' => $this->apiKey,
            ]);
            $status = $res->json()['status'] ?? 'ERR';

            return in_array($status, ['OK', 'ZERO_RESULTS'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
