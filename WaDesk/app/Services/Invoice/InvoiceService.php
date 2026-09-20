<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceNumberSequence;
use App\Models\InvoiceSetting;
use App\Models\InvoiceTaxSummary;
use App\Models\WaOrder;
use App\Models\Workspace;
use App\Services\Invoice\Mappers\OwnStoreInvoiceMapper;
use App\Services\PlanLimitGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Invoice engine. issue() = allocate number + persist (fast, webhook-safe, no
 * I/O). renderAndSend() = the deferred heavy path (PDF + delivery) — NEVER called
 * inside a platform webhook. See auto-invoice plan §4.2 / §6.6.
 */
class InvoiceService
{
    public function __construct(
        private InvoicePdfRenderer $pdf,
        private ?InvoiceSender $sender = null,   // wired in P3
    ) {}

    // ── Entry points ──────────────────────────────────────────────────────

    /**
     * Allocate + persist ONLY (no render/send). Safe to call inside a webhook.
     * Returns null when the plan gate soft-skips (never throws in webhook context).
     */
    public function issue(InvoiceDraft $draft, Workspace $ws, ?int $userId = null): ?Invoice
    {
        // 1) Plan gate — SOFT skip (never throw here; a thrown 4xx makes Woo/Shopify retry).
        if (! PlanLimitGuard::hasFeature($ws, 'auto_invoice')) {
            Log::info('invoice.skip.no_feature', ['ws' => $ws->id, 'source' => $draft->source]);

            return null;
        }
        // Monthly cap — over-quota on an already-paid order is FLAGGED, not dropped.
        $cap = (int) $ws->effectiveLimit('auto_invoice_max_monthly', 0);
        if ($cap > 0) {
            $used = Invoice::forWorkspace($ws->id)->whereMonth('issued_at', now()->month)->whereYear('issued_at', now()->year)->count();
            if ($used >= $cap) {
                $draft->meta['quota_flag'] = true;
                Log::warning('invoice.over_quota', ['ws' => $ws->id, 'used' => $used, 'cap' => $cap]);
            }
        }

        // 2) Idempotency — one invoice per (source, external_order_id, doc_type).
        if ($draft->externalOrderId) {
            $existing = Invoice::where('source', $draft->source)
                ->where('external_order_id', $draft->externalOrderId)
                ->where('doc_type', $draft->docType)->first();
            if ($existing) {
                return $existing;
            }
        }

        $settings = InvoiceSetting::forWorkspace($ws->id);
        $series   = $this->seriesFor($draft, $settings);

        // 3) Allocate the number in a SHORT locked txn that commits before any render.
        [$number, $seq] = $this->allocateNumber($ws, $series, $settings);

        // 4) Snapshot the seller.
        $seller = $this->sellerSnapshot($settings);

        // 5) Persist. No PDF, no send.
        return $this->persist($draft, $ws, $userId, $series, $number, $seq, $seller);
    }

    /** Resolve + issue for a paid WaOrder (own-store). $context: webhook|web|console. */
    public function maybeAutoIssue(WaOrder $order, string $source = 'own', string $context = 'web'): ?Invoice
    {
        $ws = Workspace::find($order->workspace_id);
        if (! $ws) {
            return null;
        }
        $settings = InvoiceSetting::forWorkspace($ws->id);
        $trigger  = (string) ($settings->trigger_own ?? 'on_paid');
        $draft    = (new OwnStoreInvoiceMapper())->toDraft($order, $trigger);

        return $this->issue($draft, $ws, $order->workspace->owner_user_id ?? null);
    }

    /** Draft from an order for the manual "Generate invoice" button. */
    public function manualDraftFromOrder(WaOrder $order): InvoiceDraft
    {
        return (new OwnStoreInvoiceMapper())->toDraft($order, 'manual');
    }

