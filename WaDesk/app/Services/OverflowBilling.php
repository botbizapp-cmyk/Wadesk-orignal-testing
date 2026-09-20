<?php

namespace App\Services;

use App\Exceptions\PlanLimitReachedException;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Per-message billing with plan-quota-first, wallet-credits-after.
 *
 *   1. Under the workspace's monthly_messages_limit → free, no deduction.
 *   2. At or above the cap → spend 1 wallet credit from the workspace
 *      owner's wallet. WalletTransaction logged with source=plan_overflow.
 *   3. Cap reached AND wallet at 0 → throw PlanLimitReachedException with
 *      a "top up to keep sending" message (renders as 422 JSON / back-with-error).
 *
 * Used by InboxDispatcher::send() and WhatsAppDispatcher::send() so
 * every outbound surface in the app gets the same behaviour.
 */
class OverflowBilling
{
    /**
     * @param  int          $used      Messages already sent this calendar month.
     * @param  string|null  $toPhone   Recipient number — enables per-country pricing.
     * @param  string|null  $category  marketing|utility|authentication|service.
     * @throws PlanLimitReachedException
     */
    public static function consumeOne(?Workspace $workspace, int $used, ?string $toPhone = null, ?string $category = null): void
    {
        if (!$workspace) return;

        // DELIVERY-TIME BILLING (default): every message is billed exactly once
        // when the engine confirms delivery, through MessageBillingService (wired
        // into the WABA/Twilio status webhooks + the Baileys delivery callbacks).
        // In that model this legacy SEND-TIME charge is disabled everywhere it is
        // still called (dispatchers, campaign/scheduled/auto-reply loops) so the
        // same message can't be billed twice. Flip wallet_billing_on_delivery=0
        // to fall back to the old send-time behaviour below.
        if ((string) \App\Models\SystemSetting::get('wallet_billing_on_delivery', '1') === '1') {
            return;
        }

        // Admin bypass — same rule as PlanLimitGuard. Admins are never
        // throttled by their own product.
        if (self::bypass()) return;

        // Owner-based admin bypass. The keyword/flow lookup + auto-reply path is
        // a Node-bridge call with NO auth session, so bypass() above (which reads
        // auth()->user()) can't see that an admin OWNS this workspace — and the
        // admin then gets throttled on their own test workspace. Resolve the
        // owner and bypass for admin-owned workspaces in that no-session context.
        try {
            $owner = $workspace->owner;
            if ($owner && (
                (method_exists($owner, 'hasRole') && ($owner->hasRole('Super Admin') || $owner->hasRole('Admin')))
                || in_array($owner->role ?? null, ['admin', 'A'], true)
            )) {
                return;
            }
        } catch (\Throwable $e) { /* never block a send on the bypass lookup */ }

        // PAY-PER-MESSAGE (prepaid wallet / BSP) mode. When ON,
        // the plan's monthly message limit is IGNORED: EVERY message bills the
        // wallet and the wallet balance is the only gate (an expired plan doesn't
        // block sending as long as the wallet has funds). When OFF, the classic
        // plan-quota-first → wallet-overflow behaviour below applies.
        // Reseller workspace (client chose "bill me through us" at connect) is
        // always pay-per-message; others follow the global default.
        $payPerMessage = $workspace->billsToPlatform()
            || (string) \App\Models\SystemSetting::get('bsp_pay_per_message', '0') === '1';

        if (! $payPerMessage) {
            // Plan EXPIRED → sending is PAUSED until the user renews. Wallet
            // credits do NOT extend a lapsed plan — they only ever cover
            // OVER-QUOTA sends while a plan is still ACTIVE.
            if (!$workspace->planIsActive()) {
                throw new PlanLimitReachedException(
                    limitKey: 'plan_expired',
                    used:     $used,
                    limit:    0,
                    message:  'Your plan has ended — renew your plan to keep sending. Wallet credits only cover over-limit sends while a plan is active.',
                );
            }

            // Plan is ACTIVE.
            $limit = $workspace->effectiveLimit('monthly_messages_limit', null);
            // No plan limit configured → unlimited, no credit deduction. 0 (or
            // negative) ALSO means unlimited — the universal "no cap" convention.
            if ($limit === null || (int) $limit <= 0) return;

            // Still inside the plan's free monthly quota — nothing to charge.
            if ($used < (int) $limit) return;
        } else {
            // Pay-per-message: no free quota. $limit is only used for the
            // ledger description below.
            $limit = (int) ($workspace->effectiveLimit('monthly_messages_limit', null) ?? 0);
        }

        // Every billed message spends from the workspace owner's wallet.
        $ownerId = (int) $workspace->owner_user_id;
        if (!$ownerId) {
            throw new PlanLimitReachedException(
                limitKey: 'monthly_messages_limit',
                used:     $used,
                limit:    (int) $limit,
                message:  $payPerMessage
                    ? 'No wallet is attached to this workspace to bill messages. Contact support.'
                    : "You've hit your plan's monthly message cap and there's no wallet to bill. Upgrade your plan.",
            );
        }

        // Per-message price. When per-country pricing is ON and we know the
        // recipient + category, charge that fair rate (a US marketing msg costs
        // ~12× an India one; a free-window service reply can be 0). Otherwise
        // fall back to the flat admin-set `credits_per_message`. Resolver
        // handles the flag + fallbacks — see MessageCreditRate.
        $perMessage = \App\Services\MessageCreditRate::creditsFor($toPhone, $category);

        // 0 credits (e.g. a service-window reply priced free) → nothing to bill.
        // This is intentional and correct, not a misconfig, so we don't floor it.
        if ($perMessage <= 0) return;

        // BSP margin data: what THIS message earns us and what Meta bills us.
        // revenue = credits charged × money-per-credit; cost = Meta wholesale
        // rate for the recipient country×category. Both in platform MINOR units,
        // stamped on the ledger row so the P&L reconciles from real charges.
        $revenueMinor = (int) round($perMessage * \App\Services\MessageCreditRate::minorPerCredit());
        $costMinor    = \App\Services\MessageCreditRate::metaCostMinorFor($toPhone, $category);

        // Atomic deduct — lockForUpdate prevents two parallel sends
        // from racing and over-spending the balance.
        $charged = DB::transaction(function () use ($ownerId, $used, $limit, $perMessage, $toPhone, $category, $revenueMinor, $costMinor) {
            $owner = User::query()->lockForUpdate()->find($ownerId);
            if (!$owner) return false;
            $bal = (int) $owner->wallet_credits;
            if ($bal < $perMessage) return false;

            $newBal = $bal - $perMessage;
            $owner->update(['wallet_credits' => $newBal]);

            WalletTransaction::create([
                'user_id'       => $ownerId,
                'kind'          => WalletTransaction::KIND_CREDIT,
                'type'          => WalletTransaction::TYPE_SPEND,
                'amount'        => $perMessage,
                'balance_after' => $newBal,
                'source'        => 'plan_overflow',
                'description'   => "Over-quota message ({$perMessage} credit/msg"
                    . ($toPhone ? ' · ' . (\App\Support\PhoneCountry::iso($toPhone) ?? '??') : '')
                    . ($category ? ' · ' . $category : '')
                    . " · plan: {$limit}, used: {$used})",
                'meta'          => [
                    'country'          => $toPhone ? (\App\Support\PhoneCountry::iso($toPhone) ?? null) : null,
                    'category'         => $category ?: null,
                    'bsp_revenue_minor' => $revenueMinor,
                    'bsp_cost_minor'    => $costMinor,   // null when no Meta cost is set for this route
                ],
                'created_at'    => now(),
            ]);
            return true;
        });

        if (!$charged) {
            throw new PlanLimitReachedException(
                limitKey: $payPerMessage ? 'wallet_empty' : 'monthly_messages_limit',
                used:     $used,
                limit:    (int) $limit,
                message:  $payPerMessage
                    ? 'Your message wallet is out of balance. Top up to keep sending.'
                    : "You've hit your plan's monthly message cap. Top up your wallet or upgrade to keep sending.",
            );
        }
    }

    private static function bypass(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        try {
            if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return true;
        } catch (\Throwable $e) {}
        return in_array($user->role ?? null, ['admin', 'A'], true);
    }
}
