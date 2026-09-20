@php
    /** Editable invoice settings form — embeddable in a store tab or the standalone page. */
    $settings = $settings ?? \App\Models\InvoiceSetting::forWorkspace((int) (auth()->user()->current_workspace_id ?? 0));
    if (! isset($senders)) {
        $__ws = (int) (auth()->user()->current_workspace_id ?? 0);
        $senders = [];
        foreach (\App\Models\WaProviderConfig::where('workspace_id', $__ws)->where('provider', 'waba')->get() as $c) {
            $waLabel = trim((string) $c->phone_number) ?: trim((string) $c->display_label) ?: ('#'.$c->id);
            if ($c->phone_number && $c->display_label) $waLabel = $c->phone_number.' · '.$c->display_label;
            $senders[] = ['value' => 'waba:'.$c->id, 'label' => 'WABA · '.$waLabel];
        }
        foreach (\App\Models\Device::where('workspace_id', $__ws)->get() as $d) {
            $num = trim(($d->country_code ? '+'.$d->country_code.' ' : '').$d->phone_number);
            $dLabel = $num ?: trim((string) $d->device_name) ?: ('#'.$d->id);
            if ($num && $d->device_name) $dLabel = $num.' · '.$d->device_name;
            $senders[] = ['value' => 'device:'.$d->id, 'label' => 'Unofficial · '.$dLabel];
        }
    }
    $extra = is_array($settings->seller_extra_json) ? $settings->seller_extra_json : [];
@endphp

