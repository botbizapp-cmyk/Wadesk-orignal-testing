<?php

namespace App\Services\Invoice;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Services\Waba\TemplateSender;
use App\Services\WhatsAppDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Delivers the invoice over WhatsApp — WhatsApp only (WABA official + Baileys
 * unofficial), per the user-refined scope:
 *   - WABA:    the UTILITY template whose URL button links to the hosted PDF
 *              ({app}/i/{token}). A template sends ANYTIME — no 24h window, no
 *              media upload.
 *   - Baileys: the PDF sent as a document directly.
 * Persistent duplicate-send guard (sent_at) survives restarts/replays/resends.
 */
class InvoiceSender
{
    public function send(Invoice $invoice, ?string $channel = null, bool $isResend = false): array
    {
        // Persistent dup guard — a resend is the only path that re-delivers.
        if (! $isResend && $invoice->alreadySent()) {
            return ['ok' => true, 'already' => true];
        }
        // Race guard for near-simultaneous triggers (NOT the source of truth).
        $lock = Cache::lock('invoice_send_lock:'.$invoice->id, 30);
        if (! $lock->get()) {
            return ['ok' => false, 'error' => 'send_in_progress'];
        }

        try {
            $settings = InvoiceSetting::forWorkspace((int) $invoice->workspace_id);
            $sender   = trim((string) ($channel ?: $settings->send_sender ?? ''));
            [$kind, $idRaw] = array_pad(explode(':', $sender, 2), 2, '');
            $id = (int) $idRaw;
            $phone = preg_replace('/\D+/', '', (string) $invoice->buyer_phone);

            if ($phone === '') {
                return $this->fail($invoice, 'no_recipient_phone');
            }
            if ($id <= 0 || ! in_array($kind, ['waba', 'device'], true)) {
                return $this->fail($invoice, 'no_sender_configured');
            }

            $invoice->update(['send_status' => Invoice::SEND_SENDING]);

            $res = $kind === 'waba'
                ? $this->sendWaba($invoice, $settings, $id, $phone)
                : $this->sendBaileys($invoice, $id, $phone);

            if ($res['ok'] ?? false) {
                $invoice->update([
                    'send_status'      => Invoice::SEND_SENT,
                    'sent_at'          => now(),
                    'delivered_at'     => now(),
                    'delivery_channel' => 'whatsapp',
                    'wa_message_id'    => (string) ($res['wa_message_id'] ?? ''),
                    'send_reason'      => null,
                    'send_error'       => null,
                ]);
                $this->mirror($invoice, $phone);

                return ['ok' => true, 'wa_message_id' => $res['wa_message_id'] ?? null];
            }

            return $this->fail($invoice, $res['reason'] ?? 'send_failed', $res['error'] ?? null);
        } catch (\Throwable $e) {
            Log::error('invoice.send_exception', ['id' => $invoice->id, 'err' => $e->getMessage()]);

            return $this->fail($invoice, 'send_exception', $e->getMessage());
        } finally {
            optional($lock)->release();
        }
    }

    /** WABA — send the approved URL-button UTILITY template (no 24h window). */
    private function sendWaba(Invoice $invoice, InvoiceSetting $settings, int $cfgId, string $phone): array
    {
        $cfg = WaProviderConfig::where('id', $cfgId)->where('provider', 'waba')->where('workspace_id', $invoice->workspace_id)->first();
        if (! $cfg) {
            return ['ok' => false, 'reason' => 'waba_sender_missing'];
        }
        $tpl = $settings->template_id_whatsapp ? WaTemplate::find($settings->template_id_whatsapp) : null;
        if (! $tpl) {
            return ['ok' => false, 'reason' => 'no_invoice_template'];
        }
        if (strtoupper((string) $tpl->meta_status) !== 'APPROVED' && strtolower((string) $tpl->status) !== 'approved') {
            return ['ok' => false, 'reason' => 'template_not_approved'];
        }

        // Body {{1}}=name {{2}}=number {{3}}=total; URL button {{1}}=public token.
        $vars = [
            'body'    => [$invoice->buyer_name ?: 'there', $invoice->invoice_number, $invoice->total_display],
            'buttons' => [['index' => 0, 'sub_type' => 'url', 'value' => $invoice->public_token]],
        ];
        $res = app(TemplateSender::class)->send($tpl, $phone, $vars, $cfg);

        return ($res['ok'] ?? false)
            ? ['ok' => true, 'wa_message_id' => (string) ($res['wamid'] ?? $res['message_id'] ?? '')]
            : ['ok' => false, 'reason' => 'waba_send_failed', 'error' => $res['error'] ?? null];
    }

    /** Baileys — send the PDF as a document directly (no template, no window). */
    private function sendBaileys(Invoice $invoice, int $deviceId, string $phone): array
    {
        $dev = Device::where('id', $deviceId)->where('workspace_id', $invoice->workspace_id)->first();
        if (! $dev) {
            return ['ok' => false, 'reason' => 'device_missing'];
        }
        if (! $invoice->pdf_path) {
            return ['ok' => false, 'reason' => 'pdf_not_rendered'];
        }
        $res = app(WhatsAppDispatcher::class)->sendRaw([
            'from_number'  => $dev->phone_number,
            'to_number'    => $phone,
            'body'         => 'Invoice '.$invoice->invoice_number.' — '.$invoice->total_display,
            'media_path'   => $invoice->pdf_path,
            'media_type'   => 'document',
            'workspace_id' => $invoice->workspace_id,
            'provider'     => 'baileys',
            'meta'         => ['invoice_id' => $invoice->id, 'filename' => $invoice->invoice_number.'.pdf'],
        ], $invoice->user_id, 'W');

        return ($res['ok'] ?? false)
            ? ['ok' => true, 'wa_message_id' => (string) ($res['message_id'] ?? $res['wa_message_id'] ?? '')]
            : ['ok' => false, 'reason' => 'baileys_send_failed', 'error' => $res['error'] ?? null];
    }

    /** Best-effort mirror into the buyer's Team Inbox thread (if one exists). */
    private function mirror(Invoice $invoice, string $phone): void
    {
        try {
            $conv = \App\Models\Conversation::where('workspace_id', $invoice->workspace_id)
                ->where('contact_digits', $phone)->first();
            if (! $conv) {
                return;
            }
            \App\Models\InboxMessage::create([
                'conversation_id' => $conv->id,
                'provider'        => (string) $conv->provider,
                'direction'       => 'out',
                'type'            => 'document',
                'body'            => 'Invoice '.$invoice->invoice_number,
                'status'          => 'sent',
                'meta'            => ['source' => 'auto_invoice', 'invoice_id' => $invoice->id, 'invoice_url' => $invoice->publicUrl()],
                'sent_at'         => now(),
            ]);
            $conv->forceFill(['last_message_at' => now(), 'last_outbound_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('invoice.mirror_failed', ['id' => $invoice->id, 'err' => $e->getMessage()]);
        }
    }

    private function fail(Invoice $invoice, string $reason, ?string $error = null): array
    {
        $invoice->increment('send_attempts');
        $invoice->update([
            'send_status' => Invoice::SEND_FAILED,
            'send_reason' => $reason,
            'send_error'  => $error ? mb_substr($error, 0, 500) : null,
        ]);

        return ['ok' => false, 'error' => $reason];
    }
}
