@php
    $contact  = $contact;
    $company  = $company ?? null;
    $deals    = $deals ?? collect();
    $timeline = $timeline ?? collect();
    $rollup   = $rollup ?? ['open_deals' => 0, 'won_value' => 0];
    $phone    = preg_replace('/\D+/', '', (string) ($contact->country_code . $contact->mobile));
    $dispName = (string) ($contact->name ?: trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: ($phone ?: '(no name)'));

    $icon = function ($type) {
        if (str_starts_with($type, 'message')) return '<path d="M2 4.5A2.5 2.5 0 0 1 4.5 2h7A2.5 2.5 0 0 1 14 4.5v4A2.5 2.5 0 0 1 11.5 11H6l-3 2v-2A2.5 2.5 0 0 1 2 8.5v-4Z"/>';
        if (str_starts_with($type, 'deal')) return '<path d="M8 2.5l5.5 4.2L11.4 13H4.6L2.5 6.7 8 2.5Z"/>';
        if (str_contains($type, 'call')) return '<path d="M3 3h2l1.4 3.4L5 8a8 8 0 0 0 3 3l1.6-1.4L13 11v2a1 1 0 0 1-1 1A11 11 0 0 1 2 4a1 1 0 0 1 1-1Z"/>';
        if (str_contains($type, 'task')) return '<rect x="2.5" y="2.5" width="11" height="11" rx="2"/><path d="M5.5 8l2 2 3.5-4"/>';
        return '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 1"/>';
    };
@endphp

<x-layouts.user :title="$dispName" nav-key="contacts">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">

        <a href="{{ route('contacts') }}" class="inline-flex items-center gap-1.5 text-[12px] text-ink-500 hover:text-wa-deep mb-4">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3L5 8l5 5"/></svg>
            {{ __('All contacts') }}
        </a>

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
            <div class="flex items-center gap-4">
                <span class="w-14 h-14 rounded-2xl bg-[#EEF2FF] text-[#4F46E5] grid place-items-center text-[20px] font-semibold">
                    {{ mb_strtoupper(mb_substr($dispName, 0, 1)) }}
                </span>
                <div>
                    <h1 class="font-serif text-[24px] leading-tight">{{ $dispName }}</h1>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] text-ink-500">
                        @if ($phone)<span class="font-mono">+{{ $phone }}</span>@endif
                        @if ($contact->email)<span>{{ $contact->email }}</span>@endif
                        @if ($company)<a href="{{ url('/companies/'.$company->id) }}" class="text-wa-deep font-medium">{{ $company->name }}</a>@endif
                    </div>
                    @if ($contact->tags && $contact->tags->count())
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($contact->tags as $t)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-paper-100 text-ink-600">{{ $t->name }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Rollup --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
            @foreach ([
                ['label' => __('Open deals'), 'value' => number_format($rollup['open_deals'])],
                ['label' => __('Won revenue'), 'value' => number_format($rollup['won_value'], 2)],
                ['label' => __('Timeline events'), 'value' => number_format($timeline->count())],
            ] as $kpi)
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-400">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-[20px] font-semibold">{{ $kpi['value'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6">
            {{-- Activity timeline --}}
            <section class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4 sm:p-5">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-4">{{ __('Activity timeline') }}</div>
                <div class="relative pl-6">
                    <div class="absolute left-[9px] top-1 bottom-1 w-px bg-paper-200"></div>
                    @forelse ($timeline as $ev)
                        <div class="relative mb-5">
                            <span class="absolute -left-6 top-0.5 w-[18px] h-[18px] rounded-full bg-paper-0 border border-paper-200 grid place-items-center text-wa-deep">
                                <svg viewBox="0 0 16 16" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.5">{!! $icon($ev['type']) !!}</svg>
                            </span>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[13px] font-semibold text-ink-800">{{ $ev['title'] }}</span>
                                <span class="text-[11px] text-ink-400 font-mono shrink-0">{{ $ev['at'] }}</span>
                            </div>
                            @if (!empty($ev['body']))
                                <p class="mt-0.5 text-[12.5px] text-ink-600 leading-snug">{{ $ev['body'] }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-[12px] text-ink-400 py-6 text-center">{{ __('No activity yet for this contact.') }}</p>
                    @endforelse
                </div>
            </section>

            {{-- Deals --}}
            <aside class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4 h-max">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-3">{{ __('Deals') }}</div>
                <div class="space-y-2">
                    @forelse ($deals as $d)
                        <a href="{{ url('/deals') }}" class="block rounded-lg px-3 py-2 hover:bg-paper-50">
                            <div class="flex items-center justify-between">
                                <span class="text-[13px] font-medium text-ink-800">{{ $d['title'] }}</span>
                                <span class="text-[12px] text-ink-500 font-mono">{{ number_format($d['value'], 2) }}</span>
                            </div>
                            <span class="text-[11px] px-2 py-0.5 rounded-full {{ $d['status'] === 'won' ? 'bg-wa-mint text-wa-deep' : ($d['status'] === 'lost' ? 'bg-red-50 text-red-600' : 'bg-paper-100 text-ink-500') }}">{{ $d['stage'] }}</span>
                        </a>
                    @empty
                        <p class="text-[12px] text-ink-400 py-4 text-center">{{ __('No deals yet.') }}</p>
                    @endforelse
                </div>
            </aside>
        </div>
    </main>
</x-layouts.user>
