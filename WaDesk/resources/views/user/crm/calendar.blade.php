@php
    $dows = [__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')];
    $legend = [
        ['#0369A1', __('Tasks')], ['#0EA5E9', __('Projects')], ['#0b7a4b', __('Deals')],
        ['#6D28D9', __('Proposals')], ['#B45309', __('Estimates')],
    ];
@endphp
<x-layouts.user :title="__('Calendar')" nav-key="more" page="user-calendar">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-5">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('CRM') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('Your') }} <span class="italic text-wa-deep">{{ __('calendar') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Every deadline in one place — task due dates, project deadlines, deal close dates and quote expiries.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('user.calendar.index', ['m' => $prev]) }}" class="w-9 h-9 grid place-items-center rounded-full border border-paper-200 hover:bg-paper-100 text-ink-600" aria-label="Previous"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3l-5 5 5 5"/></svg></a>
                <div class="text-[14px] font-semibold min-w-[130px] text-center">{{ $monthLabel }}</div>
                <a href="{{ route('user.calendar.index', ['m' => $next]) }}" class="w-9 h-9 grid place-items-center rounded-full border border-paper-200 hover:bg-paper-100 text-ink-600" aria-label="Next"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3l5 5-5 5"/></svg></a>
                <a href="{{ route('user.calendar.index', ['m' => $today]) }}" class="ml-1 px-3 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Today') }}</a>
            </div>
        </div>

        <div class="flex items-center gap-4 flex-wrap text-[11px] text-ink-600">
            @foreach ($legend as [$c, $lbl])
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" style="background: {{ $c }}"></span>{{ $lbl }}</span>
            @endforeach
        </div>

        <x-crm.how-to :steps="[
            __('This grid pulls <b>every dated thing</b> automatically — you don\'t add anything here.'),
            __('Coloured bars are your <b>tasks</b>, <b>project</b> deadlines, <b>deal</b> close dates and <b>quote</b> expiries (see the key above).'),
            __('Click any bar to jump to that item; use the <b>&lsaquo; &rsaquo;</b> arrows to change month, <b>Today</b> to come back.'),
        ]" />

        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
            <div class="grid grid-cols-7 border-b border-paper-200 bg-paper-50">
                @foreach ($dows as $d)
                    <div class="px-2 py-2 text-center font-mono text-[10px] uppercase tracking-wide text-ink-500">{{ $d }}</div>
                @endforeach
            </div>
            @foreach ($weeks as $week)
                <div class="grid grid-cols-7">
                    @foreach ($week as $day)
                        <div class="min-h-[104px] border-b border-r border-paper-100 last:border-r-0 p-1.5 {{ $day['in_month'] ? '' : 'bg-paper-50/50' }}">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-semibold {{ $day['in_month'] ? 'text-ink-700' : 'text-ink-300' }} {{ $day['is_today'] ? 'w-5 h-5 grid place-items-center rounded-full bg-wa-deep text-paper-0' : '' }}">{{ $day['day'] }}</span>
                            </div>
                            <div class="space-y-1">
                                @foreach (array_slice($day['events'], 0, 4) as $ev)
                                    <a href="{{ $ev['url'] }}" class="block truncate rounded px-1.5 py-0.5 text-[10.5px] font-medium text-white/95 {{ $ev['done'] ? 'opacity-45 line-through' : '' }}" style="background: {{ $ev['color'] }}" title="{{ $ev['label'] }}">{{ $ev['label'] }}</a>
                                @endforeach
                                @if (count($day['events']) > 4)
                                    <div class="text-[10px] text-ink-400 px-1">+{{ count($day['events']) - 4 }} {{ __('more') }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <p class="text-[11.5px] text-ink-400">{{ trans_choice('{0}No deadlines this month.|{1}:count deadline this month.|[2,*]:count deadlines this month.', $total, ['count' => $total]) }}</p>
    </main>
</x-layouts.user>
