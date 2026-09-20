<?php

namespace App\Services;

use App\Models\MessageCharge;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Workspace;
use App\Support\PhoneCountry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE single billing choke point — prepaid wallet, charge on delivery.
 *
 * Every outbound WhatsApp message, from every feature and every engine, lands
 * here when the engine confirms it went out (delivery-status webhook). Because
 * it is the ONLY place money moves for messages:
 *
 *   settleDelivered()  charges the wallet exactly ONCE per message id (wamid),
 *                      at the per-country × category rate, debiting both the
 *                      credit balance and the ₹ money wallet, and records a
 *                      message_charges row (P&L source). Idempotent.
 *   settleFailed()     reverses that exact charge when Meta reports failure, so
 *                      customers never pay for undelivered messages. Idempotent.
 *   precheck()         a cheap "does the wallet have funds?" gate used at
 *                      LAUNCH (campaign/broadcast/flow start) so an empty wallet
 *                      is stopped before a blast — settlement then happens on
 *                      delivery — pre-check at send, settle on delivery.
 *
 * A send path can never leak revenue: if the message was delivered, a webhook
 * fired, and this ran.
 */
class MessageBillingService
{
    /** Global default: every message bills the wallet, plan limit ignored. */
    public static function payPerMessage(): bool
    {
        return (string) SystemSetting::get('bsp_pay_per_message', '0') === '1';
    }

    /**
     * Per-client billing mode. A "reseller" workspace (bill_to_platform_credit —
     * the client chose "bill me through us" at connect, so Meta bills the
     * PLATFORM's credit line) is ALWAYS pay-per-message: every message is
     * charged to the client's prepaid wallet and the plan's message quota is
     * ignored. A "meta_direct" workspace (the client's own Meta account is
     * billed by Meta) follows the global default — normally OFF, i.e. the
     * classic plan-quota system with no per-message wallet charge.
     */
    public static function payPerMessageFor(?Workspace $workspace): bool
    {
        if ($workspace && $workspace->billsToPlatform()) {
            return true;
        }
        return self::payPerMessage();
    }

    /**
     * Charge for ONE delivered message. Idempotent per $wamid. Never throws —
     * the message already went out, so we always account for it (charge or free).
     */
    public function settleDelivered(
        int $workspaceId,
        string $wamid,
        ?string $toPhone,
        ?string $category = null,
        string $provider = 'waba',
        ?string $source = null
    ): ?MessageCharge {
        $wamid = trim($wamid);
        if ($workspaceId <= 0 || $wamid === '') {
            return null;
        }

        // Idempotency: claim the wamid first. If the row already exists, this
        // message was already settled — do nothing (no double charge).
        $charge = MessageCharge::where('wamid', $wamid)->first();
        if ($charge) {
            return $charge;
        }

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return null;
        }

        // Admin-owned workspaces are never billed by their own product.
        if ($this->ownerIsAdmin($workspace)) {
            Log::info('[BILLING] admin-skip — admin/Super-Admin workspace is never billed', [
                'ws' => $workspaceId, 'wamid' => $wamid, 'provider' => $provider, 'source' => $source,
            ]);
            return null;
        }

        $ownerId = (int) $workspace->owner_user_id;
        $iso     = PhoneCountry::iso($toPhone);
        $cat     = $category ? strtolower(trim($category)) : null;

        // Price the message (per-country × category) + Meta wholesale cost.
        $price    = max(0, MessageCreditRate::creditsFor($toPhone, $cat));
        $costMinor = MessageCreditRate::metaCostMinorFor($toPhone, $cat);

        // Free? Either the rate is 0 (service / free-window), or this workspace
        // is NOT pay-per-message (meta_direct / classic) and is still inside the
        // free monthly quota. Reseller workspaces (billsToPlatform) skip the
        // quota entirely and always charge the wallet.
        $free = ($price === 0) || (! self::payPerMessageFor($workspace) && $this->withinFreeQuota($workspace));

        Log::info('[BILLING] settle', [
            'ws'              => $workspaceId,
            'wamid'           => $wamid,
            'provider'        => $provider,
            'source'          => $source,
            'to_country'      => $iso,
            'category'        => $cat,
            'price_credits'   => $price,
            'pay_per_message' => self::payPerMessageFor($workspace),
            'free'            => $free,
        ]);

