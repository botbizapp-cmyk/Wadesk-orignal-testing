<?php

namespace App\Bsp\Services\CreditSource;

/**
 * Pluggable "who funds the WABA" adapter. The wallet / metering / rate-card
 * engine (P1–P4) is identical whatever the answer; ONLY this attach/revoke step
 * differs between the commercial paths:
 *
 *   - MetaSolutionPartnerCreditSource (Path A, chosen) — WaDesk's own Meta
 *     credit line, shared onto each customer WABA via Graph.
 *   - (future) AggregatorCreditSource (Path B) / partner (Path C).
 *
 * Keeping it behind this contract means the path can change later with no
 * change to any billing code.
 */
interface CreditSourceContract
{
    /** Machine name of this source (stored on the allocation row). */
    public function name(): string;

    /** Is this source configured well enough to make live calls? */
    public function isConfigured(): bool;

    /**
     * Attach our funding to a customer WABA.
     * @return array{ok:bool, allocation_config_id:?string, error:?string}
     */
    public function attach(string $wabaId, string $currency): array;

    /**
     * Revoke a previous attach.
     * @return array{ok:bool, error:?string}
     */
    public function revoke(string $allocationConfigId): array;
}
