@php
    use Illuminate\Support\Carbon;

    $followersNum = is_null($followers) ? null : (int) $followers;
    $reach28   = $kpis['page_impressions_unique'] ?? null;
    $impr28    = $kpis['page_impressions'] ?? null;
    $eng28     = $kpis['page_post_engagements'] ?? null;
    $views28   = $kpis['page_views_total'] ?? null;
    $newFans28 = $kpis['page_fan_adds'] ?? null;
    $engRate   = ($eng28 && $reach28) ? round($eng28 / max(1, $reach28) * 100, 1) : null;

    // KPI cards — live Graph on top row, local WaDesk analytics on the second.
    $cards = [
        ['label' => __('Followers'),         'value' => $followersNum,        'sub' => __('total audience'),   'live' => true],
        ['label' => __('Reach · 28d'),       'value' => $reach28,             'sub' => __('people reached'),   'live' => true],
        ['label' => __('Impressions · 28d'), 'value' => $impr28,              'sub' => __('times shown'),      'live' => true],
        ['label' => __('Engagements · 28d'), 'value' => $eng28,               'sub' => __('interactions'),     'live' => true],
        ['label' => __('Conversations'),     'value' => $local['convos'],     'sub' => __(':n open', ['n' => $local['convos_open']]), 'live' => false],
        ['label' => __('Messages in · 28d'), 'value' => $local['msg_in'],     'sub' => __(':n sent back', ['n' => number_format($local['msg_out'])]), 'live' => false],
        ['label' => __('Posts published'),   'value' => $local['posts_published'], 'sub' => __(':n scheduled', ['n' => $local['posts_scheduled']]), 'live' => false],
        ['label' => __('Engagement rate'),   'value' => $engRate, 'suffix' => '%', 'sub' => __('eng ÷ reach'), 'live' => true],
    ];

    $typeMeta = [
        'photo'       => ['label' => __('Photo'), 'color' => '#1877F2'],
        'multi_photo' => ['label' => __('Album'), 'color' => '#42A5F5'],
        'link'        => ['label' => __('Link'),  'color' => '#7E57C2'],
        'video'       => ['label' => __('Video'), 'color' => '#26A69A'],
        'reel'        => ['label' => __('Reel'),  'color' => '#EC407A'],
    ];
    $contentTotal = array_sum($contentMix ?: []);

    $convoLabels = [
        'open'     => ['label' => __('Open'),     'color' => 'bg-wa-green'],
        'pending'  => ['label' => __('Pending'),  'color' => 'bg-accent-amber'],
        'resolved' => ['label' => __('Resolved'), 'color' => 'bg-wa-deep'],
        'closed'   => ['label' => __('Closed'),   'color' => 'bg-ink-400'],
        'snoozed'  => ['label' => __('Snoozed'),  'color' => 'bg-accent-coral'],
    ];
    $convoTotal = array_sum($convoMix ?: []);
@endphp

