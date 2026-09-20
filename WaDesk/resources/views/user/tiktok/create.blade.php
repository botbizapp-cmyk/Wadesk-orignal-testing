@php
    $tiktokGlyph = '<path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/>';
    $maxDuration = (int) (data_get($creator, 'max_video_post_duration_sec') ?: 0);
    $nickname    = (string) (data_get($creator, 'creator_nickname') ?: '');
@endphp

<x-layouts.user :title="__('Create TikTok post')" nav-key="tiktok-posts" page="user-tiktok-create">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('TikTok') }} · {{ __('Compose') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Create a') }} <span class="italic text-wa-deep">{{ __('post') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Send a video to your TikTok inbox by URL. You finish and publish it from the TikTok app. Direct publishing unlocks after TikTok audits the app.') }}</p>
            </div>
            <a href="{{ route('user.tiktok.posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium shrink-0">{{ __('Posts') }}</a>
        </div>

        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($accounts->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $tiktokGlyph !!}</svg></span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a TikTok account first.') }}</p>
                <a href="{{ route('user.tiktok.accounts') }}" class="mt-3 inline-flex px-4 py-2 rounded-full bg-ink-900 text-paper-0 text-[12.5px] font-semibold hover:bg-ink-800">{{ __('Go to accounts') }}</a>
            </div>
        @else
            <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6 items-start" data-tt-composer>
                <form method="POST" action="{{ route('user.tiktok.posts.store') }}" enctype="multipart/form-data" class="card bg-paper-0 border border-paper-200 rounded-2xl shadow-card">
                    @csrf
                    <input type="hidden" name="post_type" id="tt-post-type" value="video">

                    {{-- Account --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">01</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Account') }}</span>
                        </div>
                        <div class="relative">
                            <select name="tiktok_account_id" id="tt-account" class="w-full h-11 rounded-xl border border-paper-200 bg-paper-0 pl-11 pr-9 text-sm font-semibold appearance-none focus:outline-none focus:border-wa-deep">
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}">{{ $a->display_name ?: ('@' . ltrim((string) $a->username, '@')) }}</option>
                                @endforeach
                            </select>
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 w-7 h-7 rounded-full grid place-items-center bg-ink-900"><svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff">{!! $tiktokGlyph !!}</svg></span>
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute right-3.5 top-1/2 -translate-y-1/2 text-ink-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6l4 4 4-4"/></svg>
                        </div>
                    </div>

                    {{-- Media --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">02</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Media') }}</span>
                            <span class="font-mono text-[10px] text-ink-500">{{ __('required') }}</span>
                        </div>

                        {{-- Video / Photo toggle --}}
                        <div class="grid grid-cols-2 gap-2 mb-3" id="tt-type-tabs">
                            <button type="button" data-tt-type="video" class="tt-type-btn py-2 rounded-xl border text-[12.5px] font-semibold border-ink-900 bg-ink-900/5 text-ink-900 transition flex items-center justify-center gap-1.5">
                                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.5" y="3.5" width="11" height="9" rx="2"/><path d="M6.5 6l3 2-3 2z"/></svg>{{ __('Video') }}
                            </button>
                            <button type="button" data-tt-type="photo" class="tt-type-btn py-2 rounded-xl border text-[12.5px] font-semibold border-paper-200 text-ink-600 hover:bg-paper-50 transition flex items-center justify-center gap-1.5">
                                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.5" y="2.5" width="11" height="11" rx="2"/><circle cx="6" cy="6" r="1.3"/><path d="M3.5 11l3-3 2.5 2.5L11 8l1.5 1.5"/></svg>{{ __('Photos') }}
                            </button>
                        </div>

                        {{-- VIDEO media --}}
                        <div data-tt-media="video" class="space-y-3">
                            <label id="tt-video-drop" for="tt-video"
                                class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-paper-300 bg-paper-50/50 px-4 py-7 text-center cursor-pointer hover:border-ink-900 hover:bg-ink-900/5 transition">
                                <span class="w-10 h-10 rounded-full bg-paper-0 border border-paper-200 grid place-items-center text-ink-500">
                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 10.5V3.5M5 6l3-3 3 3"/><path d="M2.5 10v2a1.5 1.5 0 0 0 1.5 1.5h8A1.5 1.5 0 0 0 13.5 12v-2"/></svg>
                                </span>
                                <span id="tt-video-label" class="text-[12.5px] font-semibold text-ink-800">{{ __('Click to upload a video or drag & drop') }}</span>
                                <span class="text-[11px] text-ink-400">{{ __('MP4 / MOV · up to 300MB') }}</span>
                            </label>
                            <input type="file" name="video" id="tt-video" accept="video/mp4,video/quicktime" class="hidden">
                            <label class="block">
                                <span class="text-[11px] font-semibold text-ink-700">{{ __('…or paste a public video URL') }}</span>
                                <input name="video_url" id="tt-video-url" type="url" value="{{ old('video_url') }}" placeholder="https://cdn.example.com/clip.mp4"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-ink-900">
                            </label>
                        </div>

                        {{-- PHOTO media --}}
                        <div data-tt-media="photo" class="hidden space-y-3">
                            <label id="tt-photo-drop" for="tt-photos"
                                class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-paper-300 bg-paper-50/50 px-4 py-7 text-center cursor-pointer hover:border-ink-900 hover:bg-ink-900/5 transition">
                                <span class="w-10 h-10 rounded-full bg-paper-0 border border-paper-200 grid place-items-center text-ink-500">
                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.5" y="2.5" width="11" height="11" rx="2"/><circle cx="6" cy="6" r="1.3"/><path d="M3.5 11l3-3 2.5 2.5L11 8l1.5 1.5"/></svg>
                                </span>
                                <span id="tt-photo-label" class="text-[12.5px] font-semibold text-ink-800">{{ __('Click to upload photos or drag & drop') }}</span>
                                <span class="text-[11px] text-ink-400">{{ __('JPG / PNG · up to 35 images') }}</span>
                            </label>
                            <input type="file" name="photos[]" id="tt-photos" accept="image/*" multiple class="hidden">
                            <label class="block">
                                <span class="text-[11px] font-semibold text-ink-700">{{ __('…or paste image URLs') }}</span>
                                <textarea name="photo_urls" id="tt-photo-urls" rows="2" placeholder="https://img1.jpg&#10;https://img2.jpg"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-ink-900">{{ old('photo_urls') }}</textarea>
                            </label>
                        </div>

                        <p class="text-[11px] text-ink-500 mt-3">{{ __('Uploads are fetched by TikTok over public HTTPS, so publishing from a live domain (or a verified URL-prefix) is required. The post lands in your TikTok inbox to finish in the app.') }}</p>
                    </div>

                    {{-- Caption --}}
                    <div class="px-[18px] py-4 border-b border-paper-200">
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-50 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">03</span>
                            <span class="font-serif text-[18px] leading-none text-ink-900 flex-1">{{ __('Caption') }}</span>
                            <span id="tt-cap-count" class="font-mono text-[10px] text-ink-500">0</span>
                        </div>
                        <textarea name="caption" id="tt-caption" rows="4" maxlength="2200" placeholder="{{ __('Add a caption, #hashtags, @mentions…') }}"
                            class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13.5px] resize-y focus:outline-none focus:border-wa-deep">{{ old('caption') }}</textarea>
                        <p class="text-[11px] text-ink-400 mt-2">{{ __('You can edit the caption again in the TikTok app before posting.') }}</p>
                    </div>

                    <div class="px-[18px] py-3.5 flex items-center justify-between gap-3">
                        <span class="text-[11px] text-ink-500">
                            @if ($nickname){{ __('Posting to :name', ['name' => $nickname]) }}@endif
                            @if ($maxDuration) · {{ __('max :s s', ['s' => $maxDuration]) }}@endif
                        </span>
                        <button type="submit" class="px-5 py-2.5 rounded-full bg-ink-900 text-paper-0 text-[13px] font-semibold hover:bg-ink-800 flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 8l5 5 7-9"/></svg>{{ __('Send to TikTok') }}
                        </button>
                    </div>
                </form>

                {{-- Live preview (compact vertical card) --}}
                <div class="xl:sticky xl:top-6 self-start">
                    <div class="card bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-3">
                        <div class="flex items-center justify-between mb-2 px-1">
                            <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-wa-green animate-pulse"></span>{{ __('Live preview') }}</div>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-paper-50 text-ink-700 font-mono"><svg viewBox="0 0 24 24" class="w-3 h-3" fill="currentColor">{!! $tiktokGlyph !!}</svg>{{ __('TikTok') }}</span>
                        </div>
                        <div class="relative aspect-[9/16] rounded-[16px] overflow-hidden bg-ink-900 mx-auto max-w-[260px]">
                            <span class="absolute inset-0 grid place-items-center text-white/30"><svg viewBox="0 0 24 24" class="w-10 h-10" fill="currentColor">{!! $tiktokGlyph !!}</svg></span>
                            <div class="absolute inset-x-0 bottom-0 p-3 bg-gradient-to-t from-black/80 to-transparent">
                                <div class="text-white text-[12px] font-semibold mb-0.5">{{ '@' . ltrim((string) ($accounts->first()->username ?: 'you'), '@') }}</div>
                                <div id="tt-prev-caption" class="text-white/90 text-[11.5px] leading-snug whitespace-pre-wrap break-words line-clamp-3">{{ __('Your caption will appear here…') }}</div>
                            </div>
                        </div>
                    </div>
                    <p class="text-[10.5px] text-ink-400 mt-2 text-center">{{ __('Preview only — the final post is completed in the TikTok app.') }}</p>
                </div>
            </div>
        @endif
    </main>
</x-layouts.user>
