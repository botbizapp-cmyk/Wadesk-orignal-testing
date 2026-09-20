@php
    use App\Support\MoneyFormat;
    $srcLabel = ['own' => __('WhatsApp Store'), 'woocommerce' => __('WooCommerce'), 'shopify' => __('Shopify')][$source] ?? __('All stores');
    $allOk = collect($checklist)->every(fn ($c) => $c['ok']);
@endphp
<x-layouts.user :title="__('Invoices')" nav-key="more" page="user-invoices-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ $srcLabel }} · {{ __('Invoices') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Auto') }} <span class="italic text-wa-deep">{{ __('invoices') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('A numbered PDF invoice is generated for each paid order and delivered on WhatsApp — the customer taps a button to view or download it.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.invoices.create') }}" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">+ {{ __('New invoice') }}</a>
                <a href="{{ route('user.invoices.settings') }}" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Settings') }}</a>
            </div>
        </div>

        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 items-start">
            <div class="space-y-6 min-w-0">
                {{-- Setup checklist / warnings --}}
                @unless ($allOk)
                    <div class="bg-paper-0 border border-accent-amber/40 rounded-2xl shadow-card p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-6 h-6 rounded-full bg-accent-amber/20 text-[#7B5A14] grid place-items-center"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M8 5v4M8 11h.01"/></svg></span>
                            <div class="font-serif text-[16px]">{{ __('Finish setup to auto-send') }}</div>
                        </div>
                        <ul class="space-y-2">
                            @foreach ($checklist as $c)
                                <li class="flex items-start gap-2 text-[12.5px]">
                                    <span class="mt-0.5 shrink-0 {{ $c['ok'] ? 'text-wa-deep' : 'text-accent-coral' }}">
                                        @if ($c['ok'])<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l3.5 3.5L13 4"/></svg>
                                        @else<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4l8 8M12 4l-8 8"/></svg>@endif
                                    </span>
                                    <span><span class="font-semibold {{ $c['ok'] ? 'text-ink-700' : 'text-ink-900' }}">{{ $c['label'] }}</span>@unless ($c['ok'])<span class="text-ink-500"> — {{ $c['hint'] }}</span>@endunless</span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('user.invoices.settings') }}" class="mt-3 inline-flex px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Open settings') }}</a>
                    </div>
                @endunless

                {{-- Generate from paid orders --}}
                @if ($orders->isNotEmpty())
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Paid orders without an invoice') }}</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($orders as $o)
                                <form method="POST" action="{{ route('user.invoices.store') }}">@csrf
                                    <input type="hidden" name="wa_order_id" value="{{ $o->id }}">
                                    <button class="px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:border-wa-deep hover:bg-wa-deep/5 text-[12px] font-medium">{{ __('Generate for') }} #{{ $o->id }} · {{ MoneyFormat::display((int) $o->total_minor, $o->currency_code ?: 'USD') }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Invoice list --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    @if ($invoices->isEmpty())
                        <div class="px-5 py-12 text-center text-[12.5px] text-ink-500">{{ __('No invoices yet. Generate one from a paid order above, or turn on auto-send in Settings.') }}</div>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 text-left font-mono text-[10.5px] uppercase text-ink-500">
                                <tr><th class="px-4 py-2.5">{{ __('Number') }}</th><th class="px-4 py-2.5">{{ __('Customer') }}</th><th class="px-4 py-2.5">{{ __('Type') }}</th><th class="px-4 py-2.5">{{ __('Total') }}</th><th class="px-4 py-2.5">{{ __('Delivery') }}</th><th class="px-4 py-2.5 text-right"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-paper-100">
                                @foreach ($invoices as $inv)
                                    @php $sc = match($inv->send_status){ 'sent'=>'bg-wa-green/15 text-wa-deep','send_failed'=>'bg-accent-coral/10 text-accent-coral','ready','rendering','pending'=>'bg-accent-amber/20 text-[#7B5A14]',default=>'bg-paper-100 text-ink-600' }; @endphp
                                    <tr class="hover:bg-paper-50">
                                        <td class="px-4 py-3 font-mono text-[11.5px]"><a href="{{ route('user.invoices.show', $inv->id) }}" class="text-wa-deep hover:underline">{{ $inv->invoice_number }}</a></td>
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
            </div>

            {{-- How it works --}}
            <aside class="lg:sticky lg:top-6 space-y-4">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('How it works') }}</div>
                    <ol class="space-y-2.5 text-[12.5px] text-ink-700 list-none">
                        <li class="flex gap-2"><span class="font-mono text-wa-deep font-bold">1</span> {{ __('Fill your company details, tax number, logo & signature in Settings.') }}</li>
                        <li class="flex gap-2"><span class="font-mono text-wa-deep font-bold">2</span> {{ __('Pick a WhatsApp sender and click “Create & submit invoice template”.') }}</li>
                        <li class="flex gap-2"><span class="font-mono text-wa-deep font-bold">3</span> {{ __('Turn on auto-send for this store.') }}</li>
                        <li class="flex gap-2"><span class="font-mono text-wa-deep font-bold">4</span> {{ __('Every paid order → numbered PDF → delivered on WhatsApp automatically.') }}</li>
                    </ol>
                </div>
                <div class="bg-wa-bubble/40 border border-wa-green/30 rounded-2xl p-4 text-[11.5px] text-ink-700 leading-relaxed">
                    <div class="font-semibold text-ink-900 mb-1">{{ __('Good to know') }}</div>
                    <ul class="space-y-1.5">
                        <li>• {{ __('WABA sends a template with a button to the PDF — no 24-hour window limit.') }}</li>
                        <li>• {{ __('Unofficial (Baileys) sends the PDF file directly — no template needed.') }}</li>
                        <li>• {{ __('A taxless order becomes a Receipt; a taxed order a Tax Invoice.') }}</li>
                        <li>• {{ __('Failed sends show a reason and retry automatically; you can also resend manually.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </main>
</x-layouts.user>
