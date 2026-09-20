<?php

namespace App\Bsp\Services\CreditSource;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Path A — WaDesk's OWN Meta credit line, shared onto customer WABAs.
 *
 * Requires WaDesk to hold Meta Business Partner "Solution Partner" status + a
 * Meta credit line (a commercial/legal step outside code). Config lives in
 * SystemSetting:
 *   bsp_meta_business_id        our Business Manager id
 *   bsp_meta_extended_credit_id our credit line id (auto-resolved if blank)
 *   bsp_meta_system_user_token  System-User token (business_management scope)
 *   bsp_graph_version           default v26.0
 *
 * Graph endpoints (v26.0):
 *   GET  /{business_id}/extendedcredits
 *   POST /{extended_credit_id}/whatsapp_credit_sharing_and_attach
 *          ?waba_id=&waba_currency=            -> { allocation_config_id }
 *   DELETE /{allocation_config_id}
 *
 * NOTE: WABA currency + timezone are IMMUTABLE once a credit line is attached —
 * the currency passed here must be correct up front.
 */
class MetaSolutionPartnerCreditSource implements CreditSourceContract
{
    public function name(): string
    {
        return 'meta';
    }

    private function base(): string
    {
        $v = (string) SystemSetting::get('bsp_graph_version', 'v26.0');
        return "https://graph.facebook.com/{$v}";
    }

    private function token(): string
    {
        return (string) SystemSetting::get('bsp_meta_system_user_token', '');
    }

    public function isConfigured(): bool
    {
        return $this->token() !== ''
            && (string) SystemSetting::get('bsp_meta_business_id', '') !== '';
    }

    /** Our extended-credit id — from config, else resolved from the business. */
    private function extendedCreditId(): ?string
    {
        $id = trim((string) SystemSetting::get('bsp_meta_extended_credit_id', ''));
        if ($id !== '') return $id;

        $businessId = trim((string) SystemSetting::get('bsp_meta_business_id', ''));
        if ($businessId === '') return null;

        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base()}/{$businessId}/extendedcredits", ['fields' => 'id,legal_entity_name']);
            $first = (string) ($r->json('data.0.id') ?? '');
            if ($first !== '') {
                // Cache it so we don't refetch every attach. Type 'string' —
                // SystemSetting::set defaults to 'int', which would zero a token/id.
                SystemSetting::set('bsp_meta_extended_credit_id', $first, 'string');
                return $first;
            }
            Log::warning('[BSP-CREDIT] no extended credit line on business', ['business' => $businessId, 'body' => mb_substr($r->body(), 0, 300)]);
        } catch (\Throwable $e) {
            Log::error('[BSP-CREDIT] extendedcredits fetch failed: ' . $e->getMessage());
        }
        return null;
    }

    public function attach(string $wabaId, string $currency): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'allocation_config_id' => null, 'error' => 'Meta Solution Partner credit not configured (set business id + system-user token in Admin → BSP).'];
        }
        $creditId = $this->extendedCreditId();
        if (! $creditId) {
            return ['ok' => false, 'allocation_config_id' => null, 'error' => 'No Meta extended credit line found for this business.'];
        }

        // Meta ONLY accepts these ISO-4217 codes for waba_currency on a shared
        // credit line (per the Solution-Partner credit-line docs). Reject early
        // with a clear message instead of a cryptic Graph error. The customer's
        // WALLET display currency can still be anything; this limit is only the
        // currency the Meta credit line is denominated in (locked after attach).
        $cur = strtoupper($currency);
        $metaAllowed = ['AUD', 'EUR', 'GBP', 'IDR', 'INR', 'USD'];
        if (! in_array($cur, $metaAllowed, true)) {
            return ['ok' => false, 'allocation_config_id' => null,
                'error' => "Meta credit lines support only " . implode(', ', $metaAllowed) . " — '{$cur}' is not accepted. Pick one of these as the credit-line currency (it is locked once attached)."];
        }

        try {
            // Match Meta's official Postman collection exactly:
            //   POST /{Version}/{Credit-Line-ID}/whatsapp_credit_sharing_and_attach
            //        ?waba_id={{Assigned-WABA-ID}}&waba_currency={{WABA-Currency}}
            // Params go in the QUERY STRING (not the form body).
            $url = "{$this->base()}/{$creditId}/whatsapp_credit_sharing_and_attach?" . http_build_query([
                'waba_id'       => $wabaId,
                'waba_currency' => strtoupper($currency),
            ]);
            $r = Http::withToken($this->token())->acceptJson()->timeout(30)->post($url);

            // Response returns { allocation_config_id, waba_id } (per Meta docs).
            $allocId = (string) ($r->json('allocation_config_id') ?? '');
            if ($r->successful() && $allocId !== '') {
                Log::info('[BSP-CREDIT] attached credit line to WABA', ['waba' => $wabaId, 'alloc' => $allocId]);
                return ['ok' => true, 'allocation_config_id' => $allocId, 'error' => null];
            }
            $err = (string) ($r->json('error.message') ?? ('HTTP ' . $r->status()));
            Log::error('[BSP-CREDIT] attach rejected', ['waba' => $wabaId, 'error' => $err, 'body' => mb_substr($r->body(), 0, 400)]);
            return ['ok' => false, 'allocation_config_id' => null, 'error' => $err];
        } catch (\Throwable $e) {
            return ['ok' => false, 'allocation_config_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function revoke(string $allocationConfigId): array
    {
        if ($allocationConfigId === '') {
            return ['ok' => false, 'error' => 'No allocation id to revoke.'];
        }
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(20)
                ->delete("{$this->base()}/{$allocationConfigId}");
            if ($r->successful()) {
                Log::info('[BSP-CREDIT] revoked allocation', ['alloc' => $allocationConfigId]);
                return ['ok' => true, 'error' => null];
            }
            $err = (string) ($r->json('error.message') ?? ('HTTP ' . $r->status()));
            return ['ok' => false, 'error' => $err];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
