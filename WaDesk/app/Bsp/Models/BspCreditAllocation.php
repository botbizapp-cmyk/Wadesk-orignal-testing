<?php

namespace App\Bsp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Record of a credit-line attach to a customer WABA (bsp mode). Holds the
 * allocation_config_id returned by Meta's whatsapp_credit_sharing_and_attach
 * (needed to revoke later).
 */
class BspCreditAllocation extends Model
{
    protected $table = 'bsp_credit_allocations';

    protected $fillable = [
        'workspace_id', 'waba_id', 'allocation_config_id', 'credit_source',
        'currency', 'status', 'last_error', 'attached_at', 'revoked_at',
    ];

    protected $casts = [
        'attached_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public const STATUSES = ['attached', 'revoked', 'failed'];
}
