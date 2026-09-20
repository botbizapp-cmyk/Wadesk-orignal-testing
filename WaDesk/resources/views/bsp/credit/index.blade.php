<x-layouts.admin :title="__('Meta credit & keys')" admin-key="bsp-credit" page="bsp-credit">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ route('admin.bsp.dashboard') }}" class="hover:text-ink-900">{{ __('Billing') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Meta credit & keys') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-7 py-7 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    {{ __('Reseller billing · Meta credit line') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[40px] leading-[1.0]">
                    {{ __('Connect your') }} <span class="italic text-wa-deep">{{ __('Meta') }}</span> {{ __('keys') }}.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Attach your own Meta credit line to a customer\'s WhatsApp account so Meta bills you (the reseller) — not them. Follow the two steps below; the guide on the right shows exactly where to get each value.') }}
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 pb-1">
                <a href="{{ route('admin.bsp.dashboard') }}"
                    class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Message income') }}</a>
                <a href="{{ route('admin.settings.wallet-rules') }}"
                    class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Message pricing') }}</a>
            </div>
        </div>

        <x-admin.flash />

        @foreach (['success' => 'wa-green', 'error' => 'accent-coral'] as $k => $c)
            @if (session($k))
                <div class="rounded-xl border border-{{ $c }}/40 bg-{{ $c }}/10 text-[13px] px-4 py-3 {{ $k === 'success' ? 'text-wa-deep' : 'text-accent-coral' }}">{{ session($k) }}</div>
            @endif
        @endforeach

        {{-- Do you even need this page? --}}
        <div class="rounded-2xl border border-accent-amber/40 bg-accent-amber/10 p-4 flex items-start gap-3 text-[12.5px] text-ink-700 leading-relaxed">
            <svg viewBox="0 0 16 16" class="w-4 h-4 text-accent-amber shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M8 2v6M8 11v.5" /><circle cx="8" cy="8" r="6.5" />
            </svg>
            <span>
                <strong>{{ __('Optional — only for true reselling.') }}</strong>
                {{ __('You only need this if you want Meta to bill YOU for your customers\' messages. It requires Meta "Solution Partner" status + an approved Meta credit line. Until that\'s approved, everything still works: customers top up their wallet and you charge per message on') }}
                <a href="{{ route('admin.settings.wallet-rules') }}" class="text-wa-deep font-medium hover:underline">{{ __('Message pricing') }}</a>.
            </span>
        </div>

        @php
            // Currency is dynamic — pulled from the platform's own currencies,
            // nothing hardcoded. Default the picker to the platform currency.
            $metaCurrencies = \App\Models\Currency::query()->orderBy('code')
                ->pluck('code')->map(fn ($c) => strtoupper($c))->filter()->unique()->values()->all();
            if (empty($metaCurrencies)) {
                $metaCurrencies = [strtoupper(\App\Support\FormatSettings::currencyFor()?->code ?? 'USD')];
            }
            $platformCur = strtoupper(\App\Support\FormatSettings::currencyFor()?->code ?? 'USD');
            $defaultCur = in_array($platformCur, $metaCurrencies, true) ? $platformCur : ($metaCurrencies[0] ?? 'USD');
        @endphp

        <section class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-5 items-start">

            {{-- ══════════════ LEFT: the two steps ══════════════ --}}
            <div class="space-y-5 min-w-0">

                {{-- STEP 1 — Connect --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200 flex items-center gap-3">
                        <span class="w-7 h-7 rounded-full bg-wa-deep text-paper-0 grid place-items-center font-serif text-[14px] shrink-0">1</span>
                        <div class="flex-1">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('meta connection') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-0.5">{{ __('Connect your Meta business') }}</h2>
                        </div>
                        <span class="text-[11px] px-2.5 py-1 rounded-full shrink-0 {{ $configured ? 'bg-wa-green/20 text-wa-deep' : 'bg-accent-amber/20 text-[#7B5A14]' }}">
                            {{ $configured ? __('Connected') : __('Not connected') }}
                        </span>
                    </div>
                    <form method="POST" action="{{ route('admin.bsp.credit.settings') }}" class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @csrf
                        <label class="space-y-1.5">
                            <span class="text-[11.5px] font-semibold">{{ __('Meta Business ID') }} <span class="text-accent-coral">*</span></span>
                            <input name="bsp_meta_business_id" value="{{ $businessId }}" placeholder="123456789012345"
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            <span class="text-[11px] text-ink-500">{{ __('Business settings → Business info → "Business portfolio ID". A long number.') }}</span>
                        </label>

                        <label class="space-y-1.5">
                            <span class="text-[11.5px] font-semibold">{{ __('System-user token') }} <span class="text-accent-coral">*</span></span>
                            <input name="bsp_meta_system_user_token" type="password" autocomplete="off"
                                placeholder="{{ $tokenSet ? '••• '.__('stored, leave blank to keep') : __('paste token') }}"
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            <span class="text-[11px] text-ink-500">{{ __('Needs the') }} <span class="font-mono">business_management</span> {{ __('permission. Stored encrypted, never shown back.') }}</span>
                        </label>

                        <label class="space-y-1.5">
                            <span class="text-[11.5px] font-semibold">{{ __('Extended credit line ID') }} <span class="text-ink-400 font-normal">({{ __('auto if blank') }})</span></span>
                            <input name="bsp_meta_extended_credit_id" value="{{ $creditId }}" placeholder="{{ __('auto-detected from your business') }}"
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            <span class="text-[11px] text-ink-500">{{ __('Leave blank — we read it from') }} <span class="font-mono">GET /{business}/extendedcredits</span>. {{ __('Set it only to override.') }}</span>
                        </label>

                        <label class="space-y-1.5">
                            <span class="text-[11.5px] font-semibold">{{ __('Graph API version') }}</span>
                            <input name="bsp_graph_version" value="{{ $graphVersion }}" placeholder="v25.0" pattern="v\d{1,2}\.\d{1,2}"
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            <span class="text-[11px] text-ink-500">{{ __('Default') }} <span class="font-mono">v25.0</span>. {{ __('Bump only after verifying compatibility.') }}</span>
                        </label>

                        <label class="space-y-1.5 sm:col-span-2">
                            <span class="text-[11.5px] font-semibold">{{ __('Meta rates endpoint') }} <span class="text-ink-400 font-normal">({{ __('optional — live cost pull') }})</span></span>
                            <input name="bsp_meta_rates_endpoint" type="url" value="{{ $ratesEndpoint }}" placeholder="https://graph.facebook.com/v25.0/…/pricing"
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            <span class="text-[11px] text-ink-500">{{ __('Optional. When set, "Sync Meta costs" on Message pricing pulls wholesale rates from here. Blank = use the bundled rate card / uploaded CSV.') }}</span>
                        </label>

                        {{-- Hands-off mode — share the credit line automatically the
                             moment any customer connects a number, so nobody attaches
                             by hand. This is what makes it "customer just tops up and
                             sends, Meta bills me". --}}
                        <div class="sm:col-span-2 rounded-xl border-2 {{ ($autoAttach ?? false) ? 'border-wa-deep bg-wa-deep/5' : 'border-paper-200 bg-paper-50/50' }} p-4">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="bsp_auto_attach_credit" value="1" class="peer sr-only" @checked($autoAttach ?? false)>
                                <span class="mt-0.5 w-11 h-6 shrink-0 rounded-full bg-paper-200 peer-checked:bg-wa-deep relative transition
                                    after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5
                                    after:rounded-full after:bg-paper-0 after:transition peer-checked:after:translate-x-5"></span>
                                <span class="flex-1">
                                    <span class="font-serif text-[16px] text-ink-900">{{ __('Auto-attach when a customer connects') }}</span>
                                    <span class="block text-[12px] text-ink-600 mt-1 leading-relaxed">
                                        {{ __('ON (recommended): the moment any customer connects a WhatsApp number, your credit line is shared to it automatically — you never pick customers by hand. OFF: you attach each one yourself in step 2.') }}
                                    </span>
                                </span>
                                <span class="shrink-0">
                                    <span class="block text-[10px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Bill in') }}</span>
                                    <select name="bsp_auto_attach_currency"
                                        class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px]">
                                        <option value="">{{ __('Auto') }}</option>
                                        @foreach ($metaCurrencies as $cur)
                                            <option value="{{ $cur }}" @selected($cur === ($autoAttachCurrency ?? ''))>{{ $cur }}</option>
                                        @endforeach
                                    </select>
                                </span>
                            </label>
                        </div>

                        <div class="sm:col-span-2 flex items-center gap-3 pt-1">
                            <button class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save connection') }}</button>
                            <span class="text-[11px] text-ink-500">{{ __('The token is stored encrypted and never shown back.') }}</span>
                        </div>
                    </form>
                </section>

                {{-- STEP 2 — Attach --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden {{ $configured ? '' : 'opacity-60' }}">
                    <div class="px-5 py-4 border-b border-paper-200 flex flex-wrap items-center gap-3">
                        <span class="w-7 h-7 rounded-full {{ $configured ? 'bg-wa-deep' : 'bg-paper-300' }} text-paper-0 grid place-items-center font-serif text-[14px] shrink-0">2</span>
                        <div class="flex-1 min-w-0">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('attach manually — optional') }}</div>
                            <h2 class="font-serif text-[22px] leading-tight mt-0.5">{{ __('Attach one customer by hand') }}</h2>
                            <p class="text-[11.5px] text-ink-500 mt-0.5">
                                {{ ($autoAttach ?? false)
                                    ? __('Auto-attach is ON, so new customers attach themselves — use this only for a one-off.')
                                    : __('Or turn on auto-attach above and skip this entirely.') }}
                            </p>
                        </div>
                        @if ($configured)
                            <form method="POST" action="{{ route('admin.bsp.credit.attach-all') }}" class="shrink-0"
                                onsubmit="return confirm('{{ __('Attach your credit line to every connected number that is not attached yet?') }}')">
                                @csrf
                                <button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full hairline border border-wa-deep text-wa-deep text-[12px] font-semibold hover:bg-wa-deep/5">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M13 8a5 5 0 1 1-1.46-3.54M13 3v2.5h-2.5"/></svg>
                                    {{ __('Attach all connected now') }}
                                </button>
                            </form>
                        @endif
                    </div>
                    @if (empty($wabaAccounts))
                        <div class="p-5">
                            <div class="rounded-xl border border-paper-200 bg-paper-50 px-4 py-5 text-center text-[12.5px] text-ink-600">
                                {{ __('No customer has connected a WhatsApp Business (Cloud API) number yet. Once a workspace connects one under') }}
                                <span class="font-mono">/devices</span>, {{ __('it will appear here to pick.') }}
                            </div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.bsp.credit.attach') }}" class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @csrf
                            <label class="space-y-1.5 sm:col-span-2">
                                <span class="text-[11.5px] font-semibold">{{ __("Customer's WhatsApp number") }} <span class="text-accent-coral">*</span></span>
                                <select name="account" required {{ $configured ? '' : 'disabled' }}
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('— Pick a connected number —') }}</option>
                                    @foreach ($wabaAccounts as $a)
                                        <option value="{{ $a['workspace_id'] }}|{{ $a['waba_id'] }}">{{ $a['label'] }}</option>
                                    @endforeach
                                </select>
                                <span class="text-[11px] text-ink-500">{{ __('Choose the customer by name — we handle the account IDs behind the scenes.') }}</span>
                            </label>

                            <label class="space-y-1.5">
                                <span class="text-[11.5px] font-semibold">{{ __('Bill this number in') }} <span class="text-accent-coral">*</span></span>
                                <select name="currency" required {{ $configured ? '' : 'disabled' }}
                                    class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                    @foreach ($metaCurrencies as $cur)
                                        <option value="{{ $cur }}" @selected($cur === $defaultCur)>{{ $cur }}</option>
                                    @endforeach
                                </select>
                                <span class="text-[11px] text-ink-500">{{ __('The currency Meta invoices you in for this number.') }}</span>
                            </label>

                            <div class="flex items-end">
                                <div class="rounded-xl border border-accent-coral/30 bg-accent-coral/5 px-3 py-2.5 text-[11px] text-ink-600 leading-snug w-full">
                                    <strong class="text-accent-coral">{{ __('Locked once attached.') }}</strong>
                                    {{ __('Meta does not let you change the currency later — set it right the first time.') }}
                                </div>
                            </div>

                            <div class="sm:col-span-2 pt-1">
                                <button {{ $configured ? '' : 'disabled' }}
                                    class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal disabled:opacity-50">{{ __('Attach credit line') }}</button>
                                @unless ($configured)
                                    <span class="ml-2 text-[11px] text-ink-500">{{ __('Finish step 1 first.') }}</span>
                                @endunless
                            </div>
                        </form>
                    @endif
                </section>

                {{-- Attached accounts --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('attached accounts') }}</div>
                        <h2 class="font-serif text-[22px] leading-tight mt-0.5">{{ __('Credit lines in use') }}</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[13px]">
                            <thead>
                                <tr class="text-left font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 border-b border-paper-200">
                                    <th class="px-5 py-2.5">{{ __('Workspace') }}</th>
                                    <th class="px-4 py-2.5">{{ __('WABA') }}</th>
                                    <th class="px-4 py-2.5">{{ __('Alloc ID') }}</th>
                                    <th class="px-4 py-2.5">{{ __('Currency') }}</th>
                                    <th class="px-4 py-2.5">{{ __('Status') }}</th>
                                    <th class="px-4 py-2.5 text-right">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $r)
                                    <tr class="border-b border-paper-100">
                                        <td class="px-5 py-2.5">{{ $workspaceNames[$r->workspace_id] ?? ('#' . $r->workspace_id) }}</td>
                                        <td class="px-4 py-2.5 font-mono text-[11px]">…{{ substr($r->waba_id, -6) }}</td>
                                        <td class="px-4 py-2.5 font-mono text-[11px] text-ink-500">{{ $r->allocation_config_id ?: '—' }}</td>
                                        <td class="px-4 py-2.5">{{ $r->currency }}</td>
                                        <td class="px-4 py-2.5">
                                            @php $sc = ['attached'=>'bg-wa-green/20 text-wa-deep','revoked'=>'bg-paper-100 text-ink-500','failed'=>'bg-accent-coral/20 text-accent-coral'][$r->status] ?? 'bg-paper-100'; @endphp
                                            <span class="text-[11px] px-2 py-0.5 rounded-full {{ $sc }}">{{ $r->status }}</span>
                                            @if ($r->last_error)<span class="text-[10.5px] text-accent-coral ml-1" title="{{ $r->last_error }}">!</span>@endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            @if ($r->status === 'attached')
                                                <form method="POST" action="{{ route('admin.bsp.credit.revoke', $r->id) }}" class="inline" onsubmit="return confirm('{{ __('Revoke this credit allocation?') }}')">
                                                    @csrf
                                                    <button class="px-2.5 py-1 rounded-full border border-accent-coral/40 text-accent-coral text-[11px] hover:bg-accent-coral/10">{{ __('Revoke') }}</button>
                                                </form>
                                            @else
                                                <span class="text-ink-400 text-[11px]">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-5 py-8 text-center text-ink-500 text-[13px]">{{ __('No accounts attached yet. Finish steps 1 and 2 above.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($rows->hasPages())
                        <div class="px-5 py-3 border-t border-paper-200">{{ $rows->links() }}</div>
                    @endif
                </section>
            </div>

            {{-- ══════════════ RIGHT: setup guide with official links ══════════════ --}}
            <aside class="space-y-4 lg:sticky lg:top-[88px]">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-4 py-3 border-b border-paper-200">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Setup guide') }}</div>
                        <h3 class="font-serif text-[18px] leading-tight mt-0.5">{{ __('Where to get each value') }}</h3>
                    </div>
                    <div class="p-4 text-[12px] text-ink-700">
                        <ol class="space-y-4">
                            <li>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="w-5 h-5 rounded-full bg-wa-deep/10 text-wa-deep grid place-items-center font-mono text-[10px] shrink-0">1</span>
                                    <div class="font-semibold text-[12.5px] text-ink-900">{{ __('Business ID') }}</div>
                                </div>
                                <p class="text-ink-600 pl-7">
                                    {{ __('Open') }}
                                    <a href="https://business.facebook.com/settings/info" target="_blank" rel="noopener" class="text-wa-deep underline">business.facebook.com</a>
                                    → {{ __('Business settings → Business info. Copy the "Business portfolio ID".') }}
                                </p>
                            </li>
                            <li>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="w-5 h-5 rounded-full bg-wa-deep/10 text-wa-deep grid place-items-center font-mono text-[10px] shrink-0">2</span>
                                    <div class="font-semibold text-[12.5px] text-ink-900">{{ __('System-user token') }}</div>
                                </div>
                                <div class="text-ink-600 pl-7 space-y-1">
                                    <p>{{ __('Business settings →') }} <span class="font-medium">{{ __('Users → System users') }}</span> → {{ __('Add → role') }} <span class="font-mono">Admin</span>.</p>
                                    <p>{{ __('Then') }} <span class="font-medium">{{ __('Generate token') }}</span> {{ __('with these permissions:') }}</p>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <span class="px-2 py-0.5 rounded-full bg-wa-deep/10 text-wa-deep font-mono text-[10px]">business_management</span>
                                        <span class="px-2 py-0.5 rounded-full bg-wa-deep/10 text-wa-deep font-mono text-[10px]">whatsapp_business_management</span>
                                        <span class="px-2 py-0.5 rounded-full bg-wa-deep/10 text-wa-deep font-mono text-[10px]">whatsapp_business_messaging</span>
                                    </div>
                                    <p class="text-[11px] text-ink-500 mt-1">{{ __('The token shows once — copy it immediately.') }}
                                        <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/access-tokens/" target="_blank" rel="noopener" class="text-wa-deep underline">{{ __('Meta docs') }}</a>
                                    </p>
                                </div>
                            </li>
                            <li>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="w-5 h-5 rounded-full bg-wa-deep/10 text-wa-deep grid place-items-center font-mono text-[10px] shrink-0">3</span>
                                    <div class="font-semibold text-[12.5px] text-ink-900">{{ __('Credit line') }}</div>
                                </div>
                                <div class="text-ink-600 pl-7 space-y-1">
                                    <p>{{ __('You need Meta') }} <span class="font-medium">{{ __('Solution Partner') }}</span> {{ __('status + an approved credit line. Apply once with Meta:') }}</p>
                                    <p>
                                        <a href="https://www.facebook.com/business/help/1684730811624773" target="_blank" rel="noopener" class="text-wa-deep underline">{{ __('Apply for a credit line') }}</a>
                                    </p>
                                    <p class="text-[11px] text-ink-500">{{ __('Once approved, :brand reads its ID automatically and step 2 shares it with each customer WABA.', ['brand' => brand_name()]) }}
                                        <a href="https://developers.facebook.com/docs/whatsapp/embedded-signup/manage-accounts/share-and-revoke-credit-lines/" target="_blank" rel="noopener" class="text-wa-deep underline">{{ __('Meta docs') }}</a>
                                    </p>
                                </div>
                            </li>
                        </ol>

                        <div class="mt-4 pt-3 border-t border-paper-100 text-[11px] text-ink-500 leading-relaxed">
                            <div class="font-semibold text-ink-700 mb-1">{{ __('Good to know') }}</div>
                            <ul class="space-y-1 list-disc pl-4">
                                <li>{{ __('Currency is locked by Meta once attached.') }}</li>
                                <li>{{ __('Attaching = Meta bills YOU; you still set customer prices on') }}
                                    <a href="{{ route('admin.settings.wallet-rules') }}" class="text-wa-deep underline">{{ __('Message pricing') }}</a>.</li>
                                <li>{{ __('Revoke any time — the customer then pays Meta directly again.') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/overview" target="_blank" rel="noopener"
                    class="block rounded-2xl border border-paper-200 bg-paper-50 hover:bg-paper-0 p-4 transition">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('reference') }}</div>
                    <div class="font-serif text-[15px] leading-tight mt-0.5 text-ink-900">{{ __('Meta Solution Partner overview') }}</div>
                    <div class="text-[11px] text-ink-500 mt-1 inline-flex items-center gap-1">
                        developers.facebook.com
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3h7v7M13 3 4 12" /></svg>
                    </div>
                </a>
            </aside>
        </section>
    </main>
</x-layouts.admin>