<x-layouts.user :title="__('Facebook Insights')" nav-key="facebook-posts" page="user-facebook-insights">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- ===== Header ===== --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Facebook') }} · {{ __('Analytics') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Page') }} <span class="italic text-wa-deep">{{ __('insights') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Reach and engagement (28-day, live from Facebook) blended with your Messenger activity and posts published from :brand.', ['brand' => brand_name()]) }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.facebook.posts.create') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Create post') }}</a>
                <a href="{{ route('user.facebook.my-posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('My posts') }}</a>
            </div>
        </div>

        @if ($pages->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-8 text-center">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3" style="background:#1877F2">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                </span>
                <p class="text-[13.5px] text-ink-700">{{ __('No Facebook Page connected yet.') }}</p>
                <a href="{{ url('/devices') }}" class="mt-3 inline-flex px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Connect a Facebook account') }}</a>
            </div>
        @else
            {{-- ===== Account identity + switcher (always visible) ===== --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                <div class="flex items-center gap-3.5 min-w-0 flex-1">
                    @if (!empty($identity['avatar']))
                        <img src="{{ $identity['avatar'] }}" alt="" referrerpolicy="no-referrer" class="w-14 h-14 rounded-2xl object-cover bg-paper-100 shrink-0 border border-paper-200">
                    @else
                        <span class="w-14 h-14 rounded-2xl grid place-items-center text-white text-[22px] font-semibold shrink-0" style="background:#1877F2">{{ strtoupper(mb_substr($identity['name'] ?? 'F', 0, 1)) }}</span>
                    @endif
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-[17px] font-semibold text-ink-900 truncate">{{ $identity['name'] ?? $page->page_id }}</span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-wa-mint text-wa-deep text-[10px] font-semibold shrink-0">
                                <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}
                            </span>
                        </div>
                        <div class="text-[12px] text-ink-500 mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span>{{ $identity['category'] ?? __('Facebook Page') }}</span>
                            @if (!empty($identity['username']))<span class="text-ink-300">·</span><span class="font-mono">{{ '@' . $identity['username'] }}</span>@endif
                            <span class="text-ink-300">·</span><span class="font-mono text-ink-400">ID {{ \Illuminate\Support\Str::limit($identity['page_id'] ?? '', 10, '…') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Account switcher — dropdown when several Pages, static chip when one --}}
                <div class="shrink-0">
                    <div class="font-mono text-[9px] uppercase tracking-[0.16em] text-ink-400 mb-1">{{ __('Viewing account') }}</div>
                    @if ($pages->count() > 1)
                        <form method="GET" action="{{ route('user.facebook.insights') }}">
                            <div class="relative">
                                <select name="page" onchange="this.form.submit()"
                                    class="appearance-none w-full sm:w-64 rounded-xl border border-paper-200 bg-paper-0 pl-9 pr-8 py-2.5 text-[13px] font-semibold focus:outline-none focus:border-wa-deep">
                                    @foreach ($pages as $p)
                                        <option value="{{ $p->id }}" @selected($page && $page->id === $p->id)>{{ $p->name ?: $p->page_id }}</option>
                                    @endforeach
                                </select>
                                <span class="absolute left-2 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full grid place-items-center" style="background:#1877F2"><svg viewBox="0 0 24 24" class="w-3 h-3" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg></span>
                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute right-3 top-1/2 -translate-y-1/2 text-ink-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6l4 4 4-4"/></svg>
                            </div>
                        </form>
                    @else
                        <span class="inline-flex items-center gap-2 rounded-xl border border-paper-200 bg-paper-50 px-3.5 py-2.5 text-[13px] font-semibold text-ink-800">
                            <span class="w-5 h-5 rounded-full grid place-items-center shrink-0" style="background:#1877F2"><svg viewBox="0 0 24 24" class="w-3 h-3" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg></span>
                            {{ $page->name ?: $page->page_id }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI grid ===== --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ($cards as $c)
                    <div class="bg-paper-0 border border-paper-200 rounded-[14px] p-4 shadow-card">
                        <div class="flex items-center justify-between">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ $c['label'] }}</div>
                            @if ($c['live'])
                                <span title="{{ __('Live from Facebook') }}" class="w-1.5 h-1.5 rounded-full" style="background:#1877F2"></span>
                            @else
                                <span title="{{ __('From :brand', ['brand' => brand_name()]) }}" class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>
                            @endif
                        </div>
                        <div class="mt-2 font-serif text-[30px] leading-none">{{ is_null($c['value']) ? '—' : number_format((float) $c['value']) . ($c['suffix'] ?? '') }}</div>
                        <div class="text-[11px] text-ink-500 mt-1">{{ $c['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- ===== Message activity chart ===== --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-3.5 border-b border-paper-200 flex items-center justify-between">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Messenger activity · last 14 days') }}</div>
                    <div class="flex items-center gap-4 text-[11px] text-ink-500">
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" style="background:#1877F2"></span>{{ __('Received') }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-wa-green"></span>{{ __('Sent') }}</span>
                    </div>
                </div>
                <div class="p-4">
                    <div id="fb-activity-chart" class="min-h-[260px]"></div>
                </div>
            </section>

            {{-- ===== Content mix + Conversation status ===== --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- Content mix --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Content mix') }} · {{ __(':n posts', ['n' => (int) $contentTotal]) }}</div>
                    <div class="p-5 space-y-3">
                        @if ($contentTotal > 0)
                            @foreach ($typeMeta as $key => $meta)
                                @php $n = (int) ($contentMix[$key] ?? 0); $pct = $contentTotal ? round($n / $contentTotal * 100) : 0; @endphp
                                @if ($n > 0)
                                    <div>
                                        <div class="flex items-center justify-between text-[12px] mb-1">
                                            <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:{{ $meta['color'] }}"></span>{{ $meta['label'] }}</span>
                                            <span class="font-mono text-ink-500">{{ $n }} · {{ $pct }}%</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-paper-100 overflow-hidden"><div class="h-full rounded-full" style="width:{{ $pct }}%;background:{{ $meta['color'] }}"></div></div>
                                    </div>
                                @endif
                            @endforeach
                        @else
                            <div class="text-center py-6 text-[12.5px] text-ink-500">
                                {{ __('No posts published from :brand yet.', ['brand' => brand_name()]) }}
                                <a href="{{ route('user.facebook.posts.create') }}" class="block mt-2 text-wa-deep font-semibold hover:underline">{{ __('Create your first post') }}</a>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Conversation status --}}
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Conversations') }} · {{ __(':n total', ['n' => (int) $convoTotal]) }}</div>
                    <div class="p-5 space-y-3">
                        @if ($convoTotal > 0)
                            @foreach ($convoLabels as $key => $meta)
                                @php $n = (int) ($convoMix[$key] ?? 0); $pct = $convoTotal ? round($n / $convoTotal * 100) : 0; @endphp
                                @if ($n > 0)
                                    <div>
                                        <div class="flex items-center justify-between text-[12px] mb-1">
                                            <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full {{ $meta['color'] }}"></span>{{ $meta['label'] }}</span>
                                            <span class="font-mono text-ink-500">{{ $n }} · {{ $pct }}%</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-paper-100 overflow-hidden"><div class="h-full rounded-full {{ $meta['color'] }}" style="width:{{ $pct }}%"></div></div>
                                    </div>
                                @endif
                            @endforeach
                            <a href="{{ url('/team-inbox') }}" class="inline-flex mt-2 text-[12px] text-wa-deep font-semibold hover:underline">{{ __('Open Team Inbox') }} →</a>
                        @else
                            <div class="text-center py-6 text-[12.5px] text-ink-500">{{ __('No Facebook conversations yet. They appear here once people message your Page.') }}</div>
                        @endif
                    </div>
                </section>
            </div>

            {{-- ===== Recent posts performance ===== --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Recent posts') }}</div>
                <div class="divide-y divide-paper-100">
                    @forelse ($posts as $post)
                        <div class="px-5 py-3.5 flex items-center gap-3">
                            @if ($post['picture'])
                                <img src="{{ $post['picture'] }}" alt="" referrerpolicy="no-referrer" class="w-12 h-12 rounded-lg object-cover bg-paper-100 shrink-0">
                            @else
                                <span class="w-12 h-12 rounded-lg bg-paper-100 grid place-items-center text-ink-400 shrink-0"><svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2.5" y="2.5" width="11" height="11" rx="2"/><path d="M2.5 11l3-3 2 2 3-4 3 3"/></svg></span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="text-[12.5px] text-ink-800 line-clamp-1">{{ $post['message'] ?: __('(no caption)') }}</div>
                                <div class="text-[10.5px] text-ink-500 font-mono flex items-center gap-2">
                                    <span>{{ $post['created'] ? Carbon::parse($post['created'])->diffForHumans() : '' }}</span>
                                    @if (!empty($post['status']))<span class="px-1.5 py-0.5 rounded bg-paper-100 text-ink-600 uppercase tracking-wide text-[9px]">{{ $post['status'] }}</span>@endif
                                </div>
                            </div>
                            <div class="flex items-center gap-5 text-right shrink-0">
                                <div><div class="font-serif text-[18px] leading-none">{{ number_format($post['stats']['impressions']) }}</div><div class="text-[10px] text-ink-500 font-mono">{{ __('reach') }}</div></div>
                                <div><div class="font-serif text-[18px] leading-none">{{ number_format($post['stats']['engaged']) }}</div><div class="text-[10px] text-ink-500 font-mono">{{ __('engaged') }}</div></div>
                                @if ($post['permalink'])
                                    <a href="{{ $post['permalink'] }}" target="_blank" rel="noopener" class="text-wa-deep hover:underline text-[11px] font-medium">{{ __('View') }}</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-[12.5px] text-ink-500">{{ __('No posts yet, or insights are still warming up.') }}</div>
                    @endforelse
                </div>
            </section>

            <p class="text-[11px] text-ink-500 flex items-center gap-4 flex-wrap">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full" style="background:#1877F2"></span>{{ __('Live from Facebook — deprecated metrics may show as —.') }}</span>
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('From :brand — Messenger + published posts.', ['brand' => brand_name()]) }}</span>
            </p>

            <script>
                window.FB_INSIGHTS = @json($trend);
            </script>
        @endif
    </main>
</x-layouts.user>
