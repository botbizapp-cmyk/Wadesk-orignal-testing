@php
    use App\Services\Social\SocialPostAggregator;
    // Per-channel action routes (publish/destroy take the post id positionally).
    $routes = [
        'instagram' => ['publish' => 'user.instagram.posts.publish', 'destroy' => 'user.instagram.posts.destroy', 'list' => url('/instagram/posts')],
        'facebook'  => ['publish' => 'user.facebook.posts.publish',  'destroy' => 'user.facebook.posts.destroy',  'list' => url('/facebook/posts')],
        'tiktok'    => ['publish' => null,                            'destroy' => 'user.tiktok.posts.destroy',    'list' => url('/tiktok/posts')],
    ];
    $chGlyph = [
        'instagram' => '<rect x="2.5" y="2.5" width="11" height="11" rx="3.2"/><circle cx="8" cy="8" r="2.6"/><circle cx="11.3" cy="4.7" r="0.7" fill="currentColor" stroke="none"/>',
        'facebook'  => '<path d="M9.5 5.2H8.4c-.6 0-1 .4-1 1V8H9.2l-.3 1.9H7.4v3.6"/>',
        'tiktok'    => '<path d="M10.4 2.5v7.8a2 2 0 1 1-1.8-2v-1.7a3.7 3.7 0 1 0 3.2 3.66V6.1a4.7 4.7 0 0 0 2.7.86V5.3a2.8 2.8 0 0 1-2.6-2.8z" fill="currentColor" stroke="none"/>',
    ];
    $chColor = ['instagram' => '#C13584', 'facebook' => '#1877F2', 'tiktok' => '#010101'];
    $filterUrl = fn ($k, $v) => url('/social/posts').'?'.http_build_query(array_filter([
        'status'   => $k === 'status' ? $v : $status,
        'platform' => $k === 'platform' ? $v : $channel,
    ]));
