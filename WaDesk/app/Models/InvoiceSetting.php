<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceSetting extends Model
{
    protected $fillable = [
        'workspace_id', 'enabled', 'numbering_prefix', 'proforma_prefix', 'fy_reset',
        'tax_label', 'default_tax_rate', 'tax_inclusive_default', 'hsn_default',
        'logo_path', 'brand_color', 'footer_note', 'support_email',
        'seller_name', 'seller_address', 'seller_tax_id', 'seller_reg_no', 'seller_phone',
        'seller_extra_json', 'signature_path', 'signature_label', 'show_signature', 'due_days',
        'auto_send_woocommerce', 'auto_send_shopify', 'auto_send_own',
        'trigger_woocommerce', 'trigger_shopify', 'trigger_own',
        'send_sender', 'template_id_whatsapp', 'template_status', 'meta_json',
    ];

    /** Defaults present in-memory on a fresh row (mirror the DB defaults). */
    protected $attributes = [
        'enabled'          => false,
        'numbering_prefix' => 'INV',
        'proforma_prefix'  => 'PRO',
        'fy_reset'         => true,
        'trigger_woocommerce' => 'on_paid',
        'trigger_shopify'  => 'on_paid',
        'trigger_own'      => 'on_paid',
        'template_status'  => 'none',
    ];

    protected $casts = [
        'enabled'                => 'bool',
        'fy_reset'               => 'bool',
        'tax_inclusive_default'  => 'bool',
        'auto_send_woocommerce'  => 'bool',
        'auto_send_shopify'      => 'bool',
        'auto_send_own'          => 'bool',
        'default_tax_rate'       => 'decimal:3',
        'show_signature'         => 'bool',
        'due_days'               => 'int',
        'seller_extra_json'      => 'array',
        'meta_json'              => 'array',
    ];

    /** The single row for a workspace (created with defaults on first read). */
    public static function forWorkspace(int $workspaceId): self
    {
        return static::firstOrCreate(['workspace_id' => $workspaceId]);
    }
}
