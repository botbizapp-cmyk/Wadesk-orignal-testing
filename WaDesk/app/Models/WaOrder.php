<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer order — every order from every channel lands here. The
 * `source` column tells us how the row was created so the /orders
 * inbox can render a channel badge:
 *
 *   waba       → Meta `messages.order` webhook
 *   storefront → wa.me prefilled cart parsed by InboundParser
 *   twilio     → inbound Twilio webhook (parsed)
 *   manual     → operator created via /orders/create
 *
 * `items_json` is denormalised at write time — products can be
 * deleted without orphaning historical orders.
 */
class WaOrder extends Model
{
    protected $fillable = [
        'workspace_id', 'source', 'shopify_order_id', 'woo_order_id',
        'customer_phone', 'customer_name', 'customer_email', 'customer_address',
        'items_json', 'total_minor', 'shipping_minor', 'discount_minor', 'coupon_code', 'currency_code',
        'tax_minor', 'tax_inclusive', 'tax_lines_json',
        'payment_method', 'rto_score', 'rto_band',
        'status', 'payment_link', 'notes', 'recovery_token',
        'wa_message_id', 'storefront_id', 'meta_json',
    ];

    protected $casts = [
        'items_json'     => 'array',
        'total_minor'    => 'integer',
        'shipping_minor' => 'integer',
        'discount_minor' => 'integer',
        'tax_minor'      => 'integer',
        'tax_inclusive'  => 'boolean',
        'tax_lines_json' => 'array',
        'meta_json'      => 'array',
    ];

    public const SOURCES = ['waba', 'storefront', 'twilio', 'manual', 'shopify', 'woocommerce', 'whatsapp_ai'];
    // Superset: original 5 + the natural-language-ordering states the client
    // asked for (pending / processing / completed). `new` is the inbound
    // default; the operator moves it through the rest.
    public const STATUSES = ['new', 'pending', 'confirmed', 'paid', 'processing', 'completed', 'shipped', 'cancelled'];

