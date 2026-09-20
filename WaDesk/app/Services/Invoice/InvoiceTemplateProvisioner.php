<?php

namespace App\Services\Invoice;

use App\Models\Device;
use App\Models\InvoiceSetting;
use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Services\Waba\TemplateClient;
use App\Services\Waba\TemplatePayloadBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Auto-creates + submits the WhatsApp invoice template — mirrors
 * {@see \App\Http\Controllers\AdminPagesController::createOtpTemplate}.
 *
 * WABA: a UTILITY template whose URL button links to the hosted PDF
 * (`{app}/i/{{1}}`), submitted to Meta for approval. Because it is a template it
 * sends ANYTIME — no 24h window, no media upload. Unofficial (Baileys) needs no
 * template: it sends the PDF document directly, so this just marks it ready.
 */
class InvoiceTemplateProvisioner
{
    /**
     * Meta template name — DERIVED from the brand, not hardcoded, so a
     * white-labelled install ships e.g. "acme_invoice" instead of the literal
     * "wadesk_invoice". Meta requires lowercase [a-z0-9_].
     */
    private function templateName(): string
    {
        $slug = \Illuminate\Support\Str::slug((string) brand_name(), '_');
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
        return ($slug !== '' ? $slug : 'brand') . '_invoice';
    }

    public function provision(int $wsId, string $sender): RedirectResponse
    {
        [$kind, $idRaw] = array_pad(explode(':', $sender, 2), 2, '');
        $id = (int) $idRaw;
        if ($id <= 0 || ! in_array($kind, ['device', 'waba'], true)) {
            return back()->with('error', 'Pick a connected WhatsApp sender first, then create the template.');
        }

        $settings = InvoiceSetting::forWorkspace($wsId);
        $settings->update(['send_sender' => $kind.':'.$id]);

        // ── Unofficial (Baileys): no Meta template needed — PDF is sent directly ──
        if ($kind === 'device') {
            $dev = Device::where('workspace_id', $wsId)->find($id);
            if (! $dev) {
                return back()->with('error', 'That Unofficial device was not found.');
            }
            $settings->update(['template_id_whatsapp' => null, 'template_status' => 'approved']);

            return back()->with('status', 'Unofficial is ready — it sends the invoice PDF directly, no Meta template needed.');
        }

        // ── WABA: UTILITY template with a URL button → hosted PDF, submitted to Meta ──
        $cfg = WaProviderConfig::where('id', $id)->where('provider', 'waba')->where('workspace_id', $wsId)->first();
        if (! $cfg) {
            return back()->with('error', 'That WABA sender was not found. Reconnect it and try again.');
        }

        $base = rtrim((string) config('app.url'), '/');
        $fields = [
            'category'       => 'utility',
            'meta_category'  => 'UTILITY',
            'template_type'  => 'standard',
            'template_body'  => 'Hi {{1}}, your invoice {{2}} for {{3}} is ready. Tap the button below to view or download it.',
            'variable_map'   => ['body' => [['Alex'], ['INV-2026-000123'], ['$49.00']]],
            'buttons'        => [[
                'type'    => 'visit_website',
                'text'    => 'View invoice',
                'value'   => $base.'/i/{{1}}',   // dynamic URL — {{1}} = the public token
                'example' => [$base.'/i/abc123token'],
            ]],
            'language'       => 'en_US',
        ];

        $tpl = WaTemplate::query()->where('workspace_id', $wsId)
            ->get()->first(fn ($t) => (string) $t->template_name === $this->templateName());
        $tpl ? $tpl->update($fields)
             : $tpl = WaTemplate::create($fields + ['workspace_id' => $wsId, 'template_name' => $this->templateName(), 'status' => 'pending']);

        try {
            $client  = new TemplateClient($cfg);
            $builder = new TemplatePayloadBuilder();
            $result  = $client->submit($builder->build($tpl));
            if (($result['id'] ?? '') === '') {
                return back()->with('error', 'Meta accepted the request but created no template. Check the WABA connection and try again.');
            }
            $tpl->update([
                'provider_config_id' => $cfg->id,
                'meta_template_id'   => $result['id'],
                'meta_status'        => $result['status'] ?: 'PENDING',
                'meta_category'      => $result['category'] ?: 'UTILITY',
                'status'             => 'pending',
            ]);
            $settings->update([
                'template_id_whatsapp' => $tpl->id,
                'template_status'      => strtolower((string) ($result['status'] ?: 'pending')) === 'approved' ? 'approved' : 'pending',
            ]);

            return back()->with('status', 'Invoice template submitted to Meta for approval. It usually clears within minutes to a few hours.');
        } catch (\Throwable $e) {
            Log::error('invoice.template.submit_failed', ['ws' => $wsId, 'err' => $e->getMessage()]);
            $settings->update(['template_id_whatsapp' => $tpl->id, 'template_status' => 'pending']);

            return back()->with('error', 'Could not submit to Meta: '.mb_substr($e->getMessage(), 0, 160));
        }
    }
}
