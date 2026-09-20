<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One billing record per outbound WhatsApp message. Written by
 * MessageBillingService at delivery time; read by ReportService for the P&L.
 * The wamid unique index is the idempotency + refund key.
 */
class MessageCharge extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'provider', 'wamid', 'to_country', 'category',
        'source', 'credits', 'revenue_minor', 'cost_minor', 'status', 'wallet_tx_id', 'meta',
    ];

    protected $casts = [
        'credits'       => 'integer',
        'revenue_minor' => 'integer',
        'cost_minor'    => 'integer',
        'wallet_tx_id'  => 'integer',
        'meta'          => 'array',
    ];

    public const STATUS_FREE     = 'free';
    public const STATUS_CHARGED  = 'charged';
    public const STATUS_REFUNDED = 'refunded';
}
