@php
    /** @var \Illuminate\Support\Collection $channels */
    $ch = $channel ?? null;
    $maxV = collect($rows)->map(fn ($r) => $r['in'] + $r['out'])->max() ?: 1;
    $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100) : 0;
@endphp

<x-layouts.user :title="__('Viber insights')" nav-key="more" page="user-viber-insights">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Viber') }} · {{ __('Insights') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Engagement') }} <span class="italic" style="color:#7360F2">{{ __('insights') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Message volume, active users and delivery/seen rates over the last 7 days.') }}</p>
            </div>
            @if ($channels->count() > 1)
                <form method="GET" class="shrink-0">
                    <select name="channel" onchange="this.form.submit()" class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] font-semibold focus:outline-none focus:border-wa-deep">
                        @foreach ($channels as $c)<option value="{{ $c->id }}" @selected($ch && $ch->id === $c->id)>{{ $c->bot_name ?: ('Viber ' . $c->id) }}</option>@endforeach
                    </select>
                </form>
            @endif
        </div>

        @if ($channels->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#7360F2">V</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a Viber channel first.') }}</p>
                <a href="{{ route('user.viber.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#7360F2">{{ __('Connect a channel') }}</a>
            </div>
        @else
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Active users (7d)') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($totals['users']) }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Received (7d)') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($totals['in']) }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Sent (7d)') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($totals['out']) }}</div>
                    <div class="mt-2 text-[11px] text-wa-deep">{{ $pct($totals['seen'], $totals['out']) }}% {{ __('seen') }} · {{ $pct($totals['delivered'], $totals['out']) }}% {{ __('delivered') }}</div>
                </div>
                <div class="bg-paper-0 border border-accent-coral/40 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Failed (7d)') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($totals['failed']) }}</div>
                </div>
            </div>

            @if (! empty($rows))
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('Daily volume') }}</div>
                    <div class="space-y-2.5">
                        @foreach ($rows as $r)
                            <div>
                                <div class="flex items-center justify-between text-[11.5px] mb-0.5">
                                    <span class="font-mono text-ink-600">{{ $r['date'] }}</span>
                                    <span class="text-ink-500"><span class="text-wa-deep">↓{{ $r['in'] }}</span> · <span style="color:#7360F2">↑{{ $r['out'] }}</span></span>
                                </div>
                                <div class="h-1.5 rounded-full bg-paper-100 overflow-hidden flex">
                                    <div class="h-full bg-wa-deep" style="width:{{ min(100, (int) round(($r['in'] / max(1,$maxV)) * 100)) }}%"></div>
                                    <div class="h-full" style="width:{{ min(100, (int) round(($r['out'] / max(1,$maxV)) * 100)) }}%;background:#7360F2"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </main>
</x-layouts.user>