<form method="POST" action="{{ route('user.invoices.settings.save') }}" enctype="multipart/form-data" class="space-y-5">@csrf

    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
        <div class="font-serif text-[18px]">{{ __('Company details') }} <span class="text-[11px] font-normal text-ink-400">{{ __('(prints on every invoice)') }}</span></div>
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Legal / business name') }}</span><input name="seller_name" value="{{ $settings->seller_name }}" maxlength="191" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Address') }}</span><textarea name="seller_address" rows="2" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">{{ $settings->seller_address }}</textarea></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Tax number (GSTIN / VAT)') }}</span><input name="seller_tax_id" value="{{ $settings->seller_tax_id }}" maxlength="64" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Registration no. (CIN / Reg)') }}</span><input name="seller_reg_no" value="{{ $settings->seller_reg_no }}" maxlength="64" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Phone') }}</span><input name="seller_phone" value="{{ $settings->seller_phone }}" maxlength="40" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Support email (footer)') }}</span><input name="support_email" type="email" value="{{ $settings->support_email }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
        </div>
        <div>
            <div class="flex items-center justify-between mb-1"><span class="text-[11px] font-semibold text-ink-700">{{ __('Extra fields') }}</span><button type="button" data-add-extra class="text-[11px] text-wa-deep font-semibold hover:underline">+ {{ __('Add field') }}</button></div>
            <div data-extra-rows class="space-y-2">
                @foreach ($extra as $ex)
                    <div class="flex items-center gap-2" data-extra-row>
                        <input name="extra_label[]" value="{{ $ex['label'] ?? '' }}" placeholder="{{ __('Label e.g. PAN') }}" class="w-40 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                        <input name="extra_value[]" value="{{ $ex['value'] ?? '' }}" placeholder="{{ __('Value') }}" class="flex-1 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                        <button type="button" data-remove-extra class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
        <div class="font-serif text-[18px]">{{ __('Logo & signature') }}</div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <span class="text-[11px] font-semibold text-ink-700">{{ __('Company logo') }}</span>
                @if ($settings->logo_path)<div class="mt-1 mb-1"><img src="{{ media_url($settings->logo_path) }}" class="h-10" alt=""></div>@endif
                <input name="logo" type="file" accept="image/*" class="mt-1 w-full text-[12px] file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[12px] file:font-semibold">
            </div>
            <div>
                <span class="text-[11px] font-semibold text-ink-700">{{ __('Signature image') }}</span>
                @if ($settings->signature_path)<div class="mt-1 mb-1"><img src="{{ media_url($settings->signature_path) }}" class="h-10" alt=""></div>@endif
                <input name="signature" type="file" accept="image/*" class="mt-1 w-full text-[12px] file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[12px] file:font-semibold">
                <div class="flex items-center gap-2 mt-2">
                    <input name="signature_label" value="{{ $settings->signature_label }}" placeholder="{{ __('Authorised signatory') }}" class="flex-1 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                    <label class="flex items-center gap-1.5 text-[12px] text-ink-700 shrink-0"><input type="checkbox" name="show_signature" value="1" @checked($settings->show_signature) class="accent-wa-deep"> {{ __('Show') }}</label>
                </div>
            </div>
        </div>
        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Brand color') }}</span><input name="brand_color" value="{{ $settings->brand_color }}" placeholder="#0B3D2E" maxlength="9" class="mt-1 w-40 rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
    </div>

    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
        <div class="font-serif text-[18px]">{{ __('Tax & numbering') }}</div>
        <div class="grid sm:grid-cols-3 gap-3">
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Tax label') }}</span><input name="tax_label" value="{{ $settings->tax_label }}" placeholder="GST / VAT" maxlength="24" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Default tax rate %') }}</span><input name="default_tax_rate" type="number" step="0.01" min="0" max="100" value="{{ $settings->default_tax_rate }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Default HSN/SAC') }}</span><input name="hsn_default" value="{{ $settings->hsn_default }}" maxlength="16" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Number prefix') }}</span><input name="numbering_prefix" value="{{ $settings->numbering_prefix }}" maxlength="16" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono uppercase focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Due in (days)') }}</span><input name="due_days" type="number" min="0" max="365" value="{{ $settings->due_days }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <label class="flex items-center gap-2 text-[12px] text-ink-700 mt-6"><input type="checkbox" name="tax_inclusive_default" value="1" @checked($settings->tax_inclusive_default) class="accent-wa-deep"> {{ __('Prices tax-inclusive') }}</label>
        </div>
        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Footer note') }}</span><textarea name="footer_note" rows="2" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">{{ $settings->footer_note }}</textarea></label>
        <p class="text-[11px] text-ink-400">{{ __('A taxless order is issued as a Receipt (not a tax invoice) — set a tax rate at checkout for a proper tax invoice.') }}</p>
    </div>

    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
        <div class="font-serif text-[18px]">{{ __('WhatsApp delivery') }}</div>
        <label class="flex items-center justify-between"><span class="text-[13px] font-semibold">{{ __('Enable invoices') }}</span><input type="checkbox" name="enabled" value="1" @checked($settings->enabled) class="accent-wa-deep w-4 h-4"></label>
        <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Send from') }}</span>
            <select name="send_sender" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                <option value="">{{ __('— pick a connected WhatsApp sender —') }}</option>
                @foreach ($senders as $s)<option value="{{ $s['value'] }}" @selected($settings->send_sender === $s['value'])>{{ $s['label'] }}</option>@endforeach
            </select>
            <span class="text-[10.5px] text-ink-400">{{ __('WABA sends a template with a button linking to the PDF (no 24h limit). Unofficial sends the PDF file directly.') }}</span>
        </label>
        <div class="grid sm:grid-cols-3 gap-2 text-[12px]">
            <label class="flex items-center gap-2"><input type="checkbox" name="auto_send_own" value="1" @checked($settings->auto_send_own) class="accent-wa-deep"> {{ __('Auto-send WhatsApp Store') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="auto_send_woocommerce" value="1" @checked($settings->auto_send_woocommerce) class="accent-wa-deep"> {{ __('Auto-send WooCommerce') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="auto_send_shopify" value="1" @checked($settings->auto_send_shopify) class="accent-wa-deep"> {{ __('Auto-send Shopify') }}</label>
        </div>
        <div class="rounded-xl bg-paper-50 border border-paper-200 p-3">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="text-[12px]"><span class="font-semibold">{{ __('Invoice template') }}</span>
                    <span class="ml-2 font-mono text-[10px] uppercase px-2 py-0.5 rounded-full {{ $settings->template_status==='approved'?'bg-wa-green/15 text-wa-deep':($settings->template_status==='rejected'?'bg-accent-coral/10 text-accent-coral':($settings->template_status==='pending'?'bg-accent-amber/20 text-[#7B5A14]':'bg-paper-100 text-ink-600')) }}">{{ $settings->template_status }}</span>
                </div>
                <button type="submit" formaction="{{ route('user.invoices.template.create') }}" class="px-3 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">+ {{ __('Create & submit invoice template') }}</button>
            </div>
            <div class="text-[10.5px] text-ink-400 mt-1">{{ __('WABA is submitted to Meta for approval; Unofficial is ready instantly. Auto-send on WABA needs an approved template.') }}</div>
        </div>
    </div>

    <button type="submit" class="w-full py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[13px] font-semibold">{{ __('Save settings') }}</button>
</form>

<template data-extra-tpl>
    <div class="flex items-center gap-2" data-extra-row>
        <input name="extra_label[]" placeholder="{{ __('Label e.g. PAN') }}" class="w-40 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
        <input name="extra_value[]" placeholder="{{ __('Value') }}" class="flex-1 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
        <button type="button" data-remove-extra class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
    </div>
</template>
<script>
    (function(){
        const wrap=document.querySelector('[data-extra-rows]'), tpl=document.querySelector('[data-extra-tpl]');
        document.querySelector('[data-add-extra]')?.addEventListener('click',()=>wrap&&wrap.appendChild(tpl.content.firstElementChild.cloneNode(true)));
        document.addEventListener('click',e=>{const b=e.target.closest('[data-remove-extra]'); if(b) b.closest('[data-extra-row]')?.remove();});
    })();
</script>
