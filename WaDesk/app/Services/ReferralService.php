<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Referral attribution + payout. Called from AuthController on
 * register, and only there — there's no admin "re-attribute" path
 * (the unique constraint on `referrals.referred_user_id` enforces
 * one-and-done).
 *
 * Payout amount is read from `system_settings.referral_signup_credits`,
 * which the admin tunes from /admin/settings. Defaults to 100.
 */
class ReferralService
{
    public function __construct(private WalletService $wallet) {}

    /**
     * Look up the referrer user from a code captured at signup-time.
     * Self-referrals (code belongs to the same user) are ignored —
     * defensive against a clever user pasting their own link into
     * incognito.
     */
    public function findReferrer(?string $code, ?int $excludeUserId = null): ?User
    {
        $code = trim((string) $code);
        if ($code === '') return null;
        $code = strtoupper($code);
        return User::query()
            ->where('referral_code', $code)
            ->when($excludeUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->first();
    }

    /**
     * Attribute a referee to a referrer + award the configured
     * signup credits in one transaction. Idempotent — a second call
     * for the same referee no-ops thanks to the unique constraint
     * on `referrals.referred_user_id`.
     *
     * Returns the Referral row (existing or new).
     */
    public function attribute(User $referrer, User $referee, string $codeUsed): ?Referral
    {
        if ($referrer->id === $referee->id) return null;

        $existing = Referral::where('referred_user_id', $referee->id)->first();
        if ($existing) return $existing;

        return DB::transaction(function () use ($referrer, $referee, $codeUsed) {
            // Persist the referee → referrer link on `users` first so any
            // subsequent reads (admin views, audit) reflect the graph.
            $referee->forceFill(['referred_by_user_id' => $referrer->id])->save();

            // ATTRIBUTION ONLY — no payout at signup. The bonus is paid on the
            // referee's FIRST PAID subscription (see rewardOnFirstPayment), so a
            // user can't farm free money by mass-creating throwaway accounts.
            // award_transaction_id = NULL marks the referral as "not yet paid".
            return Referral::create([
                'referrer_user_id'     => $referrer->id,
                'referred_user_id'     => $referee->id,
                'code_used'            => $codeUsed,
                'credits_awarded'      => 0,
                'award_transaction_id' => null,
                'created_at'           => now(),
            ]);
        });
    }

    /**
     * Pay the referrer their bonus when the referee makes their FIRST paid
     * purchase. Idempotent + safe to call on every successful checkout: it only
     * credits when a referral exists for this referee AND hasn't been paid out
     * yet (award_transaction_id IS NULL), then stamps the transaction id so a
     * second call (a renewal, a second order) never double-pays.
     */
    public function rewardOnFirstPayment(User $referee): void
    {
        $referral = Referral::where('referred_user_id', $referee->id)
            ->whereNull('award_transaction_id')
            ->first();
        if (! $referral) return;

        $referrer = User::find($referral->referrer_user_id);
        if (! $referrer || $referrer->id === $referee->id) return;

        $payout = max(0, (int) SystemSetting::get('referral_signup_credits', 100));
        if ($payout <= 0) return;

        DB::transaction(function () use ($referrer, $referee, $referral, $payout) {
            $awardTx = $this->wallet->creditAccount(
                $referrer,
                $payout,
                'referral.paid',
                $referee,
                "Referral bonus — {$referee->email} started a paid plan with your code",
                ['referee_id' => $referee->id, 'code_used' => $referral->code_used]
            );
            $referral->forceFill([
                'credits_awarded'      => $payout,
                'award_transaction_id' => $awardTx?->id,
            ])->save();
        });
    }
}
