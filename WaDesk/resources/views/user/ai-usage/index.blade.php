@php
    $kpis        = $kpis ?? [];
    $byProvider  = $byProvider ?? [];
    $daily       = $daily ?? [];
    $byModel     = $byModel ?? [];
    $sourceSplit = $sourceSplit ?? ['admin' => 0, 'workspace' => 0];
    $cap         = $cap ?? ['used' => 0, 'limit' => null, 'pct' => 0];
    $voice       = $voice ?? ['voice_rows' => 0, 'calls' => 0];
    $window      = $window ?? '30d';

    $windows = ['7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days'), '1y' => __('1 year')];

    $provColor = [
        'openai' => '#10a37f', 'anthropic' => '#d97757', 'gemini' => '#4285f4',
        'google' => '#4285f4', 'deepseek' => '#4d6bfe', 'grok' => '#111', 'mistral' => '#fa520f',
    ];

    $maxProv  = max(1, collect($byProvider)->max('tokens') ?: 1);
    $maxDaily = max(1, collect($daily)->max() ?: 1);
    $totalSplit = max(1, ($sourceSplit['admin'] ?? 0) + ($sourceSplit['workspace'] ?? 0));
@endphp

<x-layouts.user :title="__('AI Usage')" nav-key="ai-usage">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">

        {{-- Header + window switcher --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('AI · Spend & usage') }}</div>
                <h1 class="font-serif text-[26px] leading-tight">{{ __('AI') }} <span class="italic text-wa-deep">{{ __('usage') }}</span></h1>
                <p class="text-[12.5px] text-ink-500 mt-1">{{ __('Every AI call your workspace made — tokens burnt, by model and provider, plan keys vs your own keys, and an estimated cost.') }}</p>
            </div>
            <div class="inline-flex items-center gap-1 bg-paper-50 rounded-full p-1 shrink-0">
                @foreach ($windows as $key => $label)
                    <a href="{{ url('/ai-usage') }}?window={{ $key }}"
                        class="px-3 py-1.5 rounded-full text-[11.5px] font-semibold transition {{ $window === $key ? 'bg-wa-deep text-paper-0' : 'text-ink-600 hover:bg-paper-100' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        {{-- KPI cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Tokens used') }}</div>
                <div class="text-[26px] font-semibold leading-none mt-2">{{ number_format($kpis['tokens'] ?? 0) }}</div>
                <div class="text-[11px] text-ink-500 mt-1.5">{{ number_format($kpis['calls'] ?? 0) }} {{ __('AI calls') }}</div>
            </div>
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Estimated cost') }}</div>
                <div class="text-[26px] font-semibold leading-none mt-2">≈ ${{ number_format($kpis['cost'] ?? 0, 2) }}</div>
                <div class="text-[11px] text-ink-500 mt-1.5">{{ __('blended provider rate') }}</div>
            </div>
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('On plan keys') }}</div>
                <div class="text-[26px] font-semibold leading-none mt-2">{{ number_format($kpis['admin_tokens'] ?? 0) }}</div>
                <div class="text-[11px] text-ink-500 mt-1.5">{{ __('tokens billed to your plan') }}</div>
            </div>
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('On your keys (BYOK)') }}</div>
                <div class="text-[26px] font-semibold leading-none mt-2">{{ number_format($kpis['byok_tokens'] ?? 0) }}</div>
                <div class="text-[11px] text-ink-500 mt-1.5">{{ __('tokens on your own API keys') }}</div>
            </div>
        </div>

        {{-- Monthly plan cap --}}
        <div class="rounded-2xl border border-paper-200 bg-paper-0 p-5 shadow-card mb-6">
            <div class="flex items-center justify-between mb-2">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('This month · plan AI cap') }}</div>
                <a href="{{ url('/settings?tab=aikeys') }}" class="text-[11.5px] font-semibold text-wa-deep hover:underline">{{ __('Manage AI keys →') }}</a>
            </div>
            @if ($cap['limit'] === null)
                <div class="text-[15px] font-semibold">{{ number_format($cap['used']) }} {{ __('tokens') }} <span class="text-ink-500 font-normal">· {{ __('unlimited on your plan') }}</span></div>
            @else
                <div class="flex items-baseline justify-between gap-3">
                    <div class="text-[15px] font-semibold">{{ number_format($cap['used']) }} <span class="text-ink-500 font-normal">/ {{ number_format($cap['limit']) }} {{ __('tokens') }}</span></div>
                    <div class="text-[12px] font-mono {{ $cap['pct'] >= 90 ? 'text-accent-coral' : 'text-ink-500' }}">{{ $cap['pct'] }}%</div>
                </div>
                <div class="mt-2 h-2.5 rounded-full bg-paper-100 overflow-hidden">
                    <div class="h-full rounded-full {{ $cap['pct'] >= 90 ? 'bg-accent-coral' : ($cap['pct'] >= 70 ? 'bg-accent-amber' : 'bg-wa-green') }}" style="width: {{ max(2, $cap['pct']) }}%"></div>
                </div>
                @if ($cap['pct'] >= 90)
                    <p class="text-[11.5px] text-accent-coral mt-2">{{ __('Almost at your monthly cap. Upgrade your plan or add your own keys (BYOK) to keep using AI.') }}</p>
                @endif
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
            {{-- Daily trend --}}
            <div class="lg:col-span-2 rounded-2xl border border-paper-200 bg-paper-0 p-5 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('Daily token burn') }}</div>
                @if (collect($daily)->sum() === 0)
                    <div class="h-40 grid place-items-center text-[12.5px] text-ink-400">{{ __('No AI usage in this window yet.') }}</div>
                @else
                    <div class="flex items-end gap-[3px] h-40">
                        @foreach ($daily as $date => $val)
                            <div class="flex-1 group relative flex items-end h-full">
                                <div class="w-full rounded-t bg-wa-green/80 hover:bg-wa-deep transition" style="height: {{ max(2, (int) round($val / $maxDaily * 100)) }}%"></div>
                                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block whitespace-nowrap rounded-lg bg-ink-900 text-paper-0 text-[10px] px-2 py-1 z-10">
                                    {{ \Illuminate\Support\Carbon::parse($date)->format('d M') }} · {{ number_format($val) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Plan vs BYOK split --}}
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-5 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('Plan keys vs your keys') }}</div>
                @php $adminPct = (int) round(($sourceSplit['admin'] ?? 0) / $totalSplit * 100); @endphp
                <div class="flex h-3 rounded-full overflow-hidden bg-paper-100">
                    <div class="bg-wa-deep h-full" style="width: {{ $adminPct }}%"></div>
                    <div class="bg-accent-amber h-full" style="width: {{ 100 - $adminPct }}%"></div>
                </div>
                <div class="mt-4 space-y-2.5">
                    <div class="flex items-center justify-between text-[12.5px]">
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-wa-deep"></span>{{ __('Plan keys') }}</span>
                        <span class="font-mono text-ink-700">{{ number_format($sourceSplit['admin'] ?? 0) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[12.5px]">
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-accent-amber"></span>{{ __('Your keys (BYOK)') }}</span>
                        <span class="font-mono text-ink-700">{{ number_format($sourceSplit['workspace'] ?? 0) }}</span>
                    </div>
                </div>
                <p class="text-[11px] text-ink-500 leading-snug mt-4 pt-4 border-t border-paper-200">{{ __('BYOK tokens run on your own provider account and never count against your plan cap.') }}</p>
            </div>
        </div>

        {{-- By provider --}}
        <div class="rounded-2xl border border-paper-200 bg-paper-0 p-5 shadow-card mb-6">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('By provider') }}</div>
            @if (empty($byProvider))
                <div class="text-[12.5px] text-ink-400 py-4">{{ __('No provider usage yet.') }}</div>
            @else
                <div class="space-y-3">
                    @foreach ($byProvider as $p)
                        @php $c = $provColor[strtolower($p['provider'])] ?? '#128C7E'; @endphp
                        <div>
                            <div class="flex items-center justify-between text-[12.5px] mb-1">
                                <span class="font-semibold capitalize">{{ $p['provider'] }}</span>
                                <span class="font-mono text-ink-500">{{ number_format($p['tokens']) }} {{ __('tok') }} · {{ number_format($p['calls']) }} {{ __('calls') }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-paper-100 overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ max(2, (int) round($p['tokens'] / $maxProv * 100)) }}%; background: {{ $c }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- By model --}}
        <div class="rounded-2xl border border-paper-200 bg-paper-0 shadow-card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('By model') }}</div>
            @if (empty($byModel))
                <div class="text-[12.5px] text-ink-400 px-5 py-6">{{ __('No model usage yet.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px] min-w-[560px]">
                        <thead>
                            <tr class="text-left font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 border-b border-paper-200">
                                <th class="px-5 py-2.5 font-medium">{{ __('Model') }}</th>
                                <th class="px-3 py-2.5 font-medium">{{ __('Provider') }}</th>
                                <th class="px-3 py-2.5 font-medium text-right">{{ __('Prompt') }}</th>
                                <th class="px-3 py-2.5 font-medium text-right">{{ __('Completion') }}</th>
                                <th class="px-3 py-2.5 font-medium text-right">{{ __('Total') }}</th>
                                <th class="px-5 py-2.5 font-medium text-right">{{ __('Calls') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byModel as $m)
                                <tr class="border-b border-paper-100 last:border-0 hover:bg-paper-50/60">
                                    <td class="px-5 py-2.5 font-semibold">{{ $m['model'] }}</td>
                                    <td class="px-3 py-2.5 text-ink-600 capitalize">{{ $m['provider'] ?: '—' }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-ink-600">{{ number_format($m['prompt']) }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-ink-600">{{ number_format($m['completion']) }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono font-semibold">{{ number_format($m['tokens']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-ink-600">{{ number_format($m['calls']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Voice / calls footnote --}}
        @if (($voice['calls'] ?? 0) > 0 || ($voice['voice_rows'] ?? 0) > 0)
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-5 shadow-card">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Voice & AI calls') }}</div>
                <div class="flex flex-wrap gap-6 text-[13px]">
                    <div><span class="font-semibold text-[18px]">{{ number_format($voice['calls'] ?? 0) }}</span> <span class="text-ink-500">{{ __('AI voice calls in window') }}</span></div>
                    <div><span class="font-semibold text-[18px]">{{ number_format($voice['voice_rows'] ?? 0) }}</span> <span class="text-ink-500">{{ __('voice usage days logged') }}</span></div>
                </div>
            </div>
        @endif

        <p class="text-[11px] text-ink-400 mt-4">{{ __('Cost is an estimate based on published blended per-provider token rates and may differ from your provider invoice. Token counts are exact.') }}</p>
    </main>
</x-layouts.user>
