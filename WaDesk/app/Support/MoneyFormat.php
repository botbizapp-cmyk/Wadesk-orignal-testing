<?php

namespace App\Support;

use App\Models\WaProduct;

/**
 * TWO separate money operations, never conflated (auto-invoice plan §5.4):
 *
 *   - INGEST scaling  — a decimal string from Woo/Shopify ("19.99", "15000",
 *     "1.500") → integer minor units, using the currency's TRUE ISO-4217
 *     minor-unit exponent (0 for JPY/IDR/KRW…, 3 for KWD/BHD/OMR…, else 2).
 *     A blind ×100 is the IDR/JPY 100× bug and the KWD 10×-low bug.
 *
 *   - DISPLAY scaling — minor units → a human string, delegated to the ONE
 *     display authority (WaProduct) so the currency lists live in one place.
 */
class MoneyFormat
{
    /** ISO-4217 zero-decimal currencies (minor unit == major unit). */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'IDR', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF', 'HUF', 'TWD',
    ];

    /** ISO-4217 three-decimal currencies. */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** The currency's ISO minor-unit exponent (0 / 2 / 3). */
    public static function ingestExponent(?string $ccy): int
    {
        $c = strtoupper(trim((string) $ccy));
        if (in_array($c, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($c, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }

    /** Decimal string (or number) → integer minor units for the given currency. */
    public static function toMinor($decimal, ?string $ccy): int
    {
        $exp = self::ingestExponent($ccy);
        $val = (float) preg_replace('/[^0-9.\-]/', '', (string) $decimal);

        return (int) round($val * (10 ** $exp));
    }

    /** Minor units → human string. Delegates to the WaProduct display authority. */
    public static function display(int $minor, ?string $ccy): string
    {
        return WaProduct::formatCurrency($minor, $ccy ?: 'USD');
    }

    /** Bare numeric major-unit string (no symbol), exponent-aware. For PDF columns. */
    public static function majorString(int $minor, ?string $ccy): string
    {
        $exp = self::ingestExponent($ccy);
        if ($exp === 0) {
            return number_format($minor, 0, '.', ',');
        }

        return number_format($minor / (10 ** $exp), $exp, '.', ',');
    }
}
