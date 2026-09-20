@php
    use App\Models\Currency;
    $sym = Currency::symbolFor($currency);
    $m = fn ($minor) => $sym . number_format(((int) $minor) / 100, 0);
    $md = fn ($minor) => $sym . number_format(((int) $minor) / 100, 2);
    $agB = $aging['buckets'] ?? [];
@endphp
<x-layouts.user :title="__('CRM')" nav-key="more" page="user-crm-dashboard">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Overview') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('Your') }} <span class="italic text-wa-deep">{{ __('CRM') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Deals, invoices, payments and tasks — the whole business in one view.') }}</p>
            </div>
            <a href="{{ route('user.crm.revenue') }}" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold shrink-0">{{ __('Revenue report') }}</a>
        </div>

        @php
            // Built here, not inline in the :steps attribute. The last step
            // contains a link, and a double-quoted HTML attribute cannot hold
            // an anchor whose href is also double quoted — the browser ends the
            // attribute at the first inner quote and prints the rest of the PHP
            // array as visible page text, which is what leaked under the
            // heading here. Passing a variable keeps the markup out of the
            // attribute entirely, and :link is a translator placeholder so the
            // sentence stays translatable with the anchor intact.
            $crmSteps = [
                __('This is your <b>home screen</b> — pipeline, revenue, outstanding money and tasks at a glance.'),
                __('Click any number to jump to that page (deals, invoices, payments).'),
                __('Open <b>Revenue report</b> for collected / outstanding / tax, then <b>export</b> to CSV or PDF.'),
                __('New here? Open the :link for every page and how to use it.', [
                    'link' => '<a href="' . url('/crm/guide') . '" class="underline font-semibold">'
                        . __('CRM guide') . '</a>',
                ]),
            ];
        @endphp

        <x-crm.how-to :steps="$crmSteps" />

        {{-- KPI row --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ([
                ['Open pipeline', $m($kpis['open_value_minor']), $kpis['open_deals'] . ' ' . __('deals'), url('/deals'), 'text-ink-900'],
                ['Won this month', $m($kpis['won_month_minor']), __('closed'), url('/deals'), 'text-wa-deep'],
                ['Collected (mo.)', $m($kpis['collected_month_minor']), __('this month'), url('/payments'), 'text-wa-deep'],
                ['Outstanding', $m($kpis['outstanding_minor']), $kpis['invoices_open'] . ' ' . __('invoices'), url('/payments'), 'text-accent-coral'],
            ] as [$label, $val, $sub, $href, $col])
                <a href="{{ $href }}" class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 hover:border-wa-deep transition block">
                    <div class="text-[10.5px] uppercase tracking-wide text-ink-500 font-semibold">{{ __($label) }}</div>
                    <div class="text-[24px] font-serif mt-1 {{ $col }}">{{ $val }}</div>
                    <div class="text-[11px] text-ink-500 mt-0.5">{{ $sub }}</div>
                </a>
            @endforeach
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ([
                ['Paid invoices', $kpis['invoices_paid'], url('/invoices')],
                ['Open tasks', $kpis['tasks_open'], url('/tasks')],
                ['Overdue tasks', $kpis['tasks_overdue'], url('/tasks')],
                ['Contacts', $kpis['contacts'], url('/contacts')],
            ] as [$label, $val, $href])
                <a href="{{ $href }}" class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-4 hover:border-wa-deep transition block">
                    <div class="text-[10.5px] uppercase tracking-wide text-ink-500 font-semibold">{{ __($label) }}</div>
                    <div class="text-[20px] font-serif mt-1 {{ $label === 'Overdue tasks' && $val > 0 ? 'text-accent-coral' : 'text-ink-900' }}">{{ $val }}</div>
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            {{-- Aging --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('Receivables aging') }}</div>
                <div class="space-y-2">
                    @foreach (['0-30','31-60','61-90','90+'] as $b)
                        <div class="flex items-center justify-between text-[12.5px]">
                            <span class="text-ink-600">{{ $b }} {{ __('days') }}</span>
                            <span class="font-mono {{ $b === '90+' ? 'text-accent-coral' : 'text-ink-800' }}">{{ $md($agB[$b] ?? 0) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Recent payments --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('Recent payments') }}</div>
                <div class="space-y-1.5">
                    @forelse ($recentPayments as $p)
                        <div class="flex items-center justify-between text-[12px]">
                            <span class="text-ink-600 truncate">{{ $p->contact?->name ?: optional($p->paid_at)->format('d M') }}</span>
                            <span class="font-mono text-wa-deep">{{ $p->amount_display }}</span>
                        </div>
                    @empty
                        <div class="text-[12px] text-ink-400 py-2">{{ __('No payments yet.') }}</div>
                    @endforelse
                </div>
            </div>

            {{-- Upcoming tasks --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-[13px] font-semibold text-ink-900">{{ __('Upcoming tasks') }}</div>
                    <a href="{{ url('/tasks') }}" class="text-[11px] text-wa-deep font-semibold hover:underline">{{ __('All') }}</a>
                </div>
                <div class="space-y-1.5">
                    @forelse ($upcomingTasks as $t)
                        <div class="flex items-center justify-between text-[12px]">
                            <span class="text-ink-700 truncate">{{ $t->title }}</span>
                            <span class="text-[10.5px] {{ $t->isOverdue() ? 'text-accent-coral' : 'text-ink-400' }}">{{ optional($t->due_at)->format('d M') }}</span>
                        </div>
                    @empty
                        <div class="text-[12px] text-ink-400 py-2">{{ __('Nothing scheduled.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </main>
</x-layouts.user>
