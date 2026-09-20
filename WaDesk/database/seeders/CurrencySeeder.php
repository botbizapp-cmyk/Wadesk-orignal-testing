<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Top 30 commonly-used currencies — same starting set as SnapNest.
 * `exchange_rate` is a static seed value (relative to USD); admin
 * can refresh via the Fetch Rates button. USD is the system base.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        // Symbols use \u{...} escapes (double-quoted) so this file stays pure
        // ASCII on disk — a literal '₹'/'€'/'฿' here corrupts to mojibake the
        // moment the file is saved or transferred with the wrong encoding, and
        // updateOrCreate below then WRITES that corruption into the DB. The
        // escapes always produce the correct UTF-8 byte for byte, whatever the
        // file's encoding.
        $rows = [
            ['USD', 'US Dollar',              '$',              2, 1.000000],
            ['EUR', 'Euro',                   "\u{20AC}",       2, 0.920000],
            ['GBP', 'British Pound',          "\u{00A3}",       2, 0.790000],
            ['INR', 'Indian Rupee',           "\u{20B9}",       2, 83.250000],
            ['AED', 'UAE Dirham',             "\u{062F}.\u{0625}", 2, 3.673000],
            ['SAR', 'Saudi Riyal',            "\u{FDFC}",       2, 3.750000],
            ['CAD', 'Canadian Dollar',        'CA$',            2, 1.360000],
            ['AUD', 'Australian Dollar',      'A$',             2, 1.530000],
            ['JPY', 'Japanese Yen',           "\u{00A5}",       0, 149.000000],
            ['CNY', 'Chinese Yuan',           "\u{00A5}",       2, 7.250000],
            ['HKD', 'Hong Kong Dollar',       'HK$',            2, 7.820000],
            ['SGD', 'Singapore Dollar',       'S$',             2, 1.350000],
            ['MYR', 'Malaysian Ringgit',      'RM',             2, 4.700000],
            ['IDR', 'Indonesian Rupiah',      'Rp',             0, 15700.000000],
            ['THB', 'Thai Baht',              "\u{0E3F}",       2, 36.200000],
            ['PHP', 'Philippine Peso',        "\u{20B1}",       2, 56.500000],
            ['VND', 'Vietnamese Dong',        "\u{20AB}",       0, 24500.000000],
            ['KRW', 'South Korean Won',       "\u{20A9}",       0, 1350.000000],
            ['TRY', 'Turkish Lira',           "\u{20BA}",       2, 32.500000],
            ['EGP', 'Egyptian Pound',         "E\u{00A3}",      2, 47.500000],
            ['ZAR', 'South African Rand',     'R',              2, 18.500000],
            ['NGN', 'Nigerian Naira',         "\u{20A6}",       2, 1600.000000],
            ['KES', 'Kenyan Shilling',        'KSh',            2, 129.000000],
            ['BRL', 'Brazilian Real',         'R$',             2, 5.100000],
            ['MXN', 'Mexican Peso',           'MX$',            2, 17.300000],
            ['ARS', 'Argentine Peso',         'AR$',            2, 950.000000],
            ['CLP', 'Chilean Peso',           'CLP$',           0, 920.000000],
            ['COP', 'Colombian Peso',         'CO$',            0, 4000.000000],
            ['PKR', 'Pakistani Rupee',        "\u{20A8}",       2, 278.000000],
            ['BDT', 'Bangladeshi Taka',       "\u{09F3}",       2, 110.000000],
        ];

        foreach ($rows as [$code, $name, $symbol, $precision, $rate]) {
            Currency::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'symbol' => $symbol, 'precision' => $precision, 'exchange_rate' => $rate, 'is_active' => true],
            );
        }
    }
}
