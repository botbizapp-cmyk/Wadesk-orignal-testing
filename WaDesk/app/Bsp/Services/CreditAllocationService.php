<?php

namespace App\Bsp\Services;

use App\Bsp\Models\BspCreditAllocation;
use App\Bsp\Services\CreditSource\CreditSourceContract;
use App\Bsp\Services\CreditSource\MetaSolutionPartnerCreditSource;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates credit-line attach/revoke for customer WABAs and records the
 * result in bsp_credit_allocations. Delegates the actual Meta/aggregator call
 * to the configured CreditSource adapter (Path A = Meta Solution Partner).
 */
class CreditAllocationService
{
    /** Resolve the configured credit source. Path A only for now. */
    public function source(): CreditSourceContract
    {
        return match ((string) SystemSetting::get('bsp_credit_source', 'meta')) {
            default => new MetaSolutionPartnerCreditSource(),
        };
    }

    /**
     * Attach our credit line to a workspace's WABA. Idempotent per (workspace,
     * waba): re-attaching an already-attached WABA returns the existing row.
     */
    public function attach(int $workspaceId, string $wabaId, string $currency, ?int $by = null): BspCreditAllocation
    {
        $wabaId   = trim($wabaId);
        $currency = strtoupper(trim($currency)) ?: strtoupper((string) SystemSetting::get('bsp_base_currency', 'USD'));

        $existing = BspCreditAllocation::where('workspace_id', $workspaceId)
            ->where('waba_id', $wabaId)->where('status', 'attached')->first();
        if ($existing) {
            return $existing;
        }

        $source = $this->source();
        $res    = $source->attach($wabaId, $currency);

        $alloc = BspCreditAllocation::updateOrCreate(
            ['workspace_id' => $workspaceId, 'waba_id' => $wabaId],
            [
                'allocation_config_id' => $res['allocation_config_id'] ?? null,
                'credit_source'        => $source->name(),
                'currency'             => $currency,
                'status'               => ($res['ok'] ?? false) ? 'attached' : 'failed',
                'last_error'           => ($res['ok'] ?? false) ? null : ($res['error'] ?? 'attach failed'),
                'attached_at'          => ($res['ok'] ?? false) ? now() : null,
                'revoked_at'           => null,
            ]
        );

        Log::info('[BSP-CREDIT] attach recorded', [
            'workspace' => $workspaceId, 'waba' => $wabaId,
            'status' => $alloc->status, 'alloc' => $alloc->allocation_config_id,
        ]);

        return $alloc;
    }

    /**
     * Auto-attach hook — called by the core when a WABA connection is saved.
     * When "auto-attach on connect" is ON and the credit source is configured,
     * a customer who connects a WhatsApp number gets our credit line shared to
     * them automatically, so the admin never attaches by hand. Attempts ONCE
     * per WABA (skips if any allocation row already exists) so repeated saves
     * / health pings don't hammer Meta. Never throws — a billing convenience
     * must never break the connect flow.
     */
    public function maybeAutoAttach(\App\Models\WaProviderConfig $cfg): void
    {
        try {
            if ((string) SystemSetting::get('bsp_auto_attach_credit', '0') !== '1') return;
            if ($cfg->provider !== 'waba' || $cfg->status !== 'connected') return;
            if (! $this->source()->isConfigured()) return;

            $wabaId = trim((string) ($cfg->creds()['waba_id'] ?? ''));
            if ($wabaId === '') return;

            // Attempt once per (workspace, WABA): skip if we already recorded
            // any allocation (attached OR failed). Backfill/retry is the
            // explicit "Attach all connected now" button.
            $seen = BspCreditAllocation::where('workspace_id', $cfg->workspace_id)
                ->where('waba_id', $wabaId)->exists();
            if ($seen) return;

            $this->attach((int) $cfg->workspace_id, $wabaId, $this->autoCurrency());
        } catch (\Throwable $e) {
            Log::warning('[BSP-CREDIT] auto-attach skipped', [
                'cfg' => $cfg->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Backfill: attach our credit line to EVERY connected WABA that isn't
     * already attached. Powers the "Attach all connected now" button — one
     * click instead of picking customers one by one, and the way to retry
     * everything once Meta Solution Partner approval lands.
     *
     * @return array{ok:bool,attached:int,failed:int,skipped:int,error?:string}
     */
    public function attachAllConnected(?int $by = null): array
    {
        if (! $this->source()->isConfigured()) {
            return ['ok' => false, 'attached' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'Connect your Meta credit line first (step 1).'];
        }

        $cur = $this->autoCurrency();
        $attached = 0; $failed = 0; $skipped = 0;

        \App\Models\WaProviderConfig::query()
            ->where('provider', 'waba')
            ->where('status', 'connected')
            ->get()
            ->each(function ($cfg) use (&$attached, &$failed, &$skipped, $cur, $by) {
                $wabaId = trim((string) ($cfg->creds()['waba_id'] ?? ''));
                if ($wabaId === '') { $skipped++; return; }

                $already = BspCreditAllocation::where('workspace_id', $cfg->workspace_id)
                    ->where('waba_id', $wabaId)->where('status', 'attached')->exists();
                if ($already) { $skipped++; return; }

                $alloc = $this->attach((int) $cfg->workspace_id, $wabaId, $cur, $by);
                $alloc->status === 'attached' ? $attached++ : $failed++;
            });

        return ['ok' => true, 'attached' => $attached, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * The currency a credit line is shared in when auto-attaching. Uses the
     * admin's chosen default if set, else the platform currency. No fixed
     * list — any currency the platform uses is accepted.
     */
    private function autoCurrency(): string
    {
        $set = strtoupper(trim((string) SystemSetting::get('bsp_auto_attach_currency', '')));
        if ($set !== '') return $set;

        try {
            return strtoupper(\App\Support\FormatSettings::currencyFor()?->code ?? 'USD') ?: 'USD';
        } catch (\Throwable $e) {
            return 'USD';
        }
    }

    /** Revoke an allocation (Meta call + mark the row revoked). */
    public function revoke(BspCreditAllocation $alloc, ?int $by = null): bool
    {
        $res = $this->source()->revoke((string) $alloc->allocation_config_id);

        if ($res['ok'] ?? false) {
            $alloc->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'last_error' => null])->save();
            return true;
        }
        $alloc->forceFill(['last_error' => $res['error'] ?? 'revoke failed'])->save();
        Log::warning('[BSP-CREDIT] revoke failed', ['alloc' => $alloc->id, 'error' => $alloc->last_error]);
        return false;
    }
}
