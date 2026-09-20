@php
    $companies = $companies ?? collect();
    $kpis      = $kpis ?? ['total' => 0, 'with_deals' => 0, 'won_value' => 0];
    $q         = $q ?? '';
@endphp

<x-layouts.user :title="__('Companies')" nav-key="companies" page="user-companies-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7"
          data-companies
          data-store-url="{{ route('user.companies.store') }}"
          data-csrf="{{ csrf_token() }}">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('CRM · Organizations') }}</div>
                <h1 class="font-serif text-[26px] leading-tight">{{ __('Companies') }}</h1>
                <p class="mt-1.5 text-[13px] text-ink-500 max-w-xl">{{ __('Group contacts and deals under an organization — see every contact, open deal and won revenue in one place.') }}</p>
            </div>
            <button type="button" data-company-new
                class="self-start inline-flex items-center gap-2 px-4 h-[42px] rounded-xl bg-wa-deep text-white text-[13px] font-semibold hover:opacity-90 transition">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 3v10M3 8h10"/></svg>
                {{ __('New company') }}
            </button>
        </div>

        {{-- KPIs --}}
        <div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
            @foreach ([
                ['label' => __('Companies'), 'value' => number_format($kpis['total'])],
                ['label' => __('With open deals'), 'value' => number_format($kpis['with_deals'])],
                ['label' => __('Won revenue'), 'value' => number_format($kpis['won_value'], 2)],
            ] as $kpi)
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-400">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-[22px] font-semibold">{{ $kpi['value'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Search --}}
        <form method="GET" class="mb-4">
            <input type="text" name="q" value="{{ $q }}" placeholder="{{ __('Search companies…') }}"
                class="w-full sm:max-w-sm rounded-xl border border-paper-200 px-3.5 py-2.5 text-[13.5px] focus:outline-none focus:border-wa-deep">
        </form>

        {{-- List --}}
        <div class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-ink-500 border-b border-paper-200 bg-paper-50">
                            <th class="px-4 py-3 font-medium">{{ __('Company') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Industry') }}</th>
                            <th class="px-4 py-3 font-medium text-right">{{ __('Contacts') }}</th>
                            <th class="px-4 py-3 font-medium text-right">{{ __('Open deals') }}</th>
                            <th class="px-4 py-3 font-medium text-right">{{ __('Won value') }}</th>
                            <th class="px-4 py-3 font-medium text-right w-16"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($companies as $co)
                            <tr class="border-b border-paper-100 hover:bg-paper-50 cursor-pointer" onclick="window.location='{{ url('/companies/'.$co['id']) }}'">
                                <td class="px-4 py-3 font-semibold text-ink-800">{{ $co['name'] }}</td>
                                <td class="px-4 py-3 text-ink-500">{{ $co['industry'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ number_format($co['contacts']) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ number_format($co['open_deals']) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ number_format($co['won_value'], 2) }}</td>
                                {{-- Delete — stopPropagation so the row's navigate onclick doesn't also fire. --}}
                                <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                                    <form method="POST" action="{{ url('/companies/'.$co['id']) }}"
                                        onsubmit="return confirm('{{ __('Delete this company? Its contacts and deals are kept and just unlinked.') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" title="{{ __('Delete company') }}"
                                            class="inline-grid place-items-center w-8 h-8 rounded-lg text-ink-400 hover:text-accent-coral hover:bg-accent-coral/10 transition">
                                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-ink-400">{{ __('No companies yet. Create your first one.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    {{-- New-company modal --}}
    <div data-company-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
        <div class="bg-paper-0 rounded-[16px] shadow-soft w-full max-w-md p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-[16px] font-semibold">{{ __('New company') }}</h2>
                <button type="button" data-company-close class="text-ink-400 hover:text-ink-700">&times;</button>
            </div>
            <form data-company-form class="space-y-3">
                <input name="name" required placeholder="{{ __('Company name *') }}" class="w-full rounded-lg border border-paper-200 px-3 py-2 text-[13.5px] focus:outline-none focus:border-wa-deep">
                <div class="grid grid-cols-2 gap-3">
                    <input name="industry" placeholder="{{ __('Industry') }}" class="rounded-lg border border-paper-200 px-3 py-2 text-[13.5px] focus:outline-none focus:border-wa-deep">
                    <input name="website" placeholder="{{ __('Website') }}" class="rounded-lg border border-paper-200 px-3 py-2 text-[13.5px] focus:outline-none focus:border-wa-deep">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <input name="email" type="email" placeholder="{{ __('Email') }}" class="rounded-lg border border-paper-200 px-3 py-2 text-[13.5px] focus:outline-none focus:border-wa-deep">
                    <input name="phone" placeholder="{{ __('Phone') }}" class="rounded-lg border border-paper-200 px-3 py-2 text-[13.5px] focus:outline-none focus:border-wa-deep">
                </div>
                <textarea name="notes" rows="2" placeholder="{{ __('Notes') }}" class="w-full rounded-lg border border-paper-200 px-3 py-2 text-[13.5px] focus:outline-none focus:border-wa-deep"></textarea>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" data-company-close class="px-3 py-2 rounded-lg border border-paper-200 text-[13px] text-ink-500 hover:text-ink-700">{{ __('Cancel') }}</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-wa-deep text-white text-[13px] font-semibold hover:opacity-90">{{ __('Create') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.user>
