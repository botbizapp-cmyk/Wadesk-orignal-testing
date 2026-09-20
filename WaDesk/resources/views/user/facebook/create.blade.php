@php
    use Illuminate\Support\Str;

    $currentStatus = $currentStatus ?? 'all';
    $currentType   = $currentType ?? 'all';
    $currentRange  = $currentRange ?? 'all';
    $currentSearch = $currentSearch ?? '';
    $typeCounts    = $typeCounts ?? ['all' => 0];
    $total         = $total ?? ($typeCounts['all'] ?? 0);
    $userTz        = $userTz ?? config('app.timezone', 'UTC');
    $aiAllowed     = $aiAllowed ?? false;

    // Build a filter URL preserving the OTHER active filters (mirrors IG index).
    $filterUrl = function (array $override) use ($currentStatus, $currentType, $currentRange, $currentSearch) {
        $q = array_filter([
            'status' => $override['status'] ?? ($currentStatus !== 'all' ? $currentStatus : null),
            'type'   => $override['type']   ?? ($currentType   !== 'all' ? $currentType   : null),
            'range'  => $override['range']  ?? ($currentRange  !== 'all' ? $currentRange  : null),
            'q'      => $override['q']       ?? ($currentSearch !== '' ? $currentSearch : null),
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'all');
        return route('user.facebook.posts') . ($q ? '?' . http_build_query($q) : '');
    };

    $statusPill = fn (string $s) => match ($s) {
        'scheduled' => ['cls' => 'bg-accent-amber/15 text-[#7B5A14]', 'label' => __('Scheduled')],
        'published' => ['cls' => 'bg-wa-mint text-wa-deep',            'label' => __('Published')],
        'failed'    => ['cls' => 'bg-accent-coral/10 text-accent-coral', 'label' => __('Failed')],
        'draft'     => ['cls' => 'bg-paper-100 text-ink-600',          'label' => __('Draft')],
        default     => ['cls' => 'bg-paper-100 text-ink-600',          'label' => Str::title($s)],
    };

    $statusTabs = [
        ['key' => 'all',       'label' => __('All'),        'count' => $total],
        ['key' => 'published', 'label' => __('Published'),  'count' => $counts['published'] ?? 0],
        ['key' => 'scheduled', 'label' => __('Scheduled'),  'count' => $counts['scheduled'] ?? 0],
        ['key' => 'failed',    'label' => __('Failed'),     'count' => $counts['failed'] ?? 0],
    ];

    $typeTabs = [
        ['key' => 'photo',       'label' => __('Photo'),   'hint' => __('A single photo post.'),
            'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="2"/><circle cx="6" cy="6" r="1.3"/><path d="M3.5 11l3-3 2.5 2.5L11 8l1.5 1.5"/>'],
        ['key' => 'multi_photo', 'label' => __('Album'),   'hint' => __('2–10 photos in one post.'),
            'icon' => '<rect x="4" y="4" width="8" height="8" rx="1.5"/><path d="M2.5 5.5v5M13.5 5.5v5"/>'],
        ['key' => 'link',        'label' => __('Link'),    'hint' => __('Share a link with a preview.'),
            'icon' => '<path d="M6.5 9.5a3 3 0 0 0 4 0l2-2a3 3 0 0 0-4-4l-1 1"/><path d="M9.5 6.5a3 3 0 0 0-4 0l-2 2a3 3 0 0 0 4 4l1-1"/>'],
        ['key' => 'video',       'label' => __('Video'),   'hint' => __('A feed video (MP4 / MOV).'),
            'icon' => '<rect x="2.5" y="3.5" width="11" height="9" rx="2"/><path d="M6.5 6l3 2-3 2z"/>'],
        ['key' => 'reel',        'label' => __('Reel'),    'hint' => __('A vertical short video reel.'),
            'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="2"/><path d="M6.5 5.5l4 2.5-4 2.5z"/>'],
    ];
@endphp

<x-layouts.user :title="__('Create post')" nav-key="facebook-posts" page="user-facebook-posts">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- ===== Header ===== --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    {{ __('Workspace') }} · {{ auth()->user()?->currentWorkspace?->name ?: __('Facebook') }}
                </div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Create a') }} <span class="italic text-wa-deep">{{ __('post') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Compose photos, albums, links, videos and reels — publish now or schedule them straight to your connected Pages. Watch it build in the live mobile preview.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
                <a href="{{ route('user.facebook.posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>
                    {{ __('Posts') }}
                </a>
                <a href="{{ route('user.facebook.my-posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
                    {{ __('My posts') }}
                </a>
                <a href="{{ route('user.facebook.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Insights') }}</a>
            </div>
        </div>

        {{-- ===== Flash + validation (inline, no scripts) ===== --}}
        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($pages->isEmpty())
            {{-- ===== Empty state — no Page connected ===== --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3" style="background:#1877F2">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                </span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('No Facebook Page connected yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 mb-4">{{ __('Connect a Facebook account to publish and schedule Page posts.') }}</p>
                <a href="{{ url('/devices') }}" class="inline-flex px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Connect a Facebook account') }}</a>
            </div>
        @else
            {{-- ===== Composer (CREATE only — the list lives on “My posts”) + live preview ===== --}}
            <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start" data-fb-composer-wrap>
                <form method="POST" action="{{ route('user.facebook.posts.store') }}" enctype="multipart/form-data"
                    class="card bg-paper-0 border border-paper-200 rounded-2xl shadow-card" data-fb-composer>
                    @csrf
                    <input type="hidden" name="as_reel" id="fb-as-reel" value="0">

                    {{-- 01 Page --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">01</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Page') }}</span>
                            <span class="font-mono text-[10px] text-ink-500">{{ __('required') }}</span>
                        </div>
                        <div class="relative">
                            <select name="facebook_page_id" id="fb-page"
                                class="w-full h-11 rounded-xl border border-paper-200 bg-paper-0 pl-11 pr-9 text-sm font-semibold appearance-none focus:outline-none focus:border-wa-deep">
                                @foreach ($pages as $p)
                                    <option value="{{ $p->id }}">{{ $p->name ?: $p->page_id }}</option>
                                @endforeach
                            </select>
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 w-7 h-7 rounded-full grid place-items-center" style="background:#1877F2">
                                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                            </span>
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute right-3.5 top-1/2 -translate-y-1/2 text-ink-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6l4 4 4-4"/></svg>
                        </div>
                    </div>

                    {{-- 02 Post type --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">02</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Post type') }}</span>
                        </div>
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2" id="fb-type-tabs">
                            @foreach ($typeTabs as $i => $t)
                                <button type="button" data-type="{{ $t['key'] }}" data-hint="{{ $t['hint'] }}"
                                    class="fb-type-btn flex flex-col items-center gap-1.5 py-2.5 rounded-xl border text-[11.5px] font-semibold transition
                                        {{ $i === 0 ? 'border-wa-deep bg-wa-deep/5 text-wa-deep' : 'border-paper-200 text-ink-600 hover:bg-paper-50' }}">
                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round">{!! $t['icon'] !!}</svg>
                                    {{ $t['label'] }}
                                </button>
                            @endforeach
                        </div>
                        <p id="fb-type-hint" class="text-[11px] text-ink-400 mt-2">{{ $typeTabs[0]['hint'] }}</p>
                    </div>

                    {{-- 03 Media --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">03</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Media') }}</span>
                        </div>

                        {{-- Photo / Album --}}
                        <div data-fb-media="photo" class="space-y-3">
                            <label id="fb-drop" for="fb-photos"
                                class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-paper-300 bg-paper-50/50 px-4 py-6 text-center cursor-pointer hover:border-wa-deep hover:bg-wa-deep/5 transition">
                                <span class="w-10 h-10 rounded-full bg-paper-0 border border-paper-200 grid place-items-center text-ink-500">
                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 10.5V3.5M5 6l3-3 3 3"/><path d="M2.5 10v2a1.5 1.5 0 0 0 1.5 1.5h8A1.5 1.5 0 0 0 13.5 12v-2"/></svg>
                                </span>
                                <span class="text-[12.5px] font-semibold text-ink-800">{{ __('Click to upload or drag & drop') }}</span>
                                <span class="text-[11px] text-ink-400">{{ __('JPG / PNG · up to 10 photos') }}</span>
                            </label>
                            <input type="file" name="photos[]" id="fb-photos" multiple accept="image/*" class="hidden">
                            <div id="fb-thumbs" class="hidden flex flex-wrap gap-2"></div>
                            <label class="block">
                                <span class="text-[11px] font-semibold text-ink-700">{{ __('…or paste image URLs') }}</span>
                                <textarea name="photo_urls" id="fb-photo-urls" rows="2" placeholder="https://img1.jpg&#10;https://img2.jpg"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep">{{ old('photo_urls') }}</textarea>
                            </label>
                        </div>

                        {{-- Link --}}
                        <div data-fb-media="link" class="hidden">
                            <label class="block">
                                <span class="text-[11px] font-semibold text-ink-700">{{ __('Link URL') }}</span>
                                <input name="link" type="url" value="{{ old('link') }}" placeholder="https://…"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                                <span class="block mt-1 text-[11px] text-ink-500">{{ __('Facebook builds a preview card from the link. A post can have a link or photos, not both.') }}</span>
                            </label>
                        </div>

                        {{-- Video / Reel --}}
                        <div data-fb-media="video" class="hidden space-y-3">
                            <div class="grid sm:grid-cols-2 gap-4">
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Upload a video') }} <span class="text-ink-400">{{ __('(mp4/mov)') }}</span></span>
                                    <input name="video" type="file" id="fb-video" accept="video/mp4,video/quicktime"
                                        class="mt-1 w-full text-[12px] file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-wa-mint file:text-wa-deep file:text-[12px] file:font-semibold">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-ink-700">{{ __('…or paste a video URL') }}</span>
                                    <input name="video_url" type="url" value="{{ old('video_url') }}" placeholder="https://…/clip.mp4"
                                        class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] font-mono focus:outline-none focus:border-wa-deep">
                                </label>
                            </div>
                            <p class="text-[11px] text-ink-500" data-fb-reel-note hidden>{{ __('Reels are vertical short videos. The message below is used as the reel description.') }}</p>
                            <p class="text-[11px] text-ink-500" data-fb-video-note>{{ __('Meta fetches the file over public HTTPS. The message below is used as the video description.') }}</p>
                        </div>
                    </div>

                    {{-- 04 Message + AI tools --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">04</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Message') }}</span>
                            <span id="fb-msg-count" class="font-mono text-[10px] text-ink-500">0</span>
                        </div>
                        <textarea name="message" id="fb-message" rows="5" placeholder="{{ __('Write your post…') }}"
                            class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13.5px] resize-y focus:outline-none focus:border-wa-deep">{{ old('message') }}</textarea>

                        {{-- AI composer tools (parity with Instagram) --}}
                        <div id="fb-ai" class="mt-3 rounded-xl border border-paper-200 bg-paper-50/60 p-3"
                            data-caption-url="{{ route('user.facebook.posts.ai.caption') }}"
                            data-repurpose-url="{{ route('user.facebook.posts.ai.repurpose') }}"
                            data-review-url="{{ route('user.facebook.posts.ai.review') }}"
                            data-besttime-url="{{ route('user.facebook.posts.ai.besttime') }}"
                            data-image-url="{{ route('user.facebook.posts.ai.image') }}">
                            <div class="flex items-center gap-2 mb-2.5">
                                <span class="w-6 h-6 rounded-lg grid place-items-center text-white shrink-0" style="background:#1877F2">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="currentColor"><path d="M8 1l1.6 4.4L14 7l-4.4 1.6L8 13l-1.6-4.4L2 7l4.4-1.6L8 1z"/></svg>
                                </span>
                                <span class="font-serif text-[15px] leading-none text-ink-900 flex-1">{{ __('AI composer tools') }}</span>
                            </div>
                            @unless ($aiAllowed)
                                <div class="rounded-lg bg-paper-0 border border-paper-200 px-3 py-2 text-[12px] text-ink-600">
                                    {{ __('AI tools aren\'t included in your current plan.') }}
                                </div>
                            @else
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                                    <button type="button" data-ai="caption"   class="fb-ai-btn text-[12px] font-semibold px-2 py-2 rounded-lg border border-paper-200 bg-paper-0 hover:border-wa-deep hover:text-wa-deep transition">{{ __('AI Caption') }}</button>
                                    <button type="button" data-ai="repurpose" class="fb-ai-btn text-[12px] font-semibold px-2 py-2 rounded-lg border border-paper-200 bg-paper-0 hover:border-wa-deep hover:text-wa-deep transition">{{ __('Repurpose') }}</button>
                                    <button type="button" data-ai="review"    class="fb-ai-btn text-[12px] font-semibold px-2 py-2 rounded-lg border border-paper-200 bg-paper-0 hover:border-wa-deep hover:text-wa-deep transition">{{ __('Review') }}</button>
                                    <button type="button" data-ai="best-time" class="fb-ai-btn text-[12px] font-semibold px-2 py-2 rounded-lg border border-paper-200 bg-paper-0 hover:border-wa-deep hover:text-wa-deep transition">{{ __('Best time') }}</button>
                                    <button type="button" data-ai="image"     class="fb-ai-btn text-[12px] font-semibold px-2 py-2 rounded-lg border border-paper-200 bg-paper-0 hover:border-[#1877F2] hover:text-[#1877F2] transition">{{ __('AI Image') }}</button>
                                </div>
                                <div id="fb-ai-imgrow" class="hidden mt-2 flex gap-2">
                                    <input type="text" id="fb-ai-prompt" maxlength="1000" placeholder="{{ __('Describe the image to generate…') }}"
                                        class="flex-1 h-9 rounded-lg border border-paper-200 bg-paper-0 px-3 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <button type="button" id="fb-ai-imggen" class="h-9 px-3 rounded-lg text-white text-[12px] font-semibold shrink-0" style="background:#1877F2">{{ __('Generate') }}</button>
                                </div>
                                <div id="fb-ai-out" class="hidden mt-2 rounded-lg bg-paper-0 border border-paper-200 px-3 py-2 text-[12.5px] text-ink-700 whitespace-pre-line"></div>
                            @endunless
                        </div>
                    </div>

                    {{-- 05 When to post --}}
                    <div class="px-[18px] py-4">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">05</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('When to post') }}</span>
                        </div>
                        <input type="hidden" name="schedule" id="fb-schedule" value="0">
                        <div class="grid grid-cols-2 gap-2 mb-2" id="fb-when-tabs">
                            <button type="button" data-when="now"
                                class="fb-when-btn py-2.5 rounded-xl border text-[12.5px] font-semibold border-wa-deep bg-wa-deep/5 text-wa-deep transition">{{ __('Publish now') }}</button>
                            <button type="button" data-when="later"
                                class="fb-when-btn py-2.5 rounded-xl border text-[12.5px] font-semibold border-paper-200 text-ink-600 hover:bg-paper-50 transition">{{ __('Schedule for later') }}</button>
                        </div>
                        <div id="fb-when-later" class="hidden">
                            <label for="fb-scheduled-at" class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('Send date & time') }}</label>
                            <input type="datetime-local" name="scheduled_at" id="fb-scheduled-at" value="{{ old('scheduled_at') }}"
                                class="w-full sm:w-auto h-11 mt-1 rounded-xl border border-paper-200 bg-paper-0 px-3 text-sm focus:outline-none focus:border-wa-deep">
                            <p class="mt-2 text-[11px] text-ink-500">{{ __('Between 10 minutes and 75 days from now, in your timezone (:tz). It publishes automatically when due.', ['tz' => $userTz]) }}</p>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-[18px] py-3.5 border-t border-paper-200 flex items-center justify-end gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 8l5 5 7-9"/></svg>
                            <span id="fb-submit-label">{{ __('Publish') }}</span>
                        </button>
                    </div>
                </form>

                {{-- ===== Live preview — clean card (mirrors /instagram/posts/create) ===== --}}
                <div class="xl:sticky xl:top-6 self-start" data-fb-preview>
                    <div class="card bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-3">
                        <div class="flex items-center justify-between mb-2 px-1">
                            <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-wa-green animate-pulse"></span>{{ __('Live preview') }}
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-paper-50 text-ink-700 font-mono">
                                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="#1877F2"><path d="M16 8a8 8 0 1 0-9.25 7.9v-5.59H4.72V8h2.03V6.24c0-2 1.19-3.11 3.02-3.11.87 0 1.79.16 1.79.16v1.97h-1.01c-.99 0-1.3.62-1.3 1.25V8h2.22l-.35 2.31H9.25v5.59A8 8 0 0 0 16 8Z"/></svg>
                                {{ __('Facebook') }}
                            </span>
                        </div>

                        {{-- The post card --}}
                        <div class="rounded-[16px] border border-paper-200 overflow-hidden bg-paper-0 max-w-[320px] mx-auto">
                            {{-- header --}}
                            <div class="flex items-center gap-2.5 px-3 py-2.5">
                                <span class="w-9 h-9 rounded-full grid place-items-center text-white text-[14px] font-semibold shrink-0" style="background:#1877F2" data-prev-avatar>{{ strtoupper(mb_substr($pages->first()->name ?? 'F', 0, 1)) }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[13px] font-semibold text-ink-800 truncate leading-tight" data-prev-name>{{ $pages->first()->name ?? __('Your Page') }}</div>
                                    <div class="text-[10.5px] text-ink-400 flex items-center gap-1">{{ __('Just now') }} · <svg viewBox="0 0 16 16" class="w-2.5 h-2.5" fill="currentColor"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM3 8a5 5 0 0 1 1-3l1 1v1l1 1v1l1 1v1H6l-1-1H4l-1-1zm5 5v-1l1-1 1-1v-1l1-1h1a5 5 0 0 1-4 5z"/></svg></div>
                                </div>
                                <svg viewBox="0 0 16 16" class="w-4 h-4 text-ink-400" fill="currentColor"><circle cx="3" cy="8" r="1.2"/><circle cx="8" cy="8" r="1.2"/><circle cx="13" cy="8" r="1.2"/></svg>
                            </div>

                            {{-- message --}}
                            <div data-prev-msg class="px-3 pb-2 text-[13px] text-ink-800 whitespace-pre-wrap break-words leading-relaxed empty:hidden"></div>
                            <div data-prev-msg-empty class="px-3 pb-2 text-[12.5px] text-ink-400 italic">{{ __('Your post text will appear here…') }}</div>

                            {{-- media --}}
                            <div data-prev-media class="hidden bg-paper-100"></div>

                            {{-- reactions summary --}}
                            <div class="flex items-center justify-between px-3 py-1.5 text-[10.5px] text-ink-400 border-t border-paper-100">
                                <span class="flex items-center gap-1"><span class="w-3.5 h-3.5 rounded-full grid place-items-center text-white shrink-0" style="background:#1877F2"><svg viewBox="0 0 16 16" class="w-2 h-2" fill="currentColor"><path d="M4 7v6H2V7zM6 13V7l2.5-4c.8 0 1.3.6 1.1 1.4L9 6h3.5c.8 0 1.3.8 1 1.5l-1.4 4.2c-.2.7-.8 1.3-1.6 1.3z"/></svg></span>{{ __('You and 12 others') }}</span>
                                <span>3 {{ __('shares') }}</span>
                            </div>

                            {{-- action buttons --}}
                            <div class="grid grid-cols-3 border-t border-paper-100 text-[12px] text-ink-600 font-medium">
                                <span class="flex items-center justify-center gap-1.5 py-2"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M4 7v6H2V7zM6 13V7l2.5-4c.8 0 1.3.6 1.1 1.4L9 6h3.5c.8 0 1.3.8 1 1.5l-1.4 4.2c-.2.7-.8 1.3-1.6 1.3z"/></svg>{{ __('Like') }}</span>
                                <span class="flex items-center justify-center gap-1.5 py-2 border-x border-paper-100"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M2.5 4.5A1.5 1.5 0 0 1 4 3h8a1.5 1.5 0 0 1 1.5 1.5v5A1.5 1.5 0 0 1 12 11H6l-3 2.5V11a1.5 1.5 0 0 1-.5-1.1z"/></svg>{{ __('Comment') }}</span>
                                <span class="flex items-center justify-center gap-1.5 py-2"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M9 3l5 4-5 4V8.5C5 8.5 3 10 2.5 13 2 8 5 5.5 9 5.5z"/></svg>{{ __('Share') }}</span>
                            </div>
                        </div>
                    </div>
                    <p class="text-[10.5px] text-ink-400 mt-2 text-center">{{ __('Live preview — the real post may look slightly different on Facebook.') }}</p>
                </div>
            </div>

            {{-- ===== Help cards ===== --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-5 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Help - 01') }}</div>
                    <div class="font-serif font-normal tracking-[-0.01em] text-[20px] mb-1">{{ __('What can I post?') }}</div>
                    <p class="text-[12.5px] text-ink-600 leading-relaxed">{{ __('Photos, albums, links, feed videos and reels publish straight to your connected Page through the Graph API.') }}</p>
                </div>
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-5 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Help - 02') }}</div>
                    <div class="font-serif font-normal tracking-[-0.01em] text-[20px] mb-1">{{ __('How does scheduling work?') }}</div>
                    <p class="text-[12.5px] text-ink-600 leading-relaxed">{{ __('Pick a time between 10 minutes and 75 days out and Facebook queues it — it publishes automatically when due. Or publish now.') }}</p>
                </div>
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-5 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Help - 03') }}</div>
                    <div class="font-serif font-normal tracking-[-0.01em] text-[20px] mb-1">{{ __('A post failed — why?') }}</div>
                    <p class="text-[12.5px] text-ink-600 leading-relaxed">{{ __('Usually the media URL was not public HTTPS, or the Page was reconnected. Read the error on the row, then retry with Publish now.') }}</p>
                </div>
            </div>
        @endif
    </main>
</x-layouts.user>
