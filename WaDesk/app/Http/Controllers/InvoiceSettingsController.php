<?php

namespace App\Http\Controllers;

use App\Models\InvoiceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvoiceSettingsController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    public function edit()
    {
        $settings = InvoiceSetting::forWorkspace($this->wsId());
        // Connected WhatsApp senders for the delivery/template picker.
        $senders = $this->senders();
        $template = $settings->template_id_whatsapp
            ? \App\Models\WaTemplate::find($settings->template_id_whatsapp) : null;

        return view('user.invoices.settings', compact('settings', 'senders', 'template'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'enabled'               => 'boolean',
            'numbering_prefix'      => 'nullable|string|max:16',
            'proforma_prefix'       => 'nullable|string|max:16',
            'fy_reset'              => 'boolean',
            'tax_label'             => 'nullable|string|max:24',
            'default_tax_rate'      => 'nullable|numeric|min:0|max:100',
            'tax_inclusive_default' => 'boolean',
            'hsn_default'           => 'nullable|string|max:16',
            'due_days'              => 'nullable|integer|min:0|max:365',
            'brand_color'           => 'nullable|string|max:9',
            'footer_note'           => 'nullable|string|max:2000',
            'support_email'         => 'nullable|email|max:191',
            // Seller / company identity (flows to every PDF).
            'seller_name'           => 'nullable|string|max:191',
            'seller_address'        => 'nullable|string|max:1000',
            'seller_tax_id'         => 'nullable|string|max:64',
            'seller_reg_no'         => 'nullable|string|max:64',
            'seller_phone'          => 'nullable|string|max:40',
            'extra_label'           => 'nullable|array',
            'extra_label.*'         => 'nullable|string|max:40',
            'extra_value'           => 'nullable|array',
            'extra_value.*'         => 'nullable|string|max:80',
            'signature_label'       => 'nullable|string|max:64',
            'show_signature'        => 'boolean',
            'logo'                  => 'nullable|image|max:2048',
            'signature'             => 'nullable|image|max:2048',
            'send_sender'           => 'nullable|string|max:48',
            'auto_send_woocommerce' => 'boolean',
            'auto_send_shopify'     => 'boolean',
            'auto_send_own'         => 'boolean',
            'trigger_woocommerce'   => 'nullable|in:on_placed,on_paid,on_fulfilled',
            'trigger_shopify'       => 'nullable|in:on_placed,on_paid,on_fulfilled',
            'trigger_own'           => 'nullable|in:on_placed,on_paid,on_fulfilled',
        ]);
        $settings = InvoiceSetting::forWorkspace($this->wsId());

        // Uploads → media_storage (never a remote URL — the PDF renderer refuses one).
        if ($request->hasFile('logo')) {
            $settings->logo_path = $this->storeImage($request->file('logo'), 'logo');
        }
        if ($request->hasFile('signature')) {
            $settings->signature_path = $this->storeImage($request->file('signature'), 'sig');
        }

        // Extra statutory fields (PAN/CIN/…) → [{label,value}].
        $extra = [];
        foreach ((array) ($data['extra_label'] ?? []) as $i => $lab) {
            $val = trim((string) ($data['extra_value'][$i] ?? ''));
            $lab = trim((string) $lab);
            if ($lab !== '' && $val !== '') {
                $extra[] = ['label' => $lab, 'value' => $val];
            }
        }

        $settings->fill([
            'enabled'               => $request->boolean('enabled'),
            'numbering_prefix'      => strtoupper($data['numbering_prefix'] ?? 'INV'),
            'proforma_prefix'       => strtoupper($data['proforma_prefix'] ?? 'PRO'),
            'fy_reset'              => $request->boolean('fy_reset'),
            'tax_label'             => $data['tax_label'] ?? null,
            'default_tax_rate'      => $data['default_tax_rate'] ?? null,
            'tax_inclusive_default' => $request->boolean('tax_inclusive_default'),
            'hsn_default'           => $data['hsn_default'] ?? null,
            'due_days'              => $data['due_days'] ?? null,
            'brand_color'           => $data['brand_color'] ?? null,
            'footer_note'           => $data['footer_note'] ?? null,
            'support_email'         => $data['support_email'] ?? null,
            'seller_name'           => $data['seller_name'] ?? null,
            'seller_address'        => $data['seller_address'] ?? null,
            'seller_tax_id'         => $data['seller_tax_id'] ?? null,
            'seller_reg_no'         => $data['seller_reg_no'] ?? null,
            'seller_phone'          => $data['seller_phone'] ?? null,
            'seller_extra_json'     => $extra ?: null,
            'signature_label'       => $data['signature_label'] ?? null,
            'show_signature'        => $request->boolean('show_signature'),
            'send_sender'           => $data['send_sender'] ?? null,
            'auto_send_woocommerce' => $request->boolean('auto_send_woocommerce'),
            'auto_send_shopify'     => $request->boolean('auto_send_shopify'),
            'auto_send_own'         => $request->boolean('auto_send_own'),
            'trigger_woocommerce'   => $data['trigger_woocommerce'] ?? 'on_paid',
            'trigger_shopify'       => $data['trigger_shopify'] ?? 'on_paid',
            'trigger_own'           => $data['trigger_own'] ?? 'on_paid',
        ])->save();

        return back()->with('success', 'Invoice settings saved.');
    }

    /** Store an uploaded image to media_storage; returns the path. */
    private function storeImage(\Illuminate\Http\UploadedFile $file, string $kind): string
    {
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = 'invoices/branding/'.$this->wsId().'/'.$kind.'_'.\Illuminate\Support\Str::random(16).'.'.$ext;
        media_storage()->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /** P2 — auto-create + submit the WhatsApp invoice template (mirrors OTP flow). */
    public function createTemplate(Request $request)
    {
        return app(\App\Services\Invoice\InvoiceTemplateProvisioner::class)->provision(
            $this->wsId(),
            trim((string) $request->input('send_sender', ''))
        );
    }

    /** Connected WhatsApp senders (WABA + Baileys devices) for the pickers. */
    private function senders(): array
    {
        $wsId = $this->wsId();
        $out = [];
        foreach (\App\Models\WaProviderConfig::where('workspace_id', $wsId)->where('provider', 'waba')->get() as $c) {
            $waLabel = trim((string) $c->phone_number) ?: trim((string) $c->display_label) ?: ('#'.$c->id);
            if ($c->phone_number && $c->display_label) {
                $waLabel = $c->phone_number.' · '.$c->display_label;
            }
            $out[] = ['value' => 'waba:'.$c->id, 'label' => 'WABA · '.$waLabel, 'kind' => 'waba'];
        }
        foreach (\App\Models\Device::query()->forCurrentWorkspace()->get() as $d) {
            $num = trim(($d->country_code ? '+'.$d->country_code.' ' : '').$d->phone_number);
            $dLabel = $num ?: trim((string) $d->device_name) ?: ('#'.$d->id);
            if ($num && $d->device_name) {
                $dLabel = $num.' · '.$d->device_name;
            }
            $out[] = ['value' => 'device:'.$d->id, 'label' => 'Unofficial · '.$dLabel, 'kind' => 'device'];
        }

        return $out;
    }
}