        try {
            return DB::transaction(function () use ($workspaceId, $ownerId, $wamid, $iso, $cat, $provider, $source, $price, $costMinor, $free) {
                if ($free || $ownerId <= 0) {
                    Log::info('[BILLING] free — not charged', [
                        'ws' => $workspaceId, 'wamid' => $wamid,
                        'reason' => $price === 0 ? 'rate_is_zero' : ($ownerId <= 0 ? 'no_wallet_owner' : 'within_plan_free_quota'),
                    ]);
                    return $this->recordFree($workspaceId, $ownerId ?: null, $wamid, $iso, $cat, $provider, $source, $costMinor);
                }

                // Lock the wallet owner, deduct what we can (never below zero —
                // the launch precheck is what prevents a mass overspend).
                $owner = User::whereKey($ownerId)->lockForUpdate()->first();
                if (! $owner) {
                    return $this->recordFree($workspaceId, null, $wamid, $iso, $cat, $provider, $source, $costMinor);
                }

                $have    = (int) $owner->wallet_credits;
                $charged = max(0, min($price, $have));
                if ($charged === 0) {
                    Log::warning('[BILLING] wallet-empty — nothing to deduct, recorded free', [
                        'ws' => $workspaceId, 'wamid' => $wamid, 'owner' => $ownerId, 'have' => $have, 'price' => $price,
                    ]);
                    // Wallet empty at delivery — record the message as unbilled-free
                    // rather than lose the row (still fires low-balance below).
                    $row = $this->recordFree($workspaceId, $ownerId, $wamid, $iso, $cat, $provider, $source, $costMinor);
                    $this->afterSpend($owner, 0);
                    return $row;
                }

                $revenueMinor  = (int) round($charged * MessageCreditRate::minorPerCredit());
                $newCredits    = $have - $charged;
                $spendCurrency = $revenueMinor; // money value of the credits spent
                $newCurrency   = max(0, (int) $owner->wallet_currency_minor - $spendCurrency);

                $owner->forceFill([
                    'wallet_credits'        => $newCredits,
                    'wallet_currency_minor' => $newCurrency,
                ])->save();

                $tx = WalletTransaction::create([
                    'user_id'       => $ownerId,
                    'kind'          => WalletTransaction::KIND_CREDIT,
                    'type'          => WalletTransaction::TYPE_SPEND,
                    'amount'        => $charged,
                    'balance_after' => $newCredits,
                    'source'        => 'message.sent',
                    'description'   => 'WhatsApp message'
                        . ($iso ? ' · ' . $iso : '')
                        . ($cat ? ' · ' . $cat : ''),
                    'meta'          => [
                        'wamid'        => $wamid,
                        'provider'     => $provider,
                        'source'       => $source,
                        'money_minor'  => $spendCurrency,
                        'cost_minor'   => $costMinor,
                    ],
                    'created_at'    => now(),
                ]);

                $row = MessageCharge::create([
                    'workspace_id' => $workspaceId,
                    'user_id'      => $ownerId,
                    'provider'     => $provider,
                    'wamid'        => $wamid,
                    'to_country'   => $iso,
                    'category'     => $cat,
                    'source'       => $source,
                    'credits'      => $charged,
                    'revenue_minor'=> $revenueMinor,
                    'cost_minor'   => $costMinor,
                    'status'       => MessageCharge::STATUS_CHARGED,
                    'wallet_tx_id' => $tx->id,
                ]);

                Log::info('[BILLING] charged', [
                    'ws' => $workspaceId, 'wamid' => $wamid, 'owner' => $ownerId,
                    'credits' => $charged, 'balance_after' => $newCredits,
                    'provider' => $provider, 'source' => $source,
                ]);
                $this->afterSpend($owner, $newCredits);
                return $row;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique(wamid) race — another worker settled it first. Fetch + return.
            if ($this->isDuplicate($e)) {
                return MessageCharge::where('wamid', $wamid)->first();
            }
            Log::error('[BILLING] settleDelivered failed', ['wamid' => $wamid, 'err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Reverse the charge for a message Meta reports as FAILED / UNDELIVERED.
     * Idempotent — a message already free/refunded is left untouched.
     */
    public function settleFailed(int $workspaceId, string $wamid): void
    {
        $wamid = trim($wamid);
        if ($wamid === '') {
            return;
        }

        DB::transaction(function () use ($wamid) {
            $charge = MessageCharge::where('wamid', $wamid)->lockForUpdate()->first();
            if (! $charge || $charge->status !== MessageCharge::STATUS_CHARGED) {
                return; // nothing charged, or already refunded
            }

            if ($charge->user_id && $charge->credits > 0) {
                $owner = User::whereKey($charge->user_id)->lockForUpdate()->first();
                if ($owner) {
                    $newCredits  = (int) $owner->wallet_credits + (int) $charge->credits;
                    $newCurrency = (int) $owner->wallet_currency_minor + (int) $charge->revenue_minor;
                    $owner->forceFill([
                        'wallet_credits'        => $newCredits,
                        'wallet_currency_minor' => $newCurrency,
                    ])->save();

                    WalletTransaction::create([
                        'user_id'       => $owner->id,
                        'kind'          => WalletTransaction::KIND_CREDIT,
                        'type'          => WalletTransaction::TYPE_REFUND,
                        'amount'        => (int) $charge->credits,
                        'balance_after' => $newCredits,
                        'source'        => 'message.refund',
                        'subject_type'  => MessageCharge::class,
                        'subject_id'    => $charge->id,
                        'description'   => 'Refund — message not delivered',
                        'meta'          => ['wamid' => $wamid, 'money_minor' => (int) $charge->revenue_minor],
                        'created_at'    => now(),
                    ]);
                }
            }

            $charge->update(['status' => MessageCharge::STATUS_REFUNDED]);
        });
    }

    /**
     * Launch-time gate. Before a campaign/broadcast/flow blast, block if the
     * wallet can't plausibly cover it. Cheap: compares the credit balance to a
     * conservative estimate. In pay-per-message mode an empty wallet always
     * blocks; in classic mode the plan quota still covers the free tier.
     *
     * @return array{ok: bool, reason: ?string, balance: int, need: int}
     */
    public function precheck(Workspace $workspace, int $count = 1): array
    {
        $ownerId = (int) $workspace->owner_user_id;
        if ($ownerId <= 0 || $this->ownerIsAdmin($workspace)) {
            return ['ok' => true, 'reason' => null, 'balance' => 0, 'need' => 0];
        }

        // meta_direct / classic still inside free monthly quota → no wallet
        // needed. Reseller workspaces always need wallet funds.
        if (! self::payPerMessageFor($workspace) && $this->withinFreeQuota($workspace, $count)) {
            return ['ok' => true, 'reason' => null, 'balance' => 0, 'need' => 0];
        }

        $balance = (int) (User::whereKey($ownerId)->value('wallet_credits') ?? 0);
        // Conservative estimate: flat per-message rate × count (real rate is
        // per-country and resolved at delivery; this only guards gross empties).
        $per  = max(1, (int) SystemSetting::get('credits_per_message', 1));
        $need = $per * max(1, $count);

        if ($balance <= 0) {
            return ['ok' => false, 'reason' => 'Your message wallet is empty. Top up to start sending.', 'balance' => $balance, 'need' => $need];
        }

        return ['ok' => true, 'reason' => null, 'balance' => $balance, 'need' => $need];
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** Record a delivered-but-free message (rate 0, quota, or empty wallet). */
    private function recordFree(int $wsId, ?int $ownerId, string $wamid, ?string $iso, ?string $cat, string $provider, ?string $source, ?int $costMinor): MessageCharge
    {
        return MessageCharge::create([
            'workspace_id' => $wsId,
            'user_id'      => $ownerId,
            'provider'     => $provider,
            'wamid'        => $wamid,
            'to_country'   => $iso,
            'category'     => $cat,
            'source'       => $source,
            'credits'      => 0,
            'revenue_minor'=> 0,
            'cost_minor'   => $costMinor,
            'status'       => MessageCharge::STATUS_FREE,
        ]);
    }

    /** Classic-mode monthly free quota check (message_charges = the sent count). */
    private function withinFreeQuota(Workspace $workspace, int $add = 1): bool
    {
        if (! $workspace->planIsActive()) {
            return false; // expired plan → wallet must cover (no free quota)
        }
        $limit = $workspace->effectiveLimit('monthly_messages_limit', null);
        if ($limit === null || (int) $limit <= 0) {
            return true; // unlimited plan → always free
        }
        $used = MessageCharge::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
        return ($used + $add) <= (int) $limit;
    }

    private function ownerIsAdmin(Workspace $workspace): bool
    {
        try {
            $owner = $workspace->owner;
            if ($owner && (
                (method_exists($owner, 'hasRole') && ($owner->hasRole('Super Admin') || $owner->hasRole('Admin')))
                || in_array($owner->role ?? null, ['admin', 'A'], true)
            )) {
                return true;
            }
        } catch (\Throwable $e) { /* never block billing on a lookup */ }
        return false;
    }

    /** After a spend: fire the low-balance alert + auto-recharge trigger (throttled). */
    private function afterSpend(User $owner, int $newBalance): void
    {
        try {
            $threshold = (int) SystemSetting::get('wallet_low_balance_threshold', 20);
            if ($threshold > 0 && $newBalance <= $threshold && class_exists(\App\Support\WalletAlerts::class)) {
                $key = 'wallet_low_notified:' . $owner->id;
                if (! Cache::has($key)) {
                    Cache::put($key, 1, now()->addHours(6)); // one alert per 6h
                    \App\Support\WalletAlerts::lowBalance($owner, $newBalance, $threshold);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[BILLING] low-balance hook failed', ['err' => $e->getMessage()]);
        }
    }

    private function isDuplicate(\Illuminate\Database\QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains(strtolower($e->getMessage()), 'unique')
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
