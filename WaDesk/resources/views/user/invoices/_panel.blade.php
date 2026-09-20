@php
    /**
     * Self-contained invoices panel — embed inside any store dashboard's tab
     * content. Pass $panelSource = 'own' | 'woocommerce' | 'shopify'.
     * Computes its own data so no host controller change is needed. Sub-views
     * (list / detail / settings) switch on ?iv= WITHOUT leaving the dashboard —
     * links preserve the current URL via fullUrlWithQuery().
     */
    use App\Support\MoneyFormat;
    $panelSource = $panelSource ?? 'own';
    $__ws = (int) (auth()->user()->current_workspace_id ?? 0);
    $__iv = request('iv');
    $__url = fn ($iv) => request()->fullUrlWithQuery(['iv' => $iv]);
@endphp

@if ($__iv === 'settings')
    {{-- Settings — rendered IN the dashboard tab --}}
    <div class="space-y-4">
        <a href="{{ $__url(null) }}" class="inline-flex items-center gap-1.5 text-[12px] text-wa-deep font-semibold hover:underline"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 3L5 8l5 5"/></svg>{{ __('Back to invoices') }}</a>
        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error') || $errors->any())<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>@endif
        @include('user.invoices._settings_form')
    </div>
@elseif (is_numeric($__iv) && ($__inv = \App\Models\Invoice::forWorkspace($__ws)->find((int) $__iv)))
    {{-- Invoice detail — rendered IN the dashboard tab --}}
    <div class="space-y-4">
        <a href="{{ $__url(null) }}" class="inline-flex items-center gap-1.5 text-[12px] text-wa-deep font-semibold hover:underline"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 3L5 8l5 5"/></svg>{{ __('Back to invoices') }}</a>
        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif
        @include('user.invoices._detail', ['invoice' => $__inv])
    </div>
