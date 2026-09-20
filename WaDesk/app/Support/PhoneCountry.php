<?php

namespace App\Support;

/**
 * Resolve an ISO-3166 alpha-2 country code from a phone number's dialing
 * prefix — no external library. Longest-prefix-wins so 3-digit codes (e.g.
 * 971 UAE, 880 Bangladesh) beat their 1–2 digit neighbours.
 *
 * Used by MessageCreditRate to bill the correct per-country credit rate.
 * Not exhaustive — every code maps to ONE representative ISO (e.g. +1 → US
 * for the whole NANP); billing tiers are country-grouped so that's fine, and
 * any unmatched number falls through to the admin's default rate.
 */
class PhoneCountry
{
    /** Dialing code (without +) → ISO-3166 alpha-2. Order doesn't matter; we sort by length at match time. */
    private const CODES = [
        // 3-digit (checked first via longest-prefix)
        '971' => 'AE', '966' => 'SA', '974' => 'QA', '973' => 'BH', '968' => 'OM',
        '965' => 'KW', '962' => 'JO', '961' => 'LB', '972' => 'IL', '970' => 'PS',
        '963' => 'SY', '964' => 'IQ', '967' => 'YE', '212' => 'MA', '213' => 'DZ',
        '216' => 'TN', '218' => 'LY', '220' => 'GM', '221' => 'SN', '233' => 'GH',
        '234' => 'NG', '254' => 'KE', '255' => 'TZ', '256' => 'UG', '251' => 'ET',
        '260' => 'ZM', '263' => 'ZW', '880' => 'BD', '977' => 'NP', '975' => 'BT',
        '960' => 'MV', '855' => 'KH', '856' => 'LA', '852' => 'HK', '853' => 'MO',
        '886' => 'TW', '351' => 'PT', '353' => 'IE', '352' => 'LU', '358' => 'FI',
        '359' => 'BG', '370' => 'LT', '371' => 'LV', '372' => 'EE', '380' => 'UA',
        '381' => 'RS', '385' => 'HR', '386' => 'SI', '387' => 'BA', '420' => 'CZ',
        '421' => 'SK', '353' => 'IE', '598' => 'UY', '595' => 'PY', '591' => 'BO',
        '593' => 'EC', '592' => 'GY', '507' => 'PA', '506' => 'CR', '502' => 'GT',
        '503' => 'SV', '504' => 'HN', '505' => 'NI', '509' => 'HT',
        // 2-digit
        '20' => 'EG', '27' => 'ZA', '30' => 'GR', '31' => 'NL', '32' => 'BE',
        '33' => 'FR', '34' => 'ES', '36' => 'HU', '39' => 'IT', '40' => 'RO',
        '41' => 'CH', '43' => 'AT', '44' => 'GB', '45' => 'DK', '46' => 'SE',
        '47' => 'NO', '48' => 'PL', '49' => 'DE', '51' => 'PE', '52' => 'MX',
        '53' => 'CU', '54' => 'AR', '55' => 'BR', '56' => 'CL', '57' => 'CO',
        '58' => 'VE', '60' => 'MY', '61' => 'AU', '62' => 'ID', '63' => 'PH',
        '64' => 'NZ', '65' => 'SG', '66' => 'TH', '81' => 'JP', '82' => 'KR',
        '84' => 'VN', '86' => 'CN', '90' => 'TR', '91' => 'IN', '92' => 'PK',
        '93' => 'AF', '94' => 'LK', '95' => 'MM', '98' => 'IR',
        // 1-digit
        '1' => 'US', '7' => 'RU',
    ];

