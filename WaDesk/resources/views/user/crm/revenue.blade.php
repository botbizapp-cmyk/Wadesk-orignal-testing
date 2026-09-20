@php
    use App\Models\Currency;
    $sym = Currency::symbolFor($currency);
    $md = fn ($minor) => $sym . number_format(((int) $minor) / 100, 2);
    $agB = $aging['buckets'] ?? [];
    $maxMonth = max(1, collect($collectedByMonth)->max('minor'));
@endphp
<x-layouts.user :title="__('Revenue report')" nav-key="more" page="user-crm-revenue">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('CRM') }} · {{ __('Reports') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('Revenue') }} <span class="italic text-wa-deep">{{ __('report') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2">{{ __('Generated') }} {{ $generatedAt }}.</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.crm.revenue.csv') }}" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Export CSV') }}</a>
                <a href="{{ route('user.crm.revenue.pdf') }}" target="_blank" rel="noopener" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Export PDF') }}</a>
            </div>
        </div>

        @if ($mixedCurrency)
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-2.5 text-[12px] text-amber-800">
                {{ __('Payments span more than one currency — totals below are summed in raw amounts and shown in the workspace currency. Filter to a single currency for exact figures.') }}
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ([
                ['Collected (30d)', $md($collected30), 'text-wa-deep'],
                ['Collected (all)', $md($collectedAll), 'text-ink-900'],
                ['Outstanding', $md($outstanding), 'text-accent-coral'],
                ['Tax collected', $md($taxCollected), 'text-ink-900'],
            ] as [$label, $val, $col])
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                    <div class="text-[10.5px] uppercase tracking-wide text-ink-500 font-semibold">{{ __($label) }}</div>
                    <div class="text-[22px] font-serif mt-1 {{ $col }}">{{ $val }}</div>
                </div>
            @endforeach
        </div>

        {{-- Collected by month --}}
        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <div class="text-[13px] font-semibold text-ink-900 mb-4">{{ __('Collected — last 6 months') }}</div>
            <div class="flex items-end gap-3 h-40">
                @foreach ($collectedByMonth as $mo)
                    <div class="flex-1 flex flex-col items-center gap-1.5">
                        <div class="text-[10px] font-mono text-ink-500">{{ $md($mo['minor']) }}</div>
                        <div class="w-full rounded-t-md bg-wa-deep/80" style="height: {{ max(2, round(($mo['minor'] / $maxMonth) * 120)) }}px"></div>
                        <div class="text-[10px] text-ink-400">{{ $mo['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Aging --}}
        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('Receivables aging') }} <span class="text-[11px] font-normal text-ink-400">({{ $aging['count'] ?? 0 }} {{ __('invoices') }})</span></div>
            <div class="grid grid-cols-4 gap-2">
                @foreach (['0-30','31-60','61-90','90+'] as $b)
                    <div class="rounded-xl border border-paper-200 p-3 text-center">
                        <div class="text-[10px] uppercase tracking-wide text-ink-500 font-semibold">{{ $b }}</div>
                        <div class="text-[13px] font-mono mt-1 {{ $b === '90+' ? 'text-accent-coral' : 'text-ink-900' }}">{{ $md($agB[$b] ?? 0) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </main>
</x-layouts.user>