@else
@php
    $__ws = (int) (auth()->user()->current_workspace_id ?? 0);
    $__set = \App\Models\InvoiceSetting::forWorkspace($__ws);
    $__invQ = \App\Models\Invoice::forWorkspace($__ws)->orderByDesc('id');
    $__invQ->when($panelSource === 'own', fn ($q) => $q->whereIn('source', ['own', 'manual']), fn ($q) => $q->where('source', $panelSource));
    $__invoices = $__invQ->limit(50)->get();
    $__storeSources = $panelSource === 'own' ? ['own', 'storefront', 'waba', 'twilio', 'whatsapp_ai', 'manual'] : [$panelSource];
    $__orders = \App\Models\WaOrder::where('workspace_id', $__ws)->whereIn('status', ['paid', 'completed'])
        ->whereIn('source', $__storeSources)->whereDoesntHave('invoice')->orderByDesc('id')->limit(20)->get();
    $__isWaba = str_starts_with((string) $__set->send_sender, 'waba:');
    $__autoKey = 'auto_send_'.($panelSource === 'own' ? 'own' : $panelSource);
    $__checklist = [
        ['label' => __('Invoices enabled'), 'ok' => (bool) $__set->enabled],
        ['label' => __('Company name & tax number set'), 'ok' => filled($__set->seller_name)],
        ['label' => __('WhatsApp sender connected'), 'ok' => filled($__set->send_sender)],
        ['label' => $__isWaba ? __('Meta template approved') : __('Delivery ready'), 'ok' => ! $__isWaba || $__set->template_status === 'approved'],
        ['label' => __('Auto-send on for this store'), 'ok' => (bool) ($__set->$__autoKey ?? false)],
    ];
    $__allOk = collect($__checklist)->every(fn ($c) => $c['ok']);
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <div class="font-serif text-[20px] leading-tight">{{ __('Invoices') }}</div>
            <p class="text-[12px] text-ink-500 mt-0.5">{{ __('A numbered PDF is generated per paid order and delivered on WhatsApp.') }}</p>
        </div>
        <a href="{{ $__url('settings') }}" class="px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold shrink-0">{{ __('Invoice settings') }}</a>
    </div>

    @unless ($__allOk)
        <div class="bg-paper-0 border border-accent-amber/40 rounded-2xl p-4">
            <div class="flex items-center gap-2 mb-2 text-[13px] font-semibold"><span class="w-5 h-5 rounded-full bg-accent-amber/20 text-[#7B5A14] grid place-items-center"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 5v4M8 11h.01"/></svg></span>{{ __('Finish setup to auto-send') }}</div>
            <ul class="space-y-1.5">
                @foreach ($__checklist as $c)
                    <li class="flex items-center gap-2 text-[12px]">
                        <span class="{{ $c['ok'] ? 'text-wa-deep' : 'text-accent-coral' }}">@if ($c['ok'])<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l3.5 3.5L13 4"/></svg>@else<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4l8 8M12 4l-8 8"/></svg>@endif</span>
                        <span class="{{ $c['ok'] ? 'text-ink-600' : 'text-ink-900 font-medium' }}">{{ $c['label'] }}</span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('user.invoices.settings') }}" class="mt-2.5 inline-flex px-3 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold">{{ __('Open settings') }}</a>
        </div>
    @endunless

    @if ($__orders->isNotEmpty())
        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-3.5">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Paid orders without an invoice') }}</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($__orders as $o)
                    <form method="POST" action="{{ route('user.invoices.store') }}">@csrf
                        <input type="hidden" name="wa_order_id" value="{{ $o->id }}">
                        <button class="px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:border-wa-deep hover:bg-wa-deep/5 text-[12px] font-medium">{{ __('Generate') }} #{{ $o->id }} · {{ MoneyFormat::display((int) $o->total_minor, $o->currency_code ?: 'USD') }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden">
        @if ($__invoices->isEmpty())
            <div class="px-5 py-10 text-center text-[12.5px] text-ink-500">{{ __('No invoices yet. Generate one from a paid order above, or enable auto-send in settings.') }}</div>
        @else
            <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="bg-paper-50 text-left font-mono text-[10.5px] uppercase text-ink-500">
                    <tr><th class="px-4 py-2.5">{{ __('Number') }}</th><th class="px-4 py-2.5">{{ __('Customer') }}</th><th class="px-4 py-2.5">{{ __('Type') }}</th><th class="px-4 py-2.5">{{ __('Total') }}</th><th class="px-4 py-2.5">{{ __('Delivery') }}</th><th class="px-4 py-2.5 text-right"></th></tr>
                </thead>
                <tbody class="divide-y divide-paper-100">
                    @foreach ($__invoices as $inv)
                        @php $sc = match($inv->send_status){ 'sent'=>'bg-wa-green/15 text-wa-deep','send_failed'=>'bg-accent-coral/10 text-accent-coral','ready','rendering','pending'=>'bg-accent-amber/20 text-[#7B5A14]',default=>'bg-paper-100 text-ink-600' }; @endphp
                        <tr class="hover:bg-paper-50">
                            <td class="px-4 py-3 font-mono text-[11.5px]"><a href="{{ $__url($inv->id) }}" class="text-wa-deep hover:underline">{{ $inv->invoice_number }}</a></td>
                            <td class="px-4 py-3">{{ $inv->buyer_name ?: '—' }}</td>
                            <td class="px-4 py-3"><span class="font-mono text-[10px] uppercase px-2 py-0.5 rounded-full bg-paper-100">{{ str_replace('_',' ',$inv->doc_type) }}</span></td>
                            <td class="px-4 py-3 font-semibold">{{ $inv->total_display }}</td>
                            <td class="px-4 py-3"><span class="font-mono text-[10px] uppercase px-2 py-0.5 rounded-full {{ $sc }}" title="{{ $inv->send_reason }}">{{ str_replace('_',' ',$inv->send_status) }}</span></td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('user.invoices.pdf', $inv->id) }}" target="_blank" class="text-[11px] text-wa-deep font-semibold hover:underline">PDF</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="bg-wa-bubble/40 border border-wa-green/30 rounded-2xl p-3.5 text-[11.5px] text-ink-700 leading-relaxed">
        <span class="font-semibold text-ink-900">{{ __('How it works:') }}</span>
        {{ __('Set company details + tax number, logo & signature in settings → connect a WhatsApp sender and create the invoice template → turn on auto-send → every paid order becomes a numbered PDF delivered on WhatsApp. WABA uses a button-to-PDF template (no 24h limit); Unofficial sends the file directly.') }}
    </div>
</div>

@endif
