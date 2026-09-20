@php
    /** @var \Illuminate\Support\Collection $channels */
    $ch = $channel ?? null;
    $pct = fn ($v) => number_format((float) $v, 1).'%';
@endphp

<x-layouts.user :title="__('LINE insights')" nav-key="more" page="user-line-insights">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('LINE') }} · {{ __('Insights') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Channel') }} <span class="italic" style="color:#06C755">{{ __('insights') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Push quota, followers, delivery and audience demographics — read live from LINE.') }}@if ($date) <span class="font-mono text-ink-400">· {{ $date }}</span>@endif</p>
            </div>
            @if ($channels->count() > 1)
                <form method="GET" class="shrink-0">
                    <select name="channel" onchange="this.form.submit()" class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] font-semibold focus:outline-none focus:border-wa-deep">
                        @foreach ($channels as $c)
                            <option value="{{ $c->id }}" @selected($ch && $ch->id === $c->id)>{{ $c->display_name ?: ($c->basic_id ?: ('LINE ' . $c->id)) }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        @if ($channels->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#06C755">L</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a LINE channel first.') }}</p>
                <a href="{{ route('user.line.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#06C755">{{ __('Connect a channel') }}</a>
            </div>
        @else
            @if ($error)
                <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ __('Could not reach LINE') }}: {{ $error }}</div>
            @endif

            {{-- Quota + followers KPI --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Push quota') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ $quota === null ? __('Unlimited') : number_format($quota) }}</div>
                    <div class="mt-2 text-[11px] text-ink-500">{{ __('this period') }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Used') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ $used === null ? '—' : number_format($used) }}</div>
                    <div class="mt-2 text-[11px] text-wa-deep">{{ $remaining !== null ? number_format($remaining).' '.__('remaining') : __('of quota') }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Followers') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ $followers ? number_format($followers['followers'] ?? 0) : '—' }}</div>
                    <div class="mt-2 text-[11px] text-ink-500">{{ $followers ? number_format($followers['targetedReaches'] ?? 0).' '.__('reachable') : __('no data yet') }}</div>
                </div>
                <div class="bg-paper-0 border border-accent-coral/40 rounded-2xl p-4 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Blocks') }}</div>
                    <div class="mt-2 font-serif text-[30px] leading-none">{{ $followers ? number_format($followers['blocks'] ?? 0) : '—' }}</div>
                    <div class="mt-2 text-[11px] text-ink-500">{{ __('blocked the OA') }}</div>
                </div>
            </div>

            {{-- Delivery breakdown --}}
            @if ($delivery)
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('Messages delivered') }}</div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach (['broadcast' => __('Broadcast'), 'targeting' => __('Narrowcast'), 'autoResponse' => __('Auto-reply'), 'welcomeResponse' => __('Welcome'), 'chat' => __('Chat'), 'apiBroadcast' => __('API broadcast'), 'apiPush' => __('API push'), 'apiMulticast' => __('API multicast'), 'apiNarrowcast' => __('API narrowcast'), 'apiReply' => __('API reply')] as $k => $label)
                            @if (isset($delivery[$k]))
                                <div class="rounded-xl border border-paper-100 bg-paper-50/40 p-3">
                                    <div class="text-[11px] text-ink-500">{{ $label }}</div>
                                    <div class="mt-1 font-serif text-[22px] leading-none">{{ number_format($delivery[$k]) }}</div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Demographics --}}
            @if ($demographic)
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @php
                        $blocks = [
                            'genders' => [__('Gender'), 'gender'],
                            'ages'    => [__('Age'), 'age'],
                            'areas'   => [__('Area'), 'area'],
                            'appTypes'=> [__('Platform'), 'appType'],
                        ];
                    @endphp
                    @foreach ($blocks as $key => [$title, $field])
                        @if (! empty($demographic[$key]))
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ $title }}</div>
                                <div class="space-y-2">
                                    @foreach (collect($demographic[$key])->sortByDesc('percentage')->take(8) as $row)
                                        <div>
                                            <div class="flex items-center justify-between text-[11.5px] mb-0.5">
                                                <span class="text-ink-700">{{ ucfirst((string) ($row[$field] ?? 'unknown')) }}</span>
                                                <span class="font-mono text-ink-500">{{ $pct($row['percentage'] ?? 0) }}</span>
                                            </div>
                                            <div class="h-1.5 rounded-full bg-paper-100 overflow-hidden"><div class="h-full rounded-full" style="width:{{ min(100, (float) ($row['percentage'] ?? 0)) }}%;background:#06C755"></div></div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @elseif (! $error)
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-8 text-center text-[12.5px] text-ink-500">
                    {{ __('Demographic insights need at least 20 followers and appear a day after activity.') }}
                </div>
            @endif
        @endif
    </main>
</x-layouts.user>