    /**
     * Platform-webhook entry (Woo/Shopify) — ledger-dedup a replayed delivery,
     * gate on the per-source auto_send flag, then issue-ONLY (fast, no render).
     * NEVER throws; the sweep (P6) renders + sends later. Own-store passes
     * source='own' and a null deliveryId (not a rate-limited platform webhook).
     */
    public function handleWebhookOrder(WaOrder $order, string $source, ?string $deliveryId = null, ?string $topic = null, ?array $rawPayload = null): ?Invoice
    {
        try {
            $extId = (string) ($source === 'woocommerce' ? $order->woo_order_id
                : ($source === 'shopify' ? $order->shopify_order_id : $order->id));

            // Replay short-circuit — one delivery id processed once.
            if ($deliveryId) {
                $ev = \App\Models\InvoiceWebhookEvent::firstOrCreate(
                    ['source' => $source, 'delivery_id' => $deliveryId],
                    ['topic' => $topic, 'external_order_id' => $extId, 'received_at' => now()]
                );
                if (! $ev->wasRecentlyCreated) {
                    return null; // already handled
                }
            }

            $ws = Workspace::find($order->workspace_id);
            if (! $ws) {
                return null;
            }
            $settings = InvoiceSetting::forWorkspace($ws->id);
            $autoKey  = 'auto_send_'.$source;                 // auto_send_woocommerce/shopify/own
            if (property_exists($settings, $autoKey) === false && ! isset($settings->$autoKey)) {
                // own store uses auto_send_own
            }
            if (! (bool) ($settings->{'auto_send_'.($source === 'own' ? 'own' : $source)} ?? false)) {
                return null; // auto-send off for this source
            }

            $trigger = (string) ($settings->{'trigger_'.($source === 'own' ? 'own' : $source)} ?? 'on_paid');

            // Route to the source-specific mapper. Woo/Shopify parse the RAW
            // webhook payload (correct tax lines, per-line amounts, currency
            // exponent) — falling back to the mirror only if the raw body is
            // absent (e.g. a manual re-issue). Own store maps the WaOrder.
            $draft = match (true) {
                $source === 'woocommerce' && $rawPayload => (new \App\Services\Invoice\Mappers\WooInvoiceMapper())->toDraft($rawPayload, $order, $trigger),
                $source === 'shopify' && $rawPayload     => (new \App\Services\Invoice\Mappers\ShopifyInvoiceMapper())->toDraft($rawPayload, $order, $trigger),
                default => (function () use ($order, $trigger, $source, $extId) {
                    $d = (new OwnStoreInvoiceMapper())->toDraft($order, $trigger);
                    if ($source !== 'own') { $d->source = $source; $d->externalOrderId = $extId; }
                    return $d;
                })(),
            };

            return $this->issue($draft, $ws, $ws->owner_user_id ?? null);
        } catch (\Throwable $e) {
            Log::warning('invoice.webhook_issue_failed', ['source' => $source, 'order' => $order->id, 'err' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The deferred heavy path — render the PDF, store it at the token path, then
     * (P3) send. Guarded by the persistent duplicate-send guard.
     */
    public function renderAndSend(Invoice $invoice): array
    {
        if ($invoice->alreadySent()) {
            return ['ok' => true, 'already' => true];
        }

        // Render + freeze the PDF (idempotent — skip if already stored).
        if (! $invoice->pdf_path) {
            try {
                $invoice->update(['send_status' => Invoice::SEND_RENDERING]);
                $bytes = $this->pdf->render($invoice);
                $path  = 'invoices/'.$invoice->workspace_id.'/'.$invoice->public_token.'.pdf';
                media_storage()->put($path, $bytes);
                $invoice->update([
                    'pdf_path'    => $path,
                    'pdf_disk'    => (string) config('filesystems.default'),
                    'pdf_sha256'  => hash('sha256', $bytes),
                    'pdf_bytes'   => strlen($bytes),
                    'send_status' => Invoice::SEND_READY,
                ]);
            } catch (\Throwable $e) {
                $invoice->increment('send_attempts');
                $invoice->update(['send_status' => Invoice::SEND_FAILED, 'send_reason' => 'render_failed', 'send_error' => mb_substr($e->getMessage(), 0, 500)]);
                Log::error('invoice.render_failed', ['id' => $invoice->id, 'err' => $e->getMessage()]);

                return ['ok' => false, 'error' => 'render_failed'];
            }
        }

        // Delivery — only when a WhatsApp sender is actually configured; a plain
        // "generate" with no delivery set up stays READY + downloadable (never
        // marked failed for the absence of a sender).
        $settings = \App\Models\InvoiceSetting::forWorkspace((int) $invoice->workspace_id);
        if (trim((string) ($settings->send_sender ?? '')) !== '') {
            // Resolve the sender lazily — a container-injected null (nullable ctor
            // arg) must not silently skip delivery. Render-only callers configure
            // no send_sender, so this branch never fires for them.
            $sender = $this->sender ?? app(InvoiceSender::class);

            return $sender->send($invoice->fresh());
        }

        return ['ok' => true, 'rendered' => true, 'pdf_url' => $invoice->fresh()->pdf_url];
    }

    public function resend(Invoice $invoice, ?string $channel = null): array
    {
        if (! $this->sender) {
            return ['ok' => false, 'error' => 'sender_unavailable'];
        }

        return $this->sender->send($invoice, $channel, true);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** Short locked allocation; commits before any render/send. Gap/dup-free. */
    private function allocateNumber(Workspace $ws, string $series, InvoiceSetting $settings): array
    {
        return DB::transaction(function () use ($ws, $series, $settings) {
            $row = InvoiceNumberSequence::where('workspace_id', $ws->id)->where('series', $series)->lockForUpdate()->first();
            if (! $row) {
                // Seed the counter ABOVE any invoice that already exists for this
                // (ws, series) — so a lost/reset sequence resumes cleanly instead
                // of colliding on a number already issued (also "continue from
                // legacy count", plan §2.4).
                $maxSeq = (int) Invoice::forWorkspace($ws->id)->where('series', $series)->max('seq');
                $row = InvoiceNumberSequence::create(['workspace_id' => $ws->id, 'series' => $series, 'next_seq' => $maxSeq + 1]);
                $row = InvoiceNumberSequence::where('id', $row->id)->lockForUpdate()->first();
            }
            $seq = (int) $row->next_seq;
            $row->update(['next_seq' => $seq + 1]);
            $number = $series.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

            return [$number, $seq];
        });
    }

    private function persist(InvoiceDraft $draft, Workspace $ws, ?int $userId, string $series, string $number, int $seq, array $seller): Invoice
    {
        return DB::transaction(function () use ($draft, $ws, $userId, $series, $number, $seq, $seller) {
            $invoice = Invoice::create([
                'workspace_id'         => $ws->id,
                'user_id'              => $userId,
                'source'               => $draft->source,
                'doc_type'             => $draft->docType,
                'wa_order_id'          => $draft->waOrderId,
                'external_order_id'    => $draft->externalOrderId,
                'external_order_number'=> $draft->externalOrderNumber,
                'series'               => $series,
                'invoice_number'       => $number,
                'seq'                  => $seq,
                'status'               => $draft->paidAt ? Invoice::STATUS_PAID : Invoice::STATUS_ISSUED,
                'send_status'          => Invoice::SEND_PENDING,
                'trigger'              => $draft->trigger,
                'issued_at'            => now(),
                'paid_at'              => $draft->paidAt ? Carbon::parse($draft->paidAt) : null,
                'currency'             => $draft->currency,
                'currency_exponent'    => $draft->currencyExponent,
                'subtotal_minor'       => $draft->subtotalMinor,
                'discount_minor'       => $draft->discountMinor,
                'shipping_minor'       => $draft->shippingMinor,
                'tax_minor'            => $draft->taxMinor,
                'total_minor'          => $draft->totalMinor,
                'tax_inclusive'        => $draft->taxInclusive,
                'buyer_name'           => $draft->buyer['name'] ?? null,
                'buyer_email'          => $draft->buyer['email'] ?? null,
                'buyer_phone'          => $draft->buyer['phone'] ?? null,
                'billing_json'         => $draft->billing ?: null,
                'shipping_json'        => $draft->shipping ?: null,
                'seller_snapshot_json' => $seller,
                'public_token'         => $this->uniqueToken(),
                'meta_json'            => $draft->meta ?: null,
            ]);

            foreach ($draft->items as $i => $it) {
                InvoiceItem::create([
                    'invoice_id'          => $invoice->id,
                    'sort'                => $i,
                    'description'         => (string) ($it['description'] ?? 'Item'),
                    'sku'                 => $it['sku'] ?? null,
                    'hsn_sac'             => $it['hsn_sac'] ?? null,
                    'qty'                 => (float) ($it['qty'] ?? 1),
                    'unit_price_minor'    => (int) ($it['unit_price_minor'] ?? 0),
                    'line_subtotal_minor' => (int) ($it['line_subtotal_minor'] ?? 0),
                    'line_discount_minor' => (int) ($it['line_discount_minor'] ?? 0),
                    'tax_rate'            => $it['tax_rate'] ?? null,
                    'tax_amount_minor'    => (int) ($it['tax_amount_minor'] ?? 0),
                    'tax_code'            => $it['tax_code'] ?? null,
                    'currency'            => $draft->currency,
                ]);
            }
            foreach ($draft->taxSummary as $t) {
                InvoiceTaxSummary::create([
                    'invoice_id'   => $invoice->id,
                    'tax_label'    => (string) ($t['label'] ?? 'Tax'),
                    'rate'         => $t['rate'] ?? null,
                    'base_minor'   => (int) ($t['base_minor'] ?? 0),
                    'amount_minor' => (int) ($t['amount_minor'] ?? 0),
                ]);
            }

            return $invoice;
        });
    }

    private function seriesFor(InvoiceDraft $draft, InvoiceSetting $settings): string
    {
        $prefix = $draft->seriesPrefix($settings->numbering_prefix ?: 'INV', $settings->proforma_prefix ?: 'PRO');
        // Financial-year label (India FY starts April) when fy_reset, else calendar year.
        $now = now(safe_timezone(config('app.timezone'), 'Asia/Calcutta'));
        if ($settings->fy_reset) {
            $fyStart = $now->month >= 4 ? $now->year : $now->year - 1;

            return $prefix.'-'.$fyStart;
        }

        return $prefix.'-'.$now->year;
    }

    private function sellerSnapshot(InvoiceSetting $settings): array
    {
        return array_filter([
            'name'      => $settings->seller_name ?: (site_info('company_name') ?: brand_name()),
            'address'   => $settings->seller_address ?: (site_info('address') ?: ''),
            'tax_id'    => (string) ($settings->seller_tax_id ?: site_info('company_tax_id') ?: ''),
            'reg_no'    => (string) ($settings->seller_reg_no ?: ''),
            'phone'     => (string) ($settings->seller_phone ?: (site_info('phone') ?: '')),
            'email'     => $settings->support_email ?: (site_info('email_support') ?: ''),
            'tax_label' => $settings->tax_label ?: '',
            'extra'     => is_array($settings->seller_extra_json) ? array_values(array_filter($settings->seller_extra_json, fn ($r) => ! empty($r['label']) && ! empty($r['value']))) : [],
            // Signature is snapshotted as a path; the renderer base64-embeds it.
            'signature_path'  => $settings->show_signature ? (string) ($settings->signature_path ?: '') : '',
            'signature_label' => (string) ($settings->signature_label ?: 'Authorised signatory'),
        ], fn ($v) => $v !== '' && $v !== null && $v !== []);
    }

    private function uniqueToken(): string
    {
        do {
            $t = Str::random(40);
        } while (Invoice::where('public_token', $t)->exists());

        return $t;
    }
}
