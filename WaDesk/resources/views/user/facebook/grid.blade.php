@php
    use Illuminate\Support\Str;
    /** @var \Illuminate\Support\Collection $pages */
    /** @var \App\Models\FacebookPage|null $page */
    /** @var array $items */
@endphp

<x-layouts.user :title="__('My Facebook posts')" nav-key="facebook-my-posts" page="user-facebook-posts-grid">

    <div class="w-full px-4 sm:px-6 lg:px-7 py-6" id="fb-grid"
         data-page-name="{{ $page?->name ?: $page?->page_id }}">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4 flex-wrap mb-5">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Facebook') }} · {{ __('My posts') }}</div>
                <h1 class="font-serif text-[28px] leading-tight mt-0.5">{{ __('Your') }} <span class="italic text-wa-deep">{{ __('grid') }}</span></h1>
                <p class="text-[12.5px] text-ink-600 mt-1">{{ __('Your recently published Page posts — live from Facebook. Tap any post to preview it.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.facebook.posts') }}"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium transition">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>{{ __('Posts') }}
                </a>
                <a href="{{ route('user.facebook.posts.create') }}"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal transition">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 3.5v9M3.5 8h9"/></svg>{{ __('New post') }}
                </a>
                @if ($pages->count() > 1)
                    <form method="GET" action="{{ route('user.facebook.my-posts') }}">
                        <select name="page" onchange="this.form.submit()"
                            class="h-9 rounded-full border border-paper-200 bg-paper-0 pl-3 pr-8 text-[12.5px] font-medium focus:outline-none focus:border-wa-deep">
                            @foreach ($pages as $p)
                                <option value="{{ $p->id }}" {{ $p->id === $selected ? 'selected' : '' }}>{{ $p->name ?: $p->page_id }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </div>
        </div>

        @if (! $page)
            <div class="rounded-2xl border border-dashed border-paper-300 bg-paper-50/60 p-10 text-center">
                <div class="text-[14px] font-semibold text-ink-800">{{ __('No Facebook Page connected') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1">{{ __('Connect one from Devices to see your grid here.') }}</p>
                <a href="{{ url('/devices') }}" class="inline-block mt-3 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold">{{ __('Go to Devices') }}</a>
            </div>
        @elseif (empty($items))
            <div class="rounded-2xl border border-dashed border-paper-300 bg-paper-50/60 p-14 text-center">
                <div class="text-[14px] font-semibold text-ink-800">{{ __('Nothing here yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1">{{ __('Posts you publish will show up here.') }}</p>
                <a href="{{ route('user.facebook.posts') }}" class="inline-block mt-3 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold">{{ __('Create your first post') }}</a>
            </div>
        @else
            {{-- Grid — square tiles, hover reveals the message --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-1.5">
                @foreach ($items as $m)
                    @php
                        $img  = $m['full_picture'] ?? '';
                        $tile = [
                            'id'        => $m['id'] ?? '',
                            'message'   => $m['message'] ?? '',
                            'image'     => $img,
                            'permalink' => $m['permalink_url'] ?? '',
                            'created'   => $m['created_time'] ?? '',
                        ];
                    @endphp
                    <button type="button" class="fb-post-tile group relative block aspect-square overflow-hidden rounded-lg bg-paper-100 border border-paper-200" data-post='@json($tile)'>
                        @if ($img)
                            <img src="{{ $img }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                        @else
                            <span class="w-full h-full grid place-items-center text-ink-300 p-3 text-center">
                                <span class="text-[11.5px] text-ink-500 line-clamp-4">{{ Str::limit($m['message'] ?? __('(no caption)'), 90) }}</span>
                            </span>
                        @endif
                        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/45 transition flex items-end p-3 text-white opacity-0 group-hover:opacity-100">
                            <span class="text-[11.5px] leading-snug line-clamp-3">{{ Str::limit($m['message'] ?? '', 100) ?: __('View post') }}</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ===== Preview modal ===== --}}
    <div id="fb-post-modal" class="hidden fixed inset-0 z-50 p-3 sm:p-6" aria-modal="true" role="dialog">
        <div class="absolute inset-0 bg-black/80" data-close-post></div>
        <button type="button" data-close-post aria-label="{{ __('Close') }}" class="absolute top-3 right-3 z-10 w-9 h-9 rounded-full grid place-items-center text-white/80 hover:text-white hover:bg-white/10 transition">
            <svg viewBox="0 0 20 20" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>
        </button>
        <div class="relative h-full grid place-items-center">
            <div class="w-full max-w-[960px] max-h-[88vh] bg-paper-0 rounded-2xl overflow-hidden flex flex-col md:flex-row shadow-[0_28px_80px_-30px_rgba(0,0,0,0.6)]">
                <div class="flex-1 min-h-0 basis-[45%] md:basis-auto bg-black flex items-center justify-center overflow-hidden">
                    <div id="fb-post-media" class="w-full h-full flex items-center justify-center"></div>
                </div>
                <div class="w-full md:w-[360px] shrink-0 flex flex-col min-h-0 border-l border-paper-200">
                    <div class="px-4 py-3 border-b border-paper-200 flex items-center gap-2.5 shrink-0">
                        <span class="w-8 h-8 rounded-full grid place-items-center shrink-0" style="background:#1877F2">
                            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13px] font-semibold truncate">{{ $page?->name ?: __('My Page') }}</div>
                            <div id="fb-post-when" class="text-[10.5px] text-ink-400"></div>
                        </div>
                        <a id="fb-post-permalink" href="#" target="_blank" rel="noopener" title="{{ __('Open on Facebook') }}"
                            class="w-8 h-8 rounded-full grid place-items-center text-ink-500 hover:bg-paper-100 transition">
                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6.5 3H3.5v9.5H13V9.5"/><path d="M9.5 3H13v3.5M13 3 7.5 8.5"/></svg>
                        </a>
                    </div>
                    <div id="fb-post-message" class="flex-1 min-h-0 overflow-y-auto px-4 py-3.5 text-[13px] text-ink-800 whitespace-pre-wrap break-words leading-relaxed"></div>
                    <div class="px-4 py-2.5 border-t border-paper-200 shrink-0">
                        <a id="fb-post-open" href="#" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-wa-deep hover:underline">{{ __('Open on Facebook') }}
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3h7v7M13 3 4 12"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.user>