    /**
     * ISO-3166 alpha-2 → display name, for country dropdowns (rate cards etc).
     * Covers the codes we bill against plus a handful of common extras. Not a
     * full 249-country list — the admin picks from here or uses the '*' default.
     */
    public const NAMES = [
        'IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'UAE',
        'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'BH' => 'Bahrain', 'OM' => 'Oman',
        'KW' => 'Kuwait', 'JO' => 'Jordan', 'LB' => 'Lebanon', 'IL' => 'Israel',
        'PS' => 'Palestine', 'SY' => 'Syria', 'IQ' => 'Iraq', 'YE' => 'Yemen',
        'MA' => 'Morocco', 'DZ' => 'Algeria', 'TN' => 'Tunisia', 'LY' => 'Libya',
        'GM' => 'Gambia', 'SN' => 'Senegal', 'GH' => 'Ghana', 'NG' => 'Nigeria',
        'KE' => 'Kenya', 'TZ' => 'Tanzania', 'UG' => 'Uganda', 'ET' => 'Ethiopia',
        'ZM' => 'Zambia', 'ZW' => 'Zimbabwe', 'ZA' => 'South Africa', 'EG' => 'Egypt',
        'BD' => 'Bangladesh', 'NP' => 'Nepal', 'BT' => 'Bhutan', 'MV' => 'Maldives',
        'LK' => 'Sri Lanka', 'PK' => 'Pakistan', 'AF' => 'Afghanistan', 'IR' => 'Iran',
        'KH' => 'Cambodia', 'LA' => 'Laos', 'HK' => 'Hong Kong', 'MO' => 'Macau',
        'TW' => 'Taiwan', 'MY' => 'Malaysia', 'SG' => 'Singapore', 'TH' => 'Thailand',
        'VN' => 'Vietnam', 'ID' => 'Indonesia', 'PH' => 'Philippines', 'CN' => 'China',
        'JP' => 'Japan', 'KR' => 'South Korea', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'PT' => 'Portugal', 'IE' => 'Ireland', 'LU' => 'Luxembourg', 'FI' => 'Finland',
        'BG' => 'Bulgaria', 'LT' => 'Lithuania', 'LV' => 'Latvia', 'EE' => 'Estonia',
        'UA' => 'Ukraine', 'RS' => 'Serbia', 'HR' => 'Croatia', 'SI' => 'Slovenia',
        'BA' => 'Bosnia', 'CZ' => 'Czechia', 'SK' => 'Slovakia', 'GR' => 'Greece',
        'NL' => 'Netherlands', 'BE' => 'Belgium', 'FR' => 'France', 'ES' => 'Spain',
        'HU' => 'Hungary', 'IT' => 'Italy', 'RO' => 'Romania', 'CH' => 'Switzerland',
        'AT' => 'Austria', 'DK' => 'Denmark', 'SE' => 'Sweden', 'NO' => 'Norway',
        'PL' => 'Poland', 'DE' => 'Germany', 'RU' => 'Russia', 'TR' => 'Turkey',
        'MM' => 'Myanmar', 'PE' => 'Peru', 'MX' => 'Mexico', 'CU' => 'Cuba',
        'AR' => 'Argentina', 'BR' => 'Brazil', 'CL' => 'Chile', 'CO' => 'Colombia',
        'VE' => 'Venezuela', 'UY' => 'Uruguay', 'PY' => 'Paraguay', 'BO' => 'Bolivia',
        'EC' => 'Ecuador', 'GY' => 'Guyana', 'PA' => 'Panama', 'CR' => 'Costa Rica',
        'GT' => 'Guatemala', 'SV' => 'El Salvador', 'HN' => 'Honduras', 'NI' => 'Nicaragua',
        'HT' => 'Haiti',
    ];

    /** ISO → name list for dropdowns, sorted by name. */
    public static function names(): array
    {
        $n = self::NAMES;
        asort($n);
        return $n;
    }

    /**
     * Resolve a rate-card cell to an ISO-3166 alpha-2 code. Accepts an ISO-2
     * code ("US"), a country name ("United States"), or a calling code ("1",
     * "+91", "91") — so an imported Meta / WhatsApp-Manager rate card matches
     * whichever column format it ships. Returns null when unrecognised.
     */
    public static function resolve(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') return null;

        // Already an ISO-2 we know.
        $u = strtoupper($v);
        if (preg_match('/^[A-Z]{2}$/', $u) && isset(self::NAMES[$u])) return $u;

        // A calling code (with or without +).
        $digits = preg_replace('/\D+/', '', $v);
        if ($digits !== '' && isset(self::CODES[$digits])) return self::CODES[$digits];

        // A country name (case-insensitive), plus a few common aliases.
        $lower = strtolower($v);
        $aliases = [
            'usa' => 'US', 'u.s.' => 'US', 'u.s.a.' => 'US', 'united states of america' => 'US',
            'uk' => 'GB', 'u.k.' => 'GB', 'britain' => 'GB', 'great britain' => 'GB',
            'uae' => 'AE', 'u.a.e.' => 'AE', 'south korea' => 'KR', 'korea' => 'KR',
            'russia' => 'RU', 'vietnam' => 'VN', 'ivory coast' => 'CI',
        ];
        if (isset($aliases[$lower])) return $aliases[$lower];
        foreach (self::NAMES as $iso => $name) {
            if (strtolower($name) === $lower) return $iso;
        }
        return null;
    }

    /** @return string|null ISO-3166 alpha-2, or null when the prefix isn't recognised. */
    public static function iso(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') return null;

        // Longest dialing code first so 3-digit codes win over their prefixes.
        for ($len = 3; $len >= 1; $len--) {
            $prefix = substr($digits, 0, $len);
            if (isset(self::CODES[$prefix])) {
                return self::CODES[$prefix];
            }
        }
        return null;
    }
}
