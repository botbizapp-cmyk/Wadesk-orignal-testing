@php
    $company  = $company;
    $contacts = $contacts ?? collect();
    $deals    = $deals ?? collect();
    $rollup   = $rollup ?? ['contacts' => 0, 'open_deals' => 0, 'won_value' => 0];
@endphp

<x-layouts.user :title="$company->name" nav-key="companies">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">

        <a href="{{ route('user.companies.index') }}" class="inline-flex items-center gap-1.5 text-[12px] text-ink-500 hover:text-wa-deep mb-4">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3L5 8l5 5"/></svg>
            {{ __('All companies') }}
        </a>

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
            <div>
                <h1 class="font-serif text-[26px] leading-tight">{{ $company->name }}</h1>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] text-ink-500">
                    @if ($company->industry)<span>{{ $company->industry }}</span>@endif
                    @if ($company->website)<a href="#" class="text-wa-deep">{{ $company->website }}</a>@endif
                    @if ($company->email)<span>{{ $company->email }}</span>@endif
                    @if ($company->phone)<span>{{ $company->phone }}</span>@endif
                </div>
            </div>
            <div class="shrink-0 flex items-center gap-2">
                {{-- AI-CRM Phase 5 — generate a shareable Client Brief deck. Opens the
                     public deck in a new tab. --}}
                <form method="POST" action="{{ route('user.crm.briefs.store') }}" target="_blank">
                    @csrf
                    <input type="hidden" name="subject_type" value="company">
                    <input type="hidden" name="subject_id" value="{{ $company->id }}">
                    <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold inline-flex items-center gap-1.5">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="2" width="11" height="12" rx="1.5"/><path d="M5 6h6M5 8.5h6M5 11h3.5"/></svg>
                        {{ __('Generate brief') }}
                    </button>
                </form>
                {{-- Delete the company (contacts + deals are kept and unlinked). --}}
                <form method="POST" action="{{ url('/companies/'.$company->id) }}"
                    onsubmit="return confirm('{{ __('Delete this company? Its contacts and deals are kept and just unlinked.') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" title="{{ __('Delete company') }}"
                        class="px-3.5 py-2 rounded-full border border-paper-200 text-accent-coral text-[12px] font-semibold inline-flex items-center gap-1.5 hover:bg-accent-coral/10 transition">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                        {{ __('Delete') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Rollup KPIs --}}
        <div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
            @foreach ([
                ['label' => __('Contacts'), 'value' => number_format($rollup['contacts'])],
                ['label' => __('Open deals'), 'value' => number_format($rollup['open_deals'])],
                ['label' => __('Won revenue'), 'value' => number_format($rollup['won_value'], 2)],
            ] as $kpi)
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-400">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-[22px] font-semibold">{{ $kpi['value'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Contacts --}}
            <section class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-3">{{ __('Contacts') }}</div>
                <div class="space-y-2">
                    @forelse ($contacts as $c)
                        <a href="{{ url('/contacts/'.$c['id']) }}" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-paper-50">
                            <span class="text-[13.5px] font-medium text-ink-800">{{ $c['name'] }}</span>
                            <span class="text-[12px] text-ink-400 font-mono">{{ $c['phone'] }}</span>
                        </a>
                    @empty
                        <p class="text-[12px] text-ink-400 py-4 text-center">{{ __('No contacts linked to this company.') }}</p>
                    @endforelse
                </div>
            </section>

            {{-- Deals --}}
            <section class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-3">{{ __('Deals') }}</div>
                <div class="space-y-2">
                    @forelse ($deals as $d)
                        <a href="{{ url('/deals') }}" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-paper-50">
                            <span class="text-[13.5px] font-medium text-ink-800">{{ $d['title'] }}</span>
                            <span class="flex items-center gap-2">
                                <span class="text-[11px] px-2 py-0.5 rounded-full {{ $d['status'] === 'won' ? 'bg-wa-mint text-wa-deep' : ($d['status'] === 'lost' ? 'bg-red-50 text-red-600' : 'bg-paper-100 text-ink-500') }}">{{ $d['stage'] }}</span>
                                <span class="text-[12px] text-ink-500 font-mono">{{ number_format($d['value'], 2) }}</span>
                            </span>
                        </a>
                    @empty
                        <p class="text-[12px] text-ink-400 py-4 text-center">{{ __('No deals for this company.') }}</p>
                    @endforelse
                </div>
            </section>
        </div>

        @if ($company->notes)
            <section class="mt-6 bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Notes') }}</div>
                <p class="text-[13.5px] text-ink-700 whitespace-pre-line">{{ $company->notes }}</p>
            </section>
        @endif
    </main>
</x-layouts.user>
