@php
    /** @var \Illuminate\Support\Collection $channels */
    $ch = $channel ?? null;
    $maxNew = collect($rows)->max('new') ?: 1;
@endphp

<x-layouts.user :title="__('WeChat insights')" nav-key="more" page="user-wechat-insights">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('WeChat') }} · {{ __('Insights') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Follower') }} <span class="italic" style="color:#07C160">{{ __('insights') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('New follows, unfollows and total followers over the last 7 days (WeChat data lags ~1 day).') }}</p>
            </div>
            @if ($channels->count() > 1)
                <form method="GET" class="shrink-0">
                    <select name="channel" onchange="this.form.submit()" class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] font-semibold focus:outline-none focus:border-wa-deep">
                        @foreach ($channels as $c)<option value="{{ $c->id }}" @selected($ch && $ch->id === $c->id)>{{ $c->account_name ?: ('WeChat ' . $c->id) }}</option>@endforeach
                    </select>
                </form>
            @endif
        </div>

        @if ($channels->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#07C160">W</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a WeChat channel first.') }}</p>
                <a href="{{ route('user.wechat.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#07C160">{{ __('Connect a channel') }}</a>
            </div>
        @else
            @if ($error)
                <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ __('Could not reach WeChat') }}: {{ $error }}</div>
            @endif

            <div class="grid grid-cols-3 gap-3">
                <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Total followers') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ $total !== null ? number_format($total) : '—' }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('New (7d)') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($newSum) }}</div>
                </div>
                <div class="bg-paper-0 border border-accent-coral/40 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Unfollows (7d)') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($cancelSum) }}</div>
                </div>
            </div>

            @if (! empty($rows))
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('Daily') }}</div>
                    <div class="space-y-2.5">
                        @foreach ($rows as $r)
                            <div>
                                <div class="flex items-center justify-between text-[11.5px] mb-0.5">
                                    <span class="font-mono text-ink-600">{{ $r['date'] }}</span>
                                    <span class="text-ink-500"><span class="text-wa-deep">+{{ $r['new'] }}</span> · <span class="text-accent-coral">-{{ $r['cancel'] }}</span>@if($r['total'] !== null) · {{ number_format($r['total']) }} {{ __('total') }}@endif</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-paper-100 overflow-hidden"><div class="h-full rounded-full" style="width:{{ min(100, (int) round(($r['new'] / max(1,$maxNew)) * 100)) }}%;background:#07C160"></div></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif (! $error)
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-8 text-center text-[12.5px] text-ink-500">
                    {{ __('No follower data yet — WeChat analytics need the Official Account to have followers and appear a day after activity.') }}
                </div>
            @endif
        @endif
    </main>
</x-layouts.user>
