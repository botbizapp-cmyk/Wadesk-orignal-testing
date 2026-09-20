<x-layouts.admin :title="__('BSP Dashboard')" admin-key="bsp" page="bsp-dashboard">

    @php
        $sym = $symbol ?: '';
        $cur = fn ($m) => $sym . number_format(((int) $m) / 100, 2, '.', ',');
    @endphp

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3"/></svg>
            <span class="text-ink-900">{{ __('BSP Billing') }}</span>
        </div>
        <nav class="ml-auto flex items-center gap-1.5 text-[12px]">
            <a href="{{ route('admin.bsp.dashboard') }}" class="px-3 py-1.5 rounded-full bg-wa-deep text-paper-0 font-medium">{{ __('P&L') }}</a>
            <a href="{{ route('admin.settings.wallet-rules') }}" class="px-3 py-1.5 rounded-full hairline border border-paper-200 hover:bg-paper-50">{{ __('Rates & margin') }}</a>
            <a href="{{ route('admin.bsp.credit.index') }}" class="px-3 py-1.5 rounded-full hairline border border-paper-200 hover:bg-paper-50">{{ __('Connect Meta') }}</a>
        </nav>
    </header>

    <main class="px-4 sm:px-7 py-7 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('This month') }} · {{ $totals['from']->format('d M') }} – {{ $totals['to']->format('d M') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[40px] leading-[1.0]">
                    {{ __('Reseller') }} <span class="italic text-wa-deep">{{ __('P&L') }}</span>.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Every billed message is a wallet charge. Revenue is what your customers paid; Meta cost is what Meta bills you; the gap is your margin. Prices & costs are set on') }}
                    <a href="{{ route('admin.settings.wallet-rules') }}" class="text-wa-deep font-medium hover:underline">{{ __('Wallet rules') }}</a>.
                </p>
            </div>
        </div>

        {{-- P&L KPIs --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @php
                $kpis = [
                    ['Revenue (charged)', $cur($totals['revenue_minor']), 'text-ink-900'],
                    ['Meta cost', $cur($totals['cost_minor']), 'text-ink-700'],
                    ['Margin (profit)', $cur($totals['margin_minor']), 'text-wa-deep'],
                    ['Wallet float held', $cur($totals['wallet_float_minor']), 'text-ink-900'],
                ];
            @endphp
            @foreach ($kpis as [$label, $val, $cls])
                <div class="rounded-xl border border-paper-200 bg-paper-0 px-4 py-3">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __($label) }}</div>
                    <div class="text-[22px] font-serif mt-1 tabular-nums {{ $cls }}">{{ $val }}</div>
                </div>
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-paper-200 bg-paper-0 px-4 py-2.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500">{{ __('Billed messages') }}</div>
                <div class="text-[18px] font-serif tabular-nums">{{ number_format($totals['messages']) }}</div>
            </div>
            <div class="rounded-xl border {{ $totals['unpriced_msgs'] > 0 ? 'border-accent-amber/40 bg-accent-amber/5' : 'border-paper-200 bg-paper-0' }} px-4 py-2.5">
                <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500">{{ __('No Meta cost set') }}</div>
                <div class="text-[18px] font-serif tabular-nums {{ $totals['unpriced_msgs'] > 0 ? 'text-accent-coral' : '' }}">{{ number_format($totals['unpriced_msgs']) }}</div>
            </div>
        </div>
        @if ($totals['unpriced_msgs'] > 0)
            <div class="rounded-xl border border-accent-amber/40 bg-accent-amber/5 px-4 py-3 text-[12px] text-ink-600">
                {{ __(':n billed message(s) had no Meta cost set for their country/category, so their margin is counted as pure revenue. Add those rows on', ['n' => number_format($totals['unpriced_msgs'])]) }}
                <a href="{{ route('admin.settings.wallet-rules') }}" class="text-wa-deep font-semibold hover:underline">{{ __('Wallet rules → Sync Meta costs') }}</a>.
            </div>
        @endif

        {{-- Per-workspace P&L --}}
        <div class="rounded-xl border border-paper-200 bg-paper-0 overflow-x-auto">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-4 pt-4 pb-2">{{ __('Per-workspace (this month)') }}</div>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 border-b border-paper-200">
                        <th class="px-4 py-2.5">{{ __('Workspace') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Msgs') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Revenue') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Meta cost') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Margin') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Wallet balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr class="border-b border-paper-100">
                            <td class="px-4 py-2">{{ $r['workspace_name'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($r['messages']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $cur($r['revenue_minor']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-ink-500">{{ $cur($r['cost_minor']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wa-deep font-medium">{{ $cur($r['margin_minor']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $cur(((int) $r['credits_left']) * \App\Services\MessageCreditRate::minorPerCredit()) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-ink-500">{{ __('No billed messages this month yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</x-layouts.admin>
