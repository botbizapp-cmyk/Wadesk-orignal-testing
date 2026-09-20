<?php

namespace App\Services;

use App\Models\MessageRate;
use App\Models\SystemSetting;
use App\Support\PhoneCountry;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves how many wallet credits a single outbound message costs, based on
 * the recipient's COUNTRY and the message CATEGORY (marketing / utility /
 * authentication / service) — the fair model that mirrors Meta's own per-
 * country pricing. Falls back to the flat `credits_per_message` setting when
 * the `per_country_credits_enabled` flag is OFF or no rate row matches, so
 * existing installs are unaffected until the admin opts in.
 */
class MessageCreditRate
{
    /** Master switch. OFF → everyone pays the single flat rate (legacy behaviour). */
    public static function enabled(): bool
    {
        return (string) SystemSetting::get('per_country_credits_enabled', '0') === '1';
    }

    /** The flat per-message credit price (admin: /admin/settings/wallet-rules). Floored at 0. */
    public static function flat(): int
    {
        return max(0, (int) SystemSetting::get('credits_per_message', 1));
    }

    /**
     * Credits to charge for one message to $phone in $category.
     *
     * @param  string|null  $phone     Recipient number (any format; digits extracted).
     * @param  string|null  $category  marketing|utility|authentication|service (others → flat).
     */
    public static function creditsFor(?string $phone, ?string $category = null): int
    {
        if (!self::enabled()) {
            return self::flat();
        }

        $iso = PhoneCountry::iso($phone) ?? '';
        $cat = self::normalizeCategory($category);
        $rates = self::table();   // [ "IN|marketing" => 3, "|service" => 0, ... ]

        // Most-specific match wins: country+category → country-any → any+category → any-any.
        foreach (["{$iso}|{$cat}", "{$iso}|", "|{$cat}", "|"] as $key) {
            if (array_key_exists($key, $rates)) {
                return max(0, (int) $rates[$key]);
            }
        }

        // No row at all → the flat setting is the final safety net.
        return self::flat();
    }

    /**
     * Meta's WHOLESALE cost (platform-currency MINOR units) for one message to
     * $phone in $category — the number the BSP margin/P&L is measured against.
     * Same most-specific-wins resolution as creditsFor(). NULL when no row
     * carries a cost (so P&L can tell "unknown" from "free").
     */
    public static function metaCostMinorFor(?string $phone, ?string $category = null): ?int
    {
        $iso  = PhoneCountry::iso($phone) ?? '';
        $cat  = self::normalizeCategory($category);
        $costs = self::costTable();

        foreach (["{$iso}|{$cat}", "{$iso}|", "|{$cat}", "|"] as $key) {
            if (array_key_exists($key, $costs) && $costs[$key] !== null) {
                return (int) $costs[$key];
            }
        }
        return null;
    }

    /** Money (platform MINOR units) that ONE credit is worth, from the top-up rate. */
    public static function minorPerCredit(): float
    {
        // credits_per_currency_minor = credits bought per 1 MAJOR unit (₹1),
        // so 1 credit = (1/rate) major = (100/rate) minor units (paise).
        $rate = (float) SystemSetting::get('credits_per_currency_minor', 0.1);
        return $rate > 0 ? (100 / $rate) : 0.0;
    }

    /**
     * Per-message cost of ONE category for UI display — the any-country base rate
     * (the real charge varies by the recipient's country). Money is platform MINOR
     * units. `free` when the rate is 0 (e.g. service). So users can see up front
     * "how much each template costs".
     *
     * @return array{category:string, credits:int, minor:int, free:bool}
     */
    public static function displayForCategory(?string $category): array
    {
        $cat = self::normalizeCategory($category);
        $credits = self::creditsFor(null, $cat ?: 'utility'); // '' → any-category default
        return [
            'category' => $cat ?: 'utility',
            'credits'  => $credits,
            'minor'    => (int) round($credits * self::minorPerCredit()),
            'free'     => $credits <= 0,
        ];
    }

    /**
     * The 4 template categories with their per-message display cost — for a
     * pricing reference panel. @return array<string,array{credits:int,minor:int,free:bool}>
     */
    public static function displayTable(): array
    {
        $out = [];
        foreach (MessageRate::CATEGORIES as $cat) {
            $out[$cat] = self::displayForCategory($cat);
        }
        return $out;
    }

    /** "COUNTRY|category" => meta_cost_minor (nullable), cached 5 min. */
    private static function costTable(): array
    {
        return Cache::remember('message_rates_cost_map', 300, function () {
            return MessageRate::query()
                ->where('is_active', true)
                ->get(['country_code', 'category', 'meta_cost_minor'])
                ->mapWithKeys(fn ($r) => [strtoupper($r->country_code) . '|' . strtolower($r->category) => $r->meta_cost_minor])
                ->all();
        });
    }

    /** marketing|utility|authentication|service; anything else → '' (so it hits the any-category default). */
    private static function normalizeCategory(?string $category): string
    {
        $c = strtolower(trim((string) $category));
        return in_array($c, MessageRate::CATEGORIES, true) ? $c : '';
    }

    /** Active rate rows keyed "COUNTRY|category", cached 5 min (tiny table, rarely changes). */
    private static function table(): array
    {
        return Cache::remember('message_rates_map', 300, function () {
            return MessageRate::query()
                ->where('is_active', true)
                ->get(['country_code', 'category', 'credits'])
                ->mapWithKeys(fn ($r) => [strtoupper($r->country_code) . '|' . strtolower($r->category) => (int) $r->credits])
                ->all();
        });
    }

    /** Drop the cache after an admin edit. */
    public static function forget(): void
    {
        Cache::forget('message_rates_map');
        Cache::forget('message_rates_cost_map');
    }
}
