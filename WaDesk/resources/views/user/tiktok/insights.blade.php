@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $tiktokGlyph = '<path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/>';

    $num = fn ($v) => is_null($v) ? '—' : number_format((int) $v);
    $compact = function ($v) {
        if ($v === null) return '—';
        $v = (int) $v;
        if ($v >= 1000000) return round($v / 1000000, 1) . 'M';
        if ($v >= 1000) return round($v / 1000, 1) . 'K';
        return (string) $v;
    };

    $cards = [
        ['label' => __('Followers'), 'value' => $kpis['followers'] ?? null, 'sub' => __('total audience')],
        ['label' => __('Likes'),     'value' => $kpis['likes'] ?? null,     'sub' => __('across all videos')],
        ['label' => __('Videos'),    'value' => $kpis['videos'] ?? null,    'sub' => __('published')],
        ['label' => __('Following'), 'value' => $kpis['following'] ?? null, 'sub' => __('accounts')],
    ];
@endphp

<x-layouts.user :title="__('TikTok insights')" nav-key="tiktok-accounts" page="user-tiktok-insights">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- ===== Header ===== --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('TikTok') }} · {{ __('Analytics') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Account') }} <span class="italic text-wa-deep">{{ __('insights') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Profile stats and your published videos with per-video engagement — live from the TikTok Display API.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.tiktok.accounts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Accounts') }}</a>
            </div>
        </div>

        @if ($accounts->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $tiktokGlyph !!}</svg>
                </span>
                <p class="text-[13.5px] text-ink-700">{{ __('No TikTok account connected yet.') }}</p>
                <a href="{{ route('user.tiktok.connect') }}" class="mt-3 inline-flex px-4 py-2 rounded-full bg-ink-900 text-paper-0 text-[12.5px] font-semibold hover:bg-ink-800">{{ __('Connect TikTok') }}</a>
            </div>
        @else
            {{-- ===== Account identity + switcher (always visible) ===== --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                <div class="flex items-center gap-3.5 min-w-0 flex-1">
                    @if (!empty($identity['avatar']))
                        <img src="{{ $identity['avatar'] }}" alt="" referrerpolicy="no-referrer"
                            onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='grid';}"
                            class="w-14 h-14 rounded-2xl object-cover bg-paper-100 shrink-0 border border-paper-200">
                        <span style="display:none" class="w-14 h-14 rounded-2xl place-items-center shrink-0 bg-ink-900 text-white text-[22px] font-semibold">{{ strtoupper(mb_substr($identity['name'] ?? 'T', 0, 1)) }}</span>
                    @else
                        <span class="w-14 h-14 rounded-2xl grid place-items-center shrink-0 bg-ink-900 text-white text-[22px] font-semibold">{{ strtoupper(mb_substr($identity['name'] ?? 'T', 0, 1)) }}</span>
                    @endif
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-[17px] font-semibold text-ink-900 truncate">{{ $identity['name'] ?? $account->open_id }}</span>
                            @if (!empty($identity['verified']))
                                <svg viewBox="0 0 16 16" class="w-4 h-4 text-wa-deep shrink-0" fill="currentColor" title="{{ __('Verified') }}"><path d="M8 1l1.8 1.3 2.2-.2.9 2 2 .9-.2 2.2L16 8l-1.3 1.8.2 2.2-2 .9-.9 2-2.2-.2L8 15l-1.8-1.3-2.2.2-.9-2-2-.9.2-2.2L0 8l1.3-1.8L1.1 4l2-.9.9-2 2.2.2z"/><path d="M6.8 10.4 4.7 8.3l.9-.9 1.2 1.2 3-3 .9.9z" fill="#fff"/></svg>
                            @endif
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-wa-mint text-wa-deep text-[10px] font-semibold shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                        </div>
                        <div class="text-[12px] text-ink-500 mt-0.5 flex flex-wrap items-center gap-x-2">
                            @if (!empty($identity['username']))<span class="font-mono">{{ '@' . ltrim($identity['username'], '@') }}</span>@endif
                            @if (!empty($identity['bio']))<span class="text-ink-300">·</span><span class="truncate max-w-[280px]">{{ $identity['bio'] }}</span>@endif
                        </div>
                    </div>
                </div>

                <div class="shrink-0">
                    <div class="font-mono text-[9px] uppercase tracking-[0.16em] text-ink-400 mb-1">{{ __('Viewing account') }}</div>
                    @if ($accounts->count() > 1)
                        <form method="GET" action="{{ route('user.tiktok.insights') }}">
                            <div class="relative">
                                <select name="account" onchange="this.form.submit()"
                                    class="appearance-none w-full sm:w-64 rounded-xl border border-paper-200 bg-paper-0 pl-9 pr-8 py-2.5 text-[13px] font-semibold focus:outline-none focus:border-wa-deep">
                                    @foreach ($accounts as $a)
                                        <option value="{{ $a->id }}" @selected($account && $account->id === $a->id)>{{ $a->display_name ?: ('@' . ltrim((string) $a->username, '@')) }}</option>
                                    @endforeach
                                </select>
                                <span class="absolute left-2 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full grid place-items-center bg-ink-900"><svg viewBox="0 0 24 24" class="w-3 h-3" fill="#fff">{!! $tiktokGlyph !!}</svg></span>
                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute right-3 top-1/2 -translate-y-1/2 text-ink-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6l4 4 4-4"/></svg>
                            </div>
                        </form>
                    @else
                        <span class="inline-flex items-center gap-2 rounded-xl border border-paper-200 bg-paper-50 px-3.5 py-2.5 text-[13px] font-semibold text-ink-800">
                            <span class="w-5 h-5 rounded-full grid place-items-center shrink-0 bg-ink-900"><svg viewBox="0 0 24 24" class="w-3 h-3" fill="#fff">{!! $tiktokGlyph !!}</svg></span>
                            {{ $account->display_name ?: ('@' . ltrim((string) $account->username, '@')) }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI grid ===== --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ($cards as $c)
                    <div class="bg-paper-0 border border-paper-200 rounded-[14px] p-4 shadow-card">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ $c['label'] }}</div>
                        <div class="mt-2 font-serif text-[30px] leading-none">{{ $num($c['value']) }}</div>
                        <div class="text-[11px] text-ink-500 mt-1">{{ $c['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- ===== Video grid ===== --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-3.5 border-b border-paper-200 flex items-center justify-between">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Recent videos') }}</div>
                    @if ($identity['deeplink'] ?? false)
                        <a href="{{ $identity['deeplink'] }}" target="_blank" rel="noopener" class="text-[12px] text-wa-deep font-semibold hover:underline">{{ __('Open on TikTok') }} →</a>
                    @endif
                </div>

                @if (empty($videos))
                    <div class="px-5 py-12 text-center text-[12.5px] text-ink-500">{{ __('No videos found, or the video.list scope was not granted for this account.') }}</div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 p-4">
                        @foreach ($videos as $v)
                            @php
                                $cover = $v['cover_image_url'] ?? '';
                                $title = trim((string) ($v['title'] ?? $v['video_description'] ?? ''));
                                $url   = $v['share_url'] ?? ($v['embed_link'] ?? '#');
                                $dur   = (int) ($v['duration'] ?? 0);
                            @endphp
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="group block">
                                <div class="relative aspect-[9/16] rounded-xl overflow-hidden bg-ink-900 border border-paper-200">
                                    @if ($cover)
                                        <img src="{{ $cover }}" alt="" referrerpolicy="no-referrer" loading="lazy" class="w-full h-full object-cover">
                                    @else
                                        <span class="w-full h-full grid place-items-center text-white/40"><svg viewBox="0 0 24 24" class="w-8 h-8" fill="currentColor">{!! $tiktokGlyph !!}</svg></span>
                                    @endif
                                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 flex items-center justify-between text-white text-[11px] font-semibold">
                                        <span class="flex items-center gap-1"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="currentColor"><path d="M8 14s6-3.7 6-8a3.2 3.2 0 0 0-6-1.3A3.2 3.2 0 0 0 2 6c0 4.3 6 8 6 8Z"/></svg>{{ $compact($v['like_count'] ?? 0) }}</span>
                                        <span class="flex items-center gap-1"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="currentColor"><path d="M8 2c3.3 0 6 2.2 6 5s-2.7 5-6 5c-.7 0-1.4-.1-2-.3L3 13l.8-2.3A5 5 0 0 1 2 7c0-2.8 2.7-5 6-5Z"/></svg>{{ $compact($v['comment_count'] ?? 0) }}</span>
                                    </div>
                                    @if ($dur > 0)
                                        <span class="absolute top-1.5 right-1.5 px-1.5 py-0.5 rounded bg-black/60 text-white text-[10px] font-mono">{{ sprintf('%d:%02d', intdiv($dur, 60), $dur % 60) }}</span>
                                    @endif
                                </div>
                                <div class="mt-1.5 flex items-center justify-between text-[10.5px] text-ink-500 font-mono px-0.5">
                                    <span class="flex items-center gap-1"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="currentColor"><path d="M8 3C4 3 1.5 8 1.5 8S4 13 8 13s6.5-5 6.5-5S12 3 8 3Zm0 8a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/></svg>{{ $compact($v['view_count'] ?? 0) }}</span>
                                    <span>{{ !empty($v['create_time']) ? Carbon::createFromTimestamp((int) $v['create_time'])->diffForHumans(null, true) : '' }}</span>
                                </div>
                                @if ($title !== '')
                                    <div class="mt-0.5 text-[11.5px] text-ink-700 line-clamp-2 px-0.5">{{ Str::limit($title, 60) }}</div>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <p class="text-[11px] text-ink-500">{{ __('Live from the TikTok Display API. Video cover images are time-limited by TikTok and refresh on each load.') }}</p>
        @endif
    </main>
</x-layouts.user>
