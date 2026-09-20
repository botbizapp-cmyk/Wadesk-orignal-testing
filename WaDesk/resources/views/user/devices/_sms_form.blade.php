{{--
 SMS connect form — connects an SMS number as a WaProviderConfig provider='sms'
 row (the same device/provider store WhatsApp uses). Posts to /connect/wa-store/sms.
 Twilio reuses the workspace's existing Twilio keys when the SID/token are left
 blank; MSG91 covers the Indian DLT route. No JS — all fields shown with hints.
--}}
<form method="POST" action="{{ url('/connect/wa-store/sms') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="return_to" value="{{ $smsReturnTo ?? '/sms' }}">

    <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
            <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('SMS provider') }}
                <span class="text-accent-coral">*</span></span>
            <select name="sms_provider"
                class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
                <option value="twilio" @selected(old('sms_provider', 'twilio') === 'twilio')>{{ __('Twilio (global)') }}</option>
                <option value="msg91" @selected(old('sms_provider') === 'msg91')>{{ __('MSG91 (India / DLT)') }}</option>
            </select>
            <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Twilio for most countries; MSG91 for reliable delivery into India.') }}</span>
        </label>
        <label class="block">
            <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('From number / Sender ID') }}
                <span class="text-accent-coral">*</span></span>
            <input name="from_number" maxlength="32" placeholder="+17372508034"
                value="{{ old('from_number') }}"
                class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
            <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('A +E.164 SMS number (Twilio) or your registered sender (MSG91). An alphanumeric sender is one-way — no replies.') }}</span>
        </label>
    </div>

    <div class="rounded-xl border border-paper-200 p-4 space-y-4">
        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Credentials') }}</div>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Twilio Account SID') }}</span>
                <input name="account_sid" maxlength="64" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                    value="{{ old('account_sid') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Twilio only. Leave blank to reuse the Twilio keys you already connected for WhatsApp.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Auth Token / MSG91 Auth Key') }}</span>
                <input name="auth_token" type="password" maxlength="255"
                    placeholder="{{ __('paste token or auth key') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('Twilio Auth Token, or your MSG91 Auth Key. Encrypted at rest. Leave blank (Twilio) to reuse existing keys.') }}</span>
            </label>
        </div>
    </div>

    <details class="rounded-xl border border-paper-200 px-4 py-3">
        <summary class="text-[12px] font-semibold text-ink-700 cursor-pointer">{{ __('MSG91 / India (DLT) options') }}</summary>
        <div class="grid md:grid-cols-2 gap-4 mt-3">
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('DLT Sender ID') }}</span>
                <input name="sender_id" maxlength="16" placeholder="{{ brand_token(6) }}" value="{{ old('sender_id') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('MSG91 only. Your 6-letter DLT-registered sender.') }}</span>
            </label>
            <label class="block">
                <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('DLT Template ID') }}</span>
                <input name="dlt_template_id" maxlength="64" value="{{ old('dlt_template_id') }}"
                    class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
                <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('MSG91 only. The approved DLT template id used for Indian delivery.') }}</span>
            </label>
        </div>
        @php $__smsBase = preg_replace('#^http://#i', 'https://', url('/')); @endphp
        <div class="mt-3 rounded-lg bg-paper-50 border border-paper-200 px-3 py-2.5 text-[11px] text-ink-600 space-y-1.5">
            <div class="font-semibold text-ink-700">{{ __('MSG91 panel — set these two webhook URLs to receive replies + delivery reports:') }}</div>
            <div class="flex items-center gap-2"><span class="font-mono text-[10px] text-ink-400 w-16 shrink-0">{{ __('Inbound') }}</span><code class="font-mono text-[10.5px] bg-white border border-paper-200 rounded px-2 py-1 flex-1 truncate">{{ $__smsBase }}/api/sms/inbound</code></div>
            <div class="flex items-center gap-2"><span class="font-mono text-[10px] text-ink-400 w-16 shrink-0">{{ __('DLR') }}</span><code class="font-mono text-[10.5px] bg-white border border-paper-200 rounded px-2 py-1 flex-1 truncate">{{ $__smsBase }}/api/sms/status</code></div>
            <div class="text-[10px] text-ink-400">{{ __('Twilio wires delivery reports automatically per message — no setup needed.') }}</div>
        </div>
    </details>

    <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
            <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Rate per segment') }}
                <span class="font-mono text-[10px] text-ink-500">{{ __('optional') }}</span></span>
            <input name="rate_per_segment" type="number" step="0.0001" min="0" placeholder="0.0075"
                value="{{ old('rate_per_segment') }}"
                class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
            <span class="text-[10.5px] text-ink-500 mt-1 block">{{ __('For your SMS spend reporting only — never billed to the WhatsApp wallet.') }}</span>
        </label>
        <label class="block">
            <span class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Currency') }}</span>
            <input name="currency" maxlength="8" placeholder="USD" value="{{ old('currency', 'USD') }}"
                class="w-full px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10" />
        </label>
    </div>

    @error('account_sid')
        <div class="px-3 py-2 rounded-lg bg-accent-coral/10 border border-accent-coral/25 text-[12px] text-accent-coral">{{ $message }}</div>
    @enderror
    @error('from_number')
        <div class="px-3 py-2 rounded-lg bg-accent-coral/10 border border-accent-coral/25 text-[12px] text-accent-coral">{{ $message }}</div>
    @enderror

    <div class="flex items-center justify-end gap-2 pt-2 border-t border-paper-100">
        <button type="submit"
            class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">
            {{ __('Verify + connect SMS') }}
        </button>
    </div>
</form>