@endphp
<x-layouts.user :title="__('Posts')" nav-key="social-posts" page="user-social-posts">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Social') }} · {{ __('Posts') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('All') }} <span class="italic text-wa-deep">{{ __('posts') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Every scheduled post, draft and publish across Instagram, Facebook and TikTok — in one place.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ url('/social/calendar') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium inline-flex items-center gap-1.5"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 2v2M11 2v2"/></svg>{{ __('Calendar') }}</a>
                @if (count($channels))
                    <div class="relative" data-newpost>
                        <button type="button" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold inline-flex items-center gap-1.5"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3v10M3 8h10"/></svg>{{ __('New Post') }}</button>
                        <div data-newpost-menu class="hidden absolute right-0 mt-1 w-44 bg-paper-0 border border-paper-200 rounded-xl shadow-card p-1 z-20">
                            @foreach ($channels as $c)<a href="{{ $c['create'] }}" class="block px-3 py-2 rounded-lg text-[12.5px] hover:bg-paper-50">{{ __('New') }} {{ $c['label'] }} {{ __('post') }}</a>@endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif

        {{-- Filters --}}
        <div class="flex items-center gap-3 flex-wrap bg-paper-0 border border-paper-200 rounded-2xl shadow-card px-4 py-3">
            <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Filter') }}</span>
            <div class="flex items-center gap-1.5 flex-wrap">
                @foreach (['' => __('All statuses').' ('.$counts['all'].')', 'scheduled' => __('Scheduled').' ('.$counts['scheduled'].')', 'published' => __('Published').' ('.$counts['published'].')', 'draft' => __('Draft').' ('.$counts['draft'].')', 'failed' => __('Failed').' ('.$counts['failed'].')'] as $k => $lbl)
                    <a href="{{ $filterUrl('status', $k ?: null) }}" class="px-3 py-1.5 rounded-full text-[12px] {{ (string) $status === (string) $k ? 'bg-wa-deep text-paper-0 font-semibold' : 'border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-700' }}">{{ $lbl }}</a>
                @endforeach
            </div>
            <div class="ml-auto flex items-center gap-1.5">
                <a href="{{ $filterUrl('platform', null) }}" class="px-3 py-1.5 rounded-full text-[12px] {{ ! $channel ? 'bg-ink-900 text-paper-0 font-semibold' : 'border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-700' }}">{{ __('All platforms') }}</a>
                @foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $k => $lbl)
                    <a href="{{ $filterUrl('platform', $k) }}" class="px-3 py-1.5 rounded-full text-[12px] inline-flex items-center gap-1.5 {{ $channel === $k ? 'text-paper-0 font-semibold' : 'border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-700' }}" @if($channel===$k) style="background: {{ $chColor[$k] }}" @endif>
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$k] !!}</svg>{{ $lbl }}
                    </a>
                @endforeach
            </div>
        </div>

        @if ($posts->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-12 text-center">
                <div class="font-serif text-[18px]">{{ __('No posts yet') }}</div>
                <p class="text-[12.5px] text-ink-600 mt-1">{{ count($channels) ? __('Create a post from one of your connected channels.') : __('Connect Instagram, Facebook or TikTok to start posting.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4" data-post-grid>
                @foreach ($posts as $p)
                    @php [$sc, $sl] = SocialPostAggregator::statusStyle($p['status']); $r = $routes[$p['channel']]; $when = $p['scheduled_at'] ?: $p['published_at']; @endphp
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden flex flex-col" data-post-card data-post-uid="{{ $p['uid'] }}" data-post-status="{{ $p['status'] }}">
                        {{-- media --}}
                        <div class="relative aspect-[4/3] bg-paper-100">
                            @if ($p['media_url'])
                                <img src="{{ $p['media_url'] }}" alt="" class="absolute inset-0 w-full h-full object-cover" onerror="this.style.display='none'">
                            @else
                                <div class="absolute inset-0 grid place-items-center text-ink-300"><svg viewBox="0 0 24 24" class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="M21 16l-5-5L5 21"/></svg></div>
                            @endif
                            <span class="absolute top-2.5 left-2.5 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold text-paper-0" style="background: {{ $chColor[$p['channel']] }}"><svg viewBox="0 0 16 16" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$p['channel']] !!}</svg></span>
                        </div>
                        <div class="p-3.5 flex flex-col gap-2 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span data-post-badge class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-medium {{ $sc }}">{{ $sl }}</span>
                                <span data-post-when class="font-mono text-[10px] text-ink-500">{{ $when ? $when->timezone(safe_timezone(config('app.timezone'),'Asia/Calcutta'))->format('j M Y, g:i A') : '' }}</span>
                            </div>
                            <div class="text-[13px] text-ink-800 leading-snug line-clamp-3">{{ \Illuminate\Support\Str::limit(strip_tags($p['text']), 160) ?: '—' }}</div>
                            <div data-post-error class="text-[10.5px] text-accent-coral line-clamp-2" @style(['display:none' => ! ($p['status'] === 'failed' && $p['error'])])>{{ $p['status'] === 'failed' ? \Illuminate\Support\Str::limit($p['error'], 120) : '' }}</div>
                            <div class="flex items-center gap-1.5 mt-auto pt-1">
                                <span class="w-5 h-5 rounded-full bg-paper-100 grid place-items-center overflow-hidden shrink-0">@if($p['account_avatar'])<img src="{{ $p['account_avatar'] }}" class="w-5 h-5 object-cover" onerror="this.remove()">@else<svg viewBox="0 0 16 16" class="w-3 h-3 text-ink-400" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$p['channel']] !!}</svg>@endif</span>
                                <span class="text-[11px] text-ink-600 truncate">{{ $p['account_name'] }}</span>
                            </div>
                            {{-- Status-aware actions. [data-when] lists the statuses each button shows for;
                                 the poller toggles them live as status changes. Forms submit via AJAX. --}}
                            <div class="grid grid-cols-2 gap-1.5 pt-1.5 border-t border-paper-100" data-post-actions>
                                <a href="{{ $r['list'] }}" class="px-2.5 py-1.5 rounded-lg border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[11.5px] font-medium text-center">{{ __('Manage') }}</a>
                                @if ($r['publish'])
                                    <form method="POST" action="{{ route($r['publish'], $p['id']) }}" data-ajax-action data-when="draft scheduled" @style(['display:none' => ! in_array($p['status'], ['draft','scheduled'], true)])>@csrf<button class="w-full px-2.5 py-1.5 rounded-lg bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold">{{ __('Publish now') }}</button></form>
                                    <form method="POST" action="{{ route($r['publish'], $p['id']) }}" data-ajax-action data-when="failed" @style(['display:none' => $p['status'] !== 'failed'])>@csrf<button class="w-full px-2.5 py-1.5 rounded-lg bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold">{{ __('Republish') }}</button></form>
                                @else
                                    <a href="{{ $r['list'] }}" class="px-2.5 py-1.5 rounded-lg border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[11.5px] font-medium text-center" data-when="published draft" @style(['display:none' => ! in_array($p['status'], ['published','draft'], true)])>{{ __('Details') }}</a>
                                @endif
                                @if ($r['destroy'])
                                    <form method="POST" action="{{ route($r['destroy'], $p['id']) }}" class="col-span-2" data-ajax-action data-confirm="{{ __('Stop this in-progress post?') }}" data-when="processing" @style(['display:none' => $p['status'] !== 'processing'])>@csrf @method('DELETE')<button class="w-full px-2.5 py-1.5 rounded-lg border border-accent-coral/30 text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-medium">{{ __('Stop') }}</button></form>
                                    <form method="POST" action="{{ route($r['destroy'], $p['id']) }}" class="col-span-2" data-ajax-action data-confirm="{{ __('Cancel this scheduled post?') }}" data-when="scheduled" @style(['display:none' => $p['status'] !== 'scheduled'])>@csrf @method('DELETE')<button class="w-full px-2.5 py-1.5 rounded-lg border border-accent-coral/30 text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-medium">{{ __('Cancel schedule') }}</button></form>
                                    <form method="POST" action="{{ route($r['destroy'], $p['id']) }}" class="col-span-2" data-ajax-action data-confirm="{{ __('Delete this post?') }}" data-when="draft failed published" @style(['display:none' => ! in_array($p['status'], ['draft','failed','published'], true)])>@csrf @method('DELETE')<button class="w-full px-2.5 py-1.5 rounded-lg border border-accent-coral/30 text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-medium">{{ __('Delete') }}</button></form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </main>
</x-layouts.user>
