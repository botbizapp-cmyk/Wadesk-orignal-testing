@php
    use Illuminate\Support\Str;

    $currentStatus = $currentStatus ?? 'all';
    $currentType   = $currentType ?? 'all';
    $currentRange  = $currentRange ?? 'all';
    $currentSearch = $currentSearch ?? '';
    $typeCounts    = $typeCounts ?? ['all' => 0];
    $total         = $total ?? ($typeCounts['all'] ?? 0);
    $counts        = $counts ?? ['scheduled' => 0, 'published' => 0, 'failed' => 0];
    $selectedPage  = (int) (request()->integer('page'));

    // Filter URL preserving the OTHER active filters (mirrors IG index).
    $filterUrl = function (array $o) use ($currentStatus, $currentType, $currentRange, $currentSearch, $selectedPage) {
        $q = array_filter([
            'account' => $selectedPage ?: null,
            'status'  => $o['status'] ?? ($currentStatus !== 'all' ? $currentStatus : null),
            'type'    => $o['type']   ?? ($currentType   !== 'all' ? $currentType   : null),
            'range'   => $o['range']  ?? ($currentRange  !== 'all' ? $currentRange  : null),
            'q'       => $o['q']       ?? ($currentSearch !== '' ? $currentSearch : null),
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'all');
        return route('user.facebook.posts') . ($q ? '?' . http_build_query($q) : '');
    };
    $statusPill = fn (string $s) => match ($s) {
        'scheduled' => ['cls' => 'bg-accent-amber/15 text-[#7B5A14]',   'label' => __('Scheduled')],
        'published' => ['cls' => 'bg-wa-mint text-wa-deep',              'label' => __('Published')],
        'failed'    => ['cls' => 'bg-accent-coral/10 text-accent-coral', 'label' => __('Failed')],
        'draft'     => ['cls' => 'bg-paper-100 text-ink-600',            'label' => __('Draft')],
        default     => ['cls' => 'bg-paper-100 text-ink-600',            'label' => Str::title($s)],
    };
    $statusTabs = [
        ['key' => 'all',       'label' => __('All'),        'count' => $total],
        ['key' => 'scheduled', 'label' => __('Scheduled'),  'count' => $counts['scheduled'] ?? 0],
        ['key' => 'published', 'label' => __('Published'),  'count' => $counts['published'] ?? 0],
        ['key' => 'failed',    'label' => __('Failed'),     'count' => $counts['failed'] ?? 0],
    ];
@endphp

<x-layouts.user :title="__('Facebook Posts')" nav-key="facebook-posts" page="user-facebook-posts-table">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    {{ __('Workspace') }} · {{ auth()->user()?->currentWorkspace?->name ?: __('Facebook') }}
                </div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Facebook') }} <span class="italic text-wa-deep">{{ __('posts') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Everything you have created, scheduled or published from :brand — track status, retry failures, and manage your queue.', ['brand' => brand_name()]) }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
                @if ($pages->count() > 1)
                    <form method="GET" action="{{ route('user.facebook.posts') }}">
                        @if ($currentStatus !== 'all')<input type="hidden" name="status" value="{{ $currentStatus }}">@endif
                        <select name="account" onchange="this.form.submit()" class="h-9 rounded-full border border-paper-200 bg-paper-0 pl-3 pr-8 text-[12.5px] font-medium focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('All Pages') }}</option>
                            @foreach ($pages as $p)
                                <option value="{{ $p->id }}" {{ $selectedPage === $p->id ? 'selected' : '' }}>{{ $p->name ?: $p->page_id }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <a href="{{ route('user.facebook.my-posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
                    {{ __('My posts') }}
                </a>
                <a href="{{ route('user.facebook.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Insights') }}</a>
                <a href="{{ route('user.facebook.posts.create') }}" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 hover:bg-wa-teal text-[12px] font-semibold flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 3.5v9M3.5 8h9"/></svg>
                    {{ __('Create post') }}
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif

        @if ($pages->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3" style="background:#1877F2">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                </span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('No Facebook Page connected yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 mb-4">{{ __('Connect a Facebook account to publish and schedule Page posts.') }}</p>
                <a href="{{ url('/devices') }}" class="inline-flex px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Connect a Facebook account') }}</a>
            </div>
        @else
            {{-- KPI cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="text-[11px] text-ink-600 font-medium">{{ __('Total posts') }}</div>
                    <div class="font-serif text-[34px] leading-none mt-1">{{ number_format($total) }}</div>
                </div>
                <div class="bg-paper-0 border border-accent-amber/40 rounded-2xl p-4 shadow-card">
                    <div class="text-[11px] text-ink-600 font-medium">{{ __('Scheduled') }}</div>
                    <div class="font-serif text-[34px] leading-none mt-1">{{ number_format($counts['scheduled'] ?? 0) }}</div>
                </div>
                <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-4 shadow-card">
                    <div class="text-[11px] text-ink-600 font-medium">{{ __('Published') }}</div>
                    <div class="font-serif text-[34px] leading-none mt-1">{{ number_format($counts['published'] ?? 0) }}</div>
                </div>
                <div class="bg-paper-0 border border-accent-coral/40 rounded-2xl p-4 shadow-card">
                    <div class="text-[11px] text-ink-600 font-medium">{{ __('Failed') }}</div>
                    <div class="font-serif text-[34px] leading-none mt-1 {{ ($counts['failed'] ?? 0) ? 'text-accent-coral' : '' }}">{{ number_format($counts['failed'] ?? 0) }}</div>
                </div>
            </div>

            {{-- Status tabs + range + search --}}
            <div class="border border-paper-200 rounded-2xl bg-paper-0 p-2 flex flex-wrap items-center gap-1 shadow-card">
                @foreach ($statusTabs as $st)
                    @php $active = $currentStatus === $st['key']; @endphp
                    <a href="{{ $filterUrl(['status' => $st['key']]) }}"
                        class="inline-flex items-center gap-1.5 px-4 py-[7px] rounded-full text-[13px] transition {{ $active ? 'bg-wa-deep text-paper-0' : 'text-ink-600 hover:bg-paper-50' }}">
                        {{ $st['label'] }}<span class="font-mono text-[11px] {{ $active ? 'opacity-90' : 'text-ink-400' }}">{{ number_format($st['count']) }}</span>
                    </a>
                @endforeach
                <div class="flex-1"></div>
                @foreach ([['all', __('All time')], ['7d', __('7d')], ['30d', __('30d')], ['90d', __('90d')]] as [$rk, $rl])
                    @php $active = $currentRange === $rk; @endphp
                    <a href="{{ $filterUrl(['range' => $rk]) }}"
                        class="inline-flex items-center px-3 py-[7px] rounded-full text-[12px] transition {{ $active ? 'bg-wa-deep/10 text-wa-deep font-semibold' : 'text-ink-500 hover:bg-paper-50' }}">{{ $rl }}</a>
                @endforeach
                <form method="GET" action="{{ route('user.facebook.posts') }}" class="relative ml-1">
                    @if ($selectedPage)<input type="hidden" name="account" value="{{ $selectedPage }}">@endif
                    @if ($currentStatus !== 'all')<input type="hidden" name="status" value="{{ $currentStatus }}">@endif
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3"/></svg>
                    <input name="q" type="search" value="{{ $currentSearch }}" placeholder="{{ __('Search posts…') }}"
                        class="border border-paper-200 rounded-full pl-9 pr-3 py-1.5 text-[12px] bg-paper-0 w-full lg:w-60 focus:outline-none focus:border-wa-deep">
                </form>
            </div>

            {{-- ===== TABLE ===== --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left font-mono text-[10px] uppercase tracking-[0.12em] text-ink-500 border-b border-paper-200">
                                <th class="px-4 py-3 font-medium">{{ __('Post') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('Page') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('Type') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('When') }}</th>
                                <th class="px-4 py-3 font-medium text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-100">
                            @forelse ($posts as $post)
                                @php $pill = $statusPill($post->status); $thumb = data_get($post->media_json, 'photos.0'); @endphp
                                <tr class="hover:bg-paper-50/60">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3 min-w-0 max-w-[420px]">
                                            @if ($thumb)
                                                <img src="{{ $thumb }}" alt="" referrerpolicy="no-referrer" class="w-10 h-10 rounded-lg object-cover border border-paper-200 bg-paper-100 shrink-0">
                                            @else
                                                <span class="w-10 h-10 rounded-lg bg-paper-100 grid place-items-center text-ink-400 shrink-0"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2.5" y="2.5" width="11" height="11" rx="2"/><path d="M2.5 11l3-3 4 4"/></svg></span>
                                            @endif
                                            <span class="min-w-0">
                                                <span class="block text-ink-800 line-clamp-1">{{ $post->message ?: ($post->link ?: '['.str_replace('_', ' ', $post->type).']') }}</span>
                                                @if ($post->error)<span class="block text-[10.5px] text-accent-coral line-clamp-1" title="{{ $post->error }}">{{ Str::limit($post->error, 80) }}</span>@endif
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-ink-600 whitespace-nowrap">{{ $post->page?->name ?: '—' }}</td>
                                    <td class="px-4 py-3 text-ink-600 capitalize whitespace-nowrap">{{ str_replace('_', ' ', $post->type) }}</td>
                                    <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-mono {{ $pill['cls'] }}">{{ $pill['label'] }}</span></td>
                                    <td class="px-4 py-3 text-ink-500 whitespace-nowrap text-[12px]">
                                        @if ($post->status === 'scheduled' && $post->scheduled_publish_time)
                                            {{ $post->scheduled_publish_time->diffForHumans() }}
                                        @elseif ($post->published_at){{ $post->published_at->diffForHumans() }}
                                        @else {{ $post->created_at->diffForHumans() }}@endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            @if (in_array($post->status, ['failed', 'draft'], true))
                                                <form method="POST" action="{{ route('user.facebook.posts.publish', $post->id) }}" class="inline">@csrf
                                                    <button class="w-8 h-8 rounded-lg grid place-items-center text-wa-deep hover:bg-wa-mint transition" title="{{ __('Publish now') }}">
                                                        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2 7 9M14 2l-4.5 12-2.5-5-5-2.5L14 2Z"/></svg>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('user.facebook.posts.destroy', $post->id) }}" class="inline"
                                                data-confirm="{{ $post->status === 'published' ? __('This deletes the post from Facebook too — it cannot be undone.') : __('Remove this post?') }}"
                                                data-confirm-title="{{ __('Delete post') }}" data-confirm-text="{{ __('Yes, delete') }}" data-danger="1">@csrf @method('DELETE')
                                                <button class="w-8 h-8 rounded-lg grid place-items-center text-ink-400 hover:text-accent-coral hover:bg-accent-coral/10 transition" title="{{ __('Delete') }}">
                                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-16 text-center">
                                        <div class="text-[13px] text-ink-800 font-semibold">{{ $total > 0 ? __('No posts match these filters') : __('No posts yet') }}</div>
                                        <p class="text-[12px] text-ink-400 mt-1 mb-3">{{ __('Compose your first post — publish now or schedule it.') }}</p>
                                        <a href="{{ route('user.facebook.posts.create') }}" class="inline-flex px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Create post') }}</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($posts->hasPages())
                    <div class="px-5 py-3 border-t border-paper-200">{{ $posts->withQueryString()->links() }}</div>
                @endif
            </div>
        @endif
    </main>
</x-layouts.user>
