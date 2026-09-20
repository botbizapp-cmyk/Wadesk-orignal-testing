<x-layouts.user :title="__('Wallet statement')" nav-key="more" page="user-wallet-statement">

    @php
        // 1 credit == 1 money-minor in the money wallet, so every ledger
        // amount is a real money value: divide by 100 and format with the
        // workspace currency (never hardcode a symbol).
        $money   = fn ($minor) => \App\Support\FormatSettings::display((int) $minor / 100);
        $curSym  = \App\Support\FormatSettings::currencyFor()?->symbol;
        $csvQs   = http_build_query(array_filter([
            'type' => ($filters['type'] ?? 'all') !== 'all' ? $filters['type'] : null,
            'from' => $filters['from'] ?? null,
            'to'   => $filters['to'] ?? null,
            'q'    => $filters['q'] ?? null,
        ]));
    @endphp

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- Header --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-[11px] font-mono uppercase tracking-[0.16em] text-ink-500">
                    <a href="{{ url('/account?tab=wallet') }}" class="hover:text-wa-deep">{{ __('Wallet') }}</a>
                    <span class="mx-1">/</span>{{ __('Statement') }}
                </div>
                <h1 class="font-serif text-[32px] leading-tight mt-1">{{ __('Wallet statement') }}</h1>
                <p class="text-[12.5px] text-ink-500 mt-1">
                    {{ __('Every dollar that entered or left your wallet — top-ups, message charges, refunds and affiliate earnings.') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ url('/account?tab=wallet') }}"
                    class="px-3.5 py-2 rounded-full bg-paper-0 border border-paper-200 hover:border-wa-deep text-[12px] font-medium inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M10 3 5 8l5 5" />
                    </svg>{{ __('Back to wallet') }}
                </a>
                <a href="{{ route('user.account.wallet.statement.csv') }}{{ $csvQs ? '?' . $csvQs : '' }}"
                    class="px-3.5 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M8 2v8m0 0 3-3m-3 3L5 7M3 13h10" />
                    </svg>{{ __('Export CSV') }}
                </a>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="text-[11px] text-ink-600 font-medium">{{ __('Current balance') }}</div>
                <div class="font-serif text-[28px] leading-none mt-1.5">{!! $money((int) round(((int) ($authUser->wallet_credits ?? 0)) * \App\Services\MessageCreditRate::minorPerCredit())) !!}</div>
                <div class="text-[10.5px] text-ink-500 mt-2">{{ __('Available to spend') }}</div>
            </div>
            <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-4 shadow-card">
                <div class="text-[11px] text-ink-600 font-medium">{{ __('Money in') }}</div>
                <div class="font-serif text-[28px] leading-none mt-1.5 text-wa-deep">+{!! $money($summary['in']) !!}</div>
                <div class="text-[10.5px] text-ink-500 mt-2">{{ __('Top-ups, refunds & earnings') }}</div>
            </div>
            <div class="bg-paper-0 border border-accent-coral/40 rounded-2xl p-4 shadow-card">
                <div class="text-[11px] text-ink-600 font-medium">{{ __('Money out') }}</div>
                <div class="font-serif text-[28px] leading-none mt-1.5 text-accent-coral">−{!! $money($summary['out']) !!}</div>
                <div class="text-[10.5px] text-ink-500 mt-2">{{ __('Spent on messages') }}</div>
            </div>
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                <div class="text-[11px] text-ink-600 font-medium">{{ __('Net movement') }}</div>
                <div class="font-serif text-[28px] leading-none mt-1.5 {{ $summary['net'] >= 0 ? 'text-wa-deep' : 'text-accent-coral' }}">
                    {{ $summary['net'] >= 0 ? '+' : '−' }}{!! $money(abs($summary['net'])) !!}</div>
                <div class="text-[10.5px] text-ink-500 mt-2">{{ $summary['count'] }} {{ __('transactions in view') }}</div>
            </div>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('user.account.wallet.statement') }}"
            class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1.5">
                <label class="text-[11px] font-semibold text-ink-700">{{ __('Type') }}</label>
                <select name="type"
                    class="py-2 px-3 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep min-w-[150px]">
                    @php
                        $types = [
                            'all'          => __('All movements'),
                            'topup'        => __('Top-ups'),
                            'spend'        => __('Message charges'),
                            'refund'       => __('Refunds'),
                            'earn'         => __('Earnings'),
                            'admin_adjust' => __('Adjustments'),
                        ];
                    @endphp
                    @foreach ($types as $val => $label)
                        <option value="{{ $val }}" @selected(($filters['type'] ?? 'all') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[11px] font-semibold text-ink-700">{{ __('From') }}</label>
                <input type="date" name="from" value="{{ $filters['from'] }}"
                    class="py-2 px-3 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[11px] font-semibold text-ink-700">{{ __('To') }}</label>
                <input type="date" name="to" value="{{ $filters['to'] }}"
                    class="py-2 px-3 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
            </div>
            <div class="flex flex-col gap-1.5 flex-1 min-w-[180px]">
                <label class="text-[11px] font-semibold text-ink-700">{{ __('Search') }}</label>
                <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('Description or source…') }}"
                    class="py-2 px-3 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
            </div>
            <div class="flex items-center gap-2">
                <button type="submit"
                    class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Apply') }}</button>
                <a href="{{ route('user.account.wallet.statement') }}"
                    class="px-4 py-2 rounded-full bg-paper-0 border border-paper-200 hover:bg-paper-50 text-[12px] font-medium">{{ __('Reset') }}</a>
            </div>
        </form>

        {{-- Ledger --}}
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            @if ($rows->total() === 0)
                <div class="px-5 py-12 text-center">
                    @include('user.partials.empty-state', [
                        'message' => __('No transactions match these filters. Try widening the date range or clearing the search.'),
                        'resetHref' => route('user.account.wallet.statement'),
                    ])
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px] min-w-[720px]">
                        <thead class="bg-paper-50 border-b border-paper-200 text-ink-500">
                            <tr>
                                <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-2.5">{{ __('Date') }}</th>
                                <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-2.5">{{ __('Description') }}</th>
                                <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-2.5">{{ __('Type') }}</th>
                                <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-2.5">{{ __('Money in') }}</th>
                                <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-2.5">{{ __('Money out') }}</th>
                                <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-2.5">{{ __('Balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-200">
                            @foreach ($rows as $tx)
                                @php
                                    $amt = (int) $tx->amount;
                                    $isIn = $amt > 0;
                                    $badge = [
                                        'topup'        => 'bg-wa-mint border border-wa-green/30 text-wa-deep',
                                        'earn'         => 'bg-wa-mint border border-wa-green/30 text-wa-deep',
                                        'refund'       => 'bg-accent-amber/15 border border-accent-amber/40 text-ink-800',
                                        'spend'        => 'bg-paper-50 border border-paper-200 text-ink-700',
                                        'admin_adjust' => 'bg-paper-50 border border-paper-200 text-ink-700',
                                    ][$tx->type] ?? 'bg-paper-50 border border-paper-200 text-ink-700';
                                    $typeLabel = [
                                        'topup'        => __('Top-up'),
                                        'earn'         => __('Earning'),
                                        'refund'       => __('Refund'),
                                        'spend'        => __('Message charge'),
                                        'admin_adjust' => __('Adjustment'),
                                    ][$tx->type] ?? $tx->type;
                                @endphp
                                @php
                                    // Per-message charge detail (only spend rows have one).
                                    $charge = $charges[$tx->id] ?? null;
                                    $ctx = ($charge && $charge->wamid) ? ($contexts[$charge->wamid] ?? null) : null;
                                    $channelLabels = [
                                        'campaign'  => __('Campaign'),
                                        'broadcast' => __('Broadcast'),
                                        'flow'      => __('Flow / Chatbot'),
                                        'scheduled' => __('Scheduled'),
                                        'autoreply' => __('Auto-reply'),
                                        'chat'      => __('Team Inbox / Chat'),
                                        'api'       => __('API'),
                                    ];
                                    $engineLabels = [
                                        'waba'    => __('WhatsApp Cloud'),
                                        'baileys' => __('WhatsApp'),
                                        'twilio'  => __('Twilio'),
                                    ];
                                @endphp
                                <tr class="hover:bg-paper-50/60 align-top">
                                    <td class="px-4 py-2.5 font-mono text-[11.5px] text-ink-500 whitespace-nowrap">
                                        {{ optional($tx->created_at)->format('M d, Y · H:i') }}</td>
                                    <td class="px-3 py-2.5">
                                        <div>{{ $tx->description ?: ucfirst(str_replace('.', ' ', $tx->source ?? 'transaction')) }}</div>
                                        @if ($ctx)
                                            <div class="text-[11px] text-ink-600 mt-0.5 space-y-0.5">
                                                @if (!empty($ctx['name']))
                                                    <div><span class="text-ink-400">{{ $ctx['channel'] }}:</span>
                                                        <span class="font-medium text-ink-800">{{ $ctx['name'] }}</span></div>
                                                @endif
                                                @if (!empty($ctx['recipient']))
                                                    <div><span class="text-ink-400">{{ __('To') }}:</span>
                                                        <span class="font-mono">{{ $ctx['recipient'] }}</span></div>
                                                @endif
                                                @if (!empty($ctx['template']))
                                                    <div><span class="text-ink-400">{{ __('Template') }}:</span>
                                                        <span class="font-mono">{{ $ctx['template'] }}</span></div>
                                                @endif
                                            </div>
                                        @endif
                                        @if ($charge)
                                            <div class="flex flex-wrap items-center gap-1 mt-1">
                                                @if ($charge->source && isset($channelLabels[$charge->source]))
                                                    <span class="text-[9.5px] font-mono uppercase tracking-[0.08em] px-1.5 py-0.5 rounded bg-wa-mint/60 border border-wa-green/20 text-wa-deep">{{ $channelLabels[$charge->source] }}</span>
                                                @endif
                                                @if ($charge->to_country)
                                                    <span class="text-[9.5px] font-mono uppercase px-1.5 py-0.5 rounded bg-paper-50 border border-paper-200 text-ink-600">{{ $charge->to_country }}</span>
                                                @endif
                                                @if ($charge->category)
                                                    <span class="text-[9.5px] font-mono uppercase px-1.5 py-0.5 rounded bg-paper-50 border border-paper-200 text-ink-600">{{ $charge->category }}</span>
                                                @endif
                                                @if ($charge->provider && isset($engineLabels[$charge->provider]))
                                                    <span class="text-[9.5px] font-mono px-1.5 py-0.5 rounded bg-paper-50 border border-paper-200 text-ink-500">{{ $engineLabels[$charge->provider] }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5"><span
                                            class="text-[10.5px] font-mono px-2 py-0.5 rounded whitespace-nowrap {{ $badge }}">{{ $typeLabel }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono text-wa-deep">
                                        {!! $isIn ? '+' . $money($amt) : '<span class="text-ink-300">—</span>' !!}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-accent-coral">
                                        {!! !$isIn && $amt !== 0 ? '−' . $money(abs($amt)) : '<span class="text-ink-300">—</span>' !!}</td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold">{!! $money($tx->balance_after) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($rows->hasPages())
                    <div class="px-5 py-3 border-t border-paper-200 bg-paper-50/40 flex items-center justify-between text-[11.5px] text-ink-500">
                        <div class="font-mono">{{ __('Page') }} {{ $rows->currentPage() }} {{ __('of') }} {{ $rows->lastPage() }}
                            · {{ number_format($rows->total()) }} {{ __('total') }}</div>
                        <div class="flex items-center gap-1.5">
                            @if ($rows->onFirstPage())
                                <span class="px-3 py-1 rounded-full border border-paper-200 text-ink-400 cursor-not-allowed">{{ __('Prev') }}</span>
                            @else
                                <a href="{{ $rows->previousPageUrl() }}"
                                    class="px-3 py-1 rounded-full border border-paper-200 hover:bg-paper-50 text-ink-900 font-semibold">{{ __('Prev') }}</a>
                            @endif
                            @if ($rows->hasMorePages())
                                <a href="{{ $rows->nextPageUrl() }}"
                                    class="px-3 py-1 rounded-full border border-paper-200 hover:bg-paper-50 text-ink-900 font-semibold">{{ __('Next') }}</a>
                            @else
                                <span class="px-3 py-1 rounded-full border border-paper-200 text-ink-400 cursor-not-allowed">{{ __('Next') }}</span>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>

    </main>
</x-layouts.user>
