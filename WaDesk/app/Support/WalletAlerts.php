<?php

namespace App\Support;

use App\Helpers\NotificationHelper;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MessageCreditRate;

/**
 * Wallet alerts — the "you're running low, top up" nudge.
 *
 * MessageBillingService calls lowBalance() right after a spend drops the wallet
 * to/under the admin threshold. It routes through NotificationHelper so it
 * honours the workspace's `wallet_low_balance` notification preference
 * (in-app / email / Slack) — the toggle that was previously advertised but
 * never fired. Balance is shown to the customer as MONEY, not raw credits.
 */
class WalletAlerts
{
    public static function lowBalance(User $owner, int $creditsLeft, int $threshold): void
    {
        $wsId = (int) ($owner->current_workspace_id ?? 0) ?: null;

        $money = self::money($creditsLeft);
        $title = __('Low WhatsApp balance');
        $body  = $money !== null
            ? __('Your message balance is down to :money. Top up to keep your campaigns and replies sending.', ['money' => $money])
            : __('Your message balance is running low (:n credits). Top up to keep sending.', ['n' => number_format($creditsLeft)]);

        NotificationHelper::toUser($owner->id, $title, $body, [
            'event'        => 'wallet_low_balance',
            'category'     => 'billing',
            'severity'     => 'warning',
            'is_urgent'    => true,
            'icon'         => 'billing',
            'action_url'   => '/account?tab=wallet',
            'workspace_id' => $wsId,
        ]);
    }

    /** Format a credit count as the platform money value (e.g. "₹42.00"), or null. */
    private static function money(int $credits): ?string
    {
        $perCredit = MessageCreditRate::minorPerCredit(); // platform minor units per credit
        if ($perCredit <= 0) {
            return null;
        }
        $minor = (int) round($credits * $perCredit);
        try {
            return \App\Support\FormatSettings::display($minor / 100);
        } catch (\Throwable $e) {
            $sym = SystemSetting::get('currency_symbol', '');
            return trim($sym . ' ' . number_format($minor / 100, 2));
        }
    }
}
