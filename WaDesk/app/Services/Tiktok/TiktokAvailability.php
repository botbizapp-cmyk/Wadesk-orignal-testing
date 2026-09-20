<?php

namespace App\Services\Tiktok;

/**
 * TikTok feature availability by region — the single source of truth for "where
 * is this allowed". TikTok gates its partner surfaces geographically and no
 * vendor can bypass it, so the channel must region-fence BEFORE offering a
 * feature rather than letting an API call fail.
 *
 * Verified against TikTok docs + the Business Messaging education hub (2026):
 *  - Business Messaging (organic DM inbox) is UNAVAILABLE in: US, EEA, Switzerland, UK.
 *    Available in most other markets (incl. SEA: VN, TH, ID, MY, PH, SG).
 *  - Comment REPLY is supported (Business Account API); comment-as-a-TRIGGER
 *    ("comment-to-DM") is NOT offered by TikTok's API — surfaced honestly here.
 *  - Connect + Insights + Posting (the MVP) work everywhere (no region gate).
 *
 * ISO-3166 alpha-2 country codes. The connected account's registration country
 * decides availability; when unknown we FAIL OPEN for the always-on MVP features
 * and FAIL CLOSED (blocked) for the partner-gated DM lane, and say so in the UI.
 */
class TiktokAvailability
{
    /** EEA member states + the three extra markets TikTok blocks for messaging. */
    private const EEA = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
    ];

    /** Business Messaging (DM inbox) is blocked in these markets. */
    public static function messagingBlockedCountries(): array
    {
        return array_merge(self::EEA, ['CH', 'GB', 'US']);
    }

    /** Is the organic DM inbox (Business Messaging API) available for this country? */
    public static function messagingAvailable(?string $country): bool
    {
        $c = strtoupper(trim((string) $country));
        if ($c === '') {
            return false; // unknown → fail closed for the partner-gated lane
        }

        return ! in_array($c, self::messagingBlockedCountries(), true);
    }

    /**
     * Comment automation reality: TikTok exposes a comment-reply endpoint but no
     * comment-trigger. So we can moderate/reply, but "comment keyword → auto-DM"
     * cannot be offered. This is a capability flag, not a region gate.
     */
    public static function commentTriggerSupported(): bool
    {
        return false;
    }

    /** MVP features (connect, insights, posting) are available everywhere. */
    public static function coreAvailable(?string $country = null): bool
    {
        return true;
    }

    /**
     * Human-readable reason a market is blocked for the DM inbox, or null when
     * it's available — drives the UI banner.
     */
    public static function messagingBlockReason(?string $country): ?string
    {
        $c = strtoupper(trim((string) $country));
        if ($c === '') {
            return 'We could not determine this account\'s region. TikTok\'s Business Messaging API only enables the DM inbox in supported markets.';
        }
        if (in_array($c, self::EEA, true) || in_array($c, ['CH', 'GB'], true)) {
            return 'TikTok does not offer its Business Messaging API in the EEA, Switzerland or the UK, so the DM inbox cannot be enabled there.';
        }
        if ($c === 'US') {
            return 'TikTok\'s Business Messaging API (organic DM inbox) is not available in the United States.';
        }

        return null; // available
    }
}
