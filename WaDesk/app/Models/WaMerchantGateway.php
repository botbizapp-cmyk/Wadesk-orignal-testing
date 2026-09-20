<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A MERCHANT's own credentials for one payment gateway, used to charge a CUSTOMER
 * for a WaOrder (money → merchant, not platform). Mirrors PaymentGateway's
 * encrypted-credentials convention so the SAME 30 drivers can be constructed with
 * these creds via a transient PaymentGateway (see CustomerPaymentService).
 *
 * Engine-agnostic: the WABA (native Flow) and Unofficial (payment-link) checkout
 * paths both resolve payment through this row.
 */
class WaMerchantGateway extends Model
{
    protected $fillable = [
        'workspace_id', 'storefront_id', 'slug', 'mode', 'active', 'sort_order', 'meta',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
        'meta'       => 'array',
    ];

    /** Never leak the ciphertext through JSON/serialization. */
    protected $hidden = ['credentials'];

    /** Decrypt + decode the credentials JSON. Empty array on miss — same as PaymentGateway. */
    public function getDecryptedCredentials(): array
    {
        if (empty($this->credentials)) return [];
        try {
            $arr = json_decode(Crypt::decryptString($this->credentials), true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Encrypt + set the credentials JSON (call ->save() to persist). */
    public function setEncryptedCredentials(array $values): void
    {
        $this->credentials = Crypt::encryptString(json_encode($values, JSON_UNESCAPED_UNICODE));
    }

    public function getCredential(string $key, $default = null)
    {
        return $this->getDecryptedCredentials()[$key] ?? $default;
    }

    /** True when this row has enough to actually charge (has a non-empty creds set). */
    public function isConfigured(): bool
    {
        return $this->active && count(array_filter($this->getDecryptedCredentials())) > 0;
    }

    public function scopeForWorkspace(Builder $q, ?int $workspaceId): Builder
    {
        return $workspaceId ? $q->where('workspace_id', $workspaceId) : $q;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /**
     * Build a TRANSIENT (non-persisted) PaymentGateway carrying THIS merchant's
     * creds, so PaymentGatewayManager::driverFromModel() can construct the normal
     * driver against the merchant's account — no driver changes, no platform row.
     */
    public function toTransientPaymentGateway(): PaymentGateway
    {
        $gw = new PaymentGateway([
            'slug'      => $this->slug,
            'name'      => $this->slug,
            'mode'      => $this->mode ?: 'live',
            'is_active' => true,
        ]);
        $gw->setEncryptedCredentials($this->getDecryptedCredentials());
        // exists=false: it must never be written to the platform gateways table.
        $gw->exists = false;
        return $gw;
    }
}