    protected static function booted(): void
    {
        // Auto-invoice: on the paid/completed transition, mark a cheap cache flag
        // ONLY — never render/upload/send inside a model observer (it can fire
        // from webhooks, imports, or console). The inline sweep + explicit entry
        // points consume the flag. See auto-invoice plan §3.3.
        static::updated(function (WaOrder $o) {
            if ($o->wasChanged('status') && in_array($o->status, ['paid', 'completed'], true)) {
                \Illuminate\Support\Facades\Cache::put("invoice_pending:{$o->workspace_id}:{$o->id}", 1, now()->addDays(2));
            }
        });

        // Push every new order into HubSpot (contact + deal) when the
        // workspace has a live HubSpot link. The cheap, indexed existence
        // check runs inline; the HubSpot HTTP round-trip is deferred to
        // app()->terminating() so it runs AFTER the response is sent —
        // no queue worker or cron needed (same pattern as appointments).
        static::created(function (WaOrder $order) {
            try {
                $sync = app(\App\Services\Hubspot\HubspotSyncService::class);
                if (!$sync->isConnected((int) $order->workspace_id)) return;
                $orderId = (int) $order->id;
                app()->terminating(function () use ($sync, $orderId) {
                    try {
                        $fresh = WaOrder::find($orderId);
                        if ($fresh) $sync->syncOrder($fresh);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[HUBSPOT] order sync failed: ' . $e->getMessage());
                    }
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HUBSPOT] order observer skipped: ' . $e->getMessage());
            }

            // Sales Pipeline + flow automation — deferred after the response so
            // the order write isn't blocked. Both no-op unless configured
            // (auto-deal is opt-in; order_placed needs a published flow).
            $newOrderId = (int) $order->id;
            app()->terminating(function () use ($newOrderId) {
                try {
                    $fresh = WaOrder::find($newOrderId);
                    if (!$fresh) return;
                    app(\App\Services\Deals\OrderDealService::class)->maybeCreateFromOrder($fresh);
                    app(\App\Services\Flow\FlowEnrollmentService::class)->onOrderPlaced($fresh);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[DEAL/FLOW] order hook failed: ' . $e->getMessage());
                }
            });
        });

        // When an already-synced order changes status, advance the SAME
        // HubSpot deal's stage (no duplicate). Only fires when the status
        // actually changed and the order carries a stored HubSpot deal id.
        static::updated(function (WaOrder $order) {
            try {
                if (!$order->wasChanged('status')) return;
                $meta = is_array($order->meta_json) ? $order->meta_json : [];
                if (empty($meta['hubspot_deal_id'])) return;
                $orderId = (int) $order->id;
                app()->terminating(function () use ($orderId) {
                    try {
                        $fresh = WaOrder::find($orderId);
                        if ($fresh) app(\App\Services\Hubspot\HubspotSyncService::class)->syncOrderStatus($fresh);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[HUBSPOT] deal stage sync failed: ' . $e->getMessage());
                    }
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HUBSPOT] order update observer skipped: ' . $e->getMessage());
            }
        });

        // Jessica P6 — when a WhatsApp-AI / group-pinned order changes status,
        // post the update into the customer's WhatsApp group with an @mention
        // (complete / pending / confirm / cancel …). Deferred + best-effort.
        // Scoped to AI-ordering orders (or any order explicitly pinned to a
        // group via meta_json.group_code) so ordinary orders never spam groups.
        static::updated(function (WaOrder $order) {
            try {
                if (!$order->wasChanged('status')) return;
                $meta      = is_array($order->meta_json) ? $order->meta_json : [];
                $groupCode = $meta['group_code'] ?? null;
                if ($order->source !== 'whatsapp_ai' && empty($groupCode)) return;
                if (empty($order->customer_phone)) return;

                $oid    = (int) $order->id;
                $wsId   = (int) $order->workspace_id;
                $phone  = (string) $order->customer_phone;
                $status = (string) $order->status;
                $currency = $order->currency_code;
                $totalMinor = (int) $order->total_minor;

                $custLang = is_array($order->meta_json) ? ($order->meta_json['customer_lang'] ?? null) : null;
                app()->terminating(function () use ($oid, $wsId, $phone, $status, $groupCode, $currency, $totalMinor, $custLang) {
                    try {
                        $dir = app(\App\Services\Ordering\GroupDirectory::class)
                            ->resolveForCustomer($wsId, $phone, $groupCode);
                        if (empty($dir['group'])) return;
                        $body = "*Order #{$oid}* — status: *" . ucfirst($status) . "*\n"
                              . 'Total: ' . self::formatMoney($totalMinor, $currency);
                        app(\App\Services\Ordering\GroupNotifier::class)
                            ->notifyCustomerInGroup($dir['group'], $phone, $body, $custLang);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[ORDERING] status group notify failed: ' . $e->getMessage());
                    }
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[ORDERING] status observer skipped: ' . $e->getMessage());
            }
        });
    }

    /** Money formatter shared by the group-notify text (mirrors WaProduct). */
    private static function formatMoney(int $minor, ?string $code): string
    {
        return \App\Models\WaProduct::formatCurrency($minor, $code);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(WaStorefront::class, 'storefront_id')->withDefault();
    }

    /**
     * The WhatsApp sender the merchant PICKED for this order's shop, as fields
     * to merge into an outbound Message so EVERY shop DM (payment link, status,
     * checkout link, receipt, invoice) goes out FROM the shop's own number and
     * threads under it in the inbox — NOT the workspace's default/newest device,
     * which is what the dispatcher falls back to when a message carries no
     * number (the "inbox uses a different number" bug).
     *   - shop bound to an Unofficial (Baileys) device → that device's phone.
     *   - shop on the official API (device_id null)     → its connected WABA sender.
     * Returns [] when the order isn't tied to a shop with a resolvable number,
     * so the dispatcher's existing default applies unchanged.
     *
     * @return array{from_number?: string, provider?: string}
     */
    public function shopSenderFields(): array
    {
        if (! $this->storefront_id) {
            return [];
        }
        $sf = WaStorefront::where('workspace_id', $this->workspace_id)
            ->where('id', $this->storefront_id)->first();
        if (! $sf) {
            return [];
        }

        if ($sf->device_id) {
            $dev = Device::where('id', $sf->device_id)
                ->where('workspace_id', $this->workspace_id)->first();
            if ($dev) {
                $from = preg_replace('/\D+/', '', (string) (($dev->country_code ?? '') . $dev->phone_number));
                if ($from !== '') {
                    return ['from_number' => $from, 'provider' => 'baileys'];
                }
            }
        }

        $cfg = WaProviderConfig::query()
            ->where('workspace_id', $this->workspace_id)
            ->where('provider', 'waba')
            ->where('status', WaProviderConfig::STATUS_CONNECTED)
            ->orderBy('id')->first();
        if ($cfg && trim((string) $cfg->phone_number) !== '') {
            return ['from_number' => preg_replace('/\D+/', '', (string) $cfg->phone_number), 'provider' => 'waba'];
        }

        return [];
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(WaOrderItem::class, 'order_id');
    }

    /** The invoice issued for this order (latest; a proforma + tax invoice can coexist). */
    public function invoice()
    {
        return $this->hasOne(Invoice::class, 'wa_order_id')->latestOfMany();
    }

    /**
     * Returns the normalized item list for rendering. WABA-driven
     * orders write to wa_order_items; older orders kept everything
     * in items_json. This accessor returns the same shape regardless
     * of which path created the order, so the /orders templates
     * don't need to branch.
     */
    public function getRenderableItemsAttribute(): array
    {
        // hasMany rows take precedence — they have the cleaner shape
        if ($this->relationLoaded('lineItems') ? $this->lineItems->isNotEmpty() : $this->lineItems()->exists()) {
            return $this->lineItems->map(fn ($i) => [
                'name'         => $i->name,
                'image_url'    => $i->image_url,
                'qty'          => $i->quantity,
                'price_minor'  => $i->price_minor,
                'currency'     => $i->currency_code,
                'retailer_id'  => $i->retailer_id,
                'product_id'   => $i->product_id,
            ])->all();
        }
        // Fallback to the old denormalised items_json shape
        return is_array($this->items_json) ? $this->items_json : [];
    }

    public function getTotalMajorAttribute(): float
    {
        return $this->total_minor / 100;
    }

    public function getTotalDisplayAttribute(): string
    {
        $sym = match ($this->currency_code) {
            'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', default => $this->currency_code,
        };
        return $sym . ' ' . number_format($this->total_major, 2);
    }

    public function scopeForWorkspace(Builder $q, ?int $workspaceId): Builder
    {
        return $workspaceId ? $q->where('workspace_id', $workspaceId) : $q;
    }

    public function scopeWithStatus(Builder $q, string|array $status): Builder
    {
        return is_array($status) ? $q->whereIn('status', $status) : $q->where('status', $status);
    }
}
