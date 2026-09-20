@php
    use App\Services\Social\SocialPostAggregator;
    $chColor = ['instagram' => '#C13584', 'facebook' => '#1877F2', 'tiktok' => '#010101'];
    $chGlyph = [
        'instagram' => '<rect x="2.5" y="2.5" width="11" height="11" rx="3.2"/><circle cx="8" cy="8" r="2.6"/><circle cx="11.3" cy="4.7" r="0.7" fill="currentColor" stroke="none"/>',
        'facebook'  => '<path d="M9.5 5.2H8.4c-.6 0-1 .4-1 1V8H9.2l-.3 1.9H7.4v3.6"/>',
        'tiktok'    => '<path d="M10.4 2.5v7.8a2 2 0 1 1-1.8-2v-1.7a3.7 3.7 0 1 0 3.2 3.66V6.1a4.7 4.7 0 0 0 2.7.86V5.3a2.8 2.8 0 0 1-2.6-2.8z" fill="currentColor" stroke="none"/>',
    ];
    $navUrl = fn ($date, $plat) => url('/social/calendar').'?'.http_build_query(array_filter(['date' => $date, 'platform' => $plat]));
    $dows = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
@endphp
<x-layouts.user :title="__('Content calendar')" nav-key="social-calendar" page="user-social-calendar">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-5">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Social') }} · {{ __('Calendar') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Content') }} <span class="italic text-wa-deep">{{ __('calendar') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2">{{ $monthCount }} {{ __('scheduled this month across all channels.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ url('/social/posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All posts') }}</a>
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

        {{-- Month nav + platform filter --}}
        <div class="flex items-center justify-between gap-3 flex-wrap bg-paper-0 border border-paper-200 rounded-2xl shadow-card px-4 py-3">
            <div class="flex items-center gap-2">
                <a href="{{ $navUrl($prev, $channel) }}" class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3L5 8l5 5"/></svg></a>
                <div class="font-serif text-[18px] min-w-[150px] text-center">{{ $anchor->format('F Y') }}</div>
                <a href="{{ $navUrl($next, $channel) }}" class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3l5 5-5 5"/></svg></a>
                <a href="{{ $navUrl($today, $channel) }}" class="ml-1 px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Today') }}</a>
            </div>
            <div class="flex items-center gap-1.5">
                <a href="{{ $navUrl($anchor->format('Y-m-d'), null) }}" class="px-3 py-1.5 rounded-full text-[12px] {{ ! $channel ? 'bg-ink-900 text-paper-0 font-semibold' : 'border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-700' }}">{{ __('All') }}</a>
                @foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $k => $lbl)
                    <a href="{{ $navUrl($anchor->format('Y-m-d'), $k) }}" class="px-3 py-1.5 rounded-full text-[12px] inline-flex items-center gap-1.5 {{ $channel === $k ? 'text-paper-0 font-semibold' : 'border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-700' }}" @if($channel===$k) style="background: {{ $chColor[$k] }}" @endif><svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$k] !!}</svg>{{ $lbl }}</a>
                @endforeach
            </div>
        </div>

        {{-- Month grid --}}
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
            <div class="grid grid-cols-7 border-b border-paper-200 bg-paper-50/50">
                @foreach ($dows as $d)<div class="px-3 py-2 font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 text-center">{{ $d }}</div>@endforeach
            </div>
            @foreach ($weeks as $week)
                <div class="grid grid-cols-7 border-b border-paper-100 last:border-b-0">
                    @foreach ($week as $cell)
                        <div class="min-h-[116px] border-r border-paper-100 last:border-r-0 p-1.5 group relative {{ $cell['in_month'] ? '' : 'bg-paper-50/40' }}" data-day-cell data-date="{{ $cell['key'] }}">
                            <div class="flex items-center justify-between px-1">
                                <span class="text-[11px] {{ $cell['is_today'] ? 'w-5 h-5 grid place-items-center rounded-full bg-wa-deep text-paper-0 font-semibold' : ($cell['in_month'] ? 'text-ink-700' : 'text-ink-300') }}">{{ $cell['date']->format('j') }}</span>
                                <button type="button" data-schedule-at="{{ $cell['key'] }}" class="opacity-0 group-hover:opacity-100 w-5 h-5 rounded-full bg-wa-deep text-paper-0 grid place-items-center transition" title="{{ __('Schedule a post') }}"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10"/></svg></button>
                            </div>
                            <div class="mt-1 space-y-1">
                                @foreach ($cell['posts']->take(4) as $p)
                                    @php $ring = $p['status'] === 'failed' ? 'ring-1 ring-accent-coral' : ''; @endphp
                                    <a href="{{ ['instagram'=>url('/instagram/posts'),'facebook'=>url('/facebook/posts'),'tiktok'=>url('/tiktok/posts')][$p['channel']] }}"
                                        class="flex items-center gap-1.5 px-1.5 py-1 rounded-md {{ $ring }} hover:brightness-95" style="background: {{ $chColor[$p['channel']] }}14" title="{{ $p['scheduled_at']->timezone($tz)->format('g:i A') }} · {{ $p['account_name'] }} · {{ \Illuminate\Support\Str::limit(strip_tags($p['text']),80) }}">
                                        <span class="w-3.5 h-3.5 rounded grid place-items-center shrink-0" style="color: {{ $chColor[$p['channel']] }}"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$p['channel']] !!}</svg></span>
                                        <span class="text-[10.5px] text-ink-800 truncate flex-1">{{ $p['scheduled_at']->timezone($tz)->format('g:i A') }} {{ \Illuminate\Support\Str::limit(strip_tags($p['text']) ?: 'Post', 16) }}</span>
                                    </a>
                                @endforeach
                                @if ($cell['posts']->count() > 4)<div class="text-[10px] text-ink-400 px-1.5">+{{ $cell['posts']->count() - 4 }} {{ __('more') }}</div>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        {{-- Upcoming --}}
        @if ($upcoming->isNotEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">{{ __('Upcoming scheduled') }}</div>
                <div class="divide-y divide-paper-100">
                    @foreach ($upcoming as $p)
                        <div class="flex items-center gap-3 py-2.5">
                            <span class="w-8 h-8 rounded-lg grid place-items-center text-paper-0 shrink-0" style="background: {{ $chColor[$p['channel']] }}"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$p['channel']] !!}</svg></span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[12.5px] text-ink-800 truncate">{{ \Illuminate\Support\Str::limit(strip_tags($p['text']) ?: 'Post', 90) }}</div>
                                <div class="font-mono text-[10.5px] text-ink-500">{{ $p['scheduled_at']->timezone($tz)->format('D j M, g:i A') }} · {{ $p['account_name'] }}</div>
                            </div>
                            <a href="{{ ['instagram'=>url('/instagram/posts'),'facebook'=>url('/facebook/posts'),'tiktok'=>url('/tiktok/posts')][$p['channel']] }}" class="text-[11px] text-wa-deep font-semibold hover:underline shrink-0">{{ __('Manage') }}</a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </main>

    {{-- Scheduler drawer — opens on a day "+" (or the toolbar's New Post). Pick an
         account across channels, compose, and schedule. Submits to /social/schedule. --}}
    <div data-sched-overlay class="hidden fixed inset-0 z-[70] bg-[rgba(11,31,28,0.4)]"></div>
    <aside data-sched-drawer class="hidden fixed top-0 right-0 z-[71] h-full w-full max-w-[420px] bg-paper-0 border-l border-paper-200 shadow-2xl flex flex-col">
        <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between">
            <div class="font-serif text-[18px]">{{ __('Schedule a post') }}</div>
            <button type="button" data-sched-close class="w-8 h-8 rounded-full border border-paper-200 hover:bg-paper-50 grid place-items-center"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l8 8M12 4l-8 8"/></svg></button>
        </div>
        <form data-sched-form class="flex-1 overflow-y-auto p-5 space-y-4" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="channel" data-sched-channel value="">
            <input type="hidden" name="account_id" data-sched-account value="">

            <div>
                <div class="text-[11px] font-semibold text-ink-700 mb-1.5">{{ __('Post to') }}</div>
                @if (count($accounts))
                    <div class="space-y-1.5 max-h-40 overflow-y-auto border border-paper-200 rounded-xl p-1.5">
                        @foreach ($accounts as $a)
                            <label class="flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg hover:bg-paper-50 cursor-pointer">
                                <input type="radio" name="acct" data-channel="{{ $a['channel'] }}" data-account="{{ $a['id'] }}" class="accent-wa-deep shrink-0">
                                <span class="w-5 h-5 rounded grid place-items-center text-paper-0 shrink-0" style="background: {{ $chColor[$a['channel']] }}"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.4">{!! $chGlyph[$a['channel']] !!}</svg></span>
                                <span class="text-[12.5px] text-ink-800 truncate">{{ $a['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <div class="text-[12px] text-ink-500 border border-paper-200 rounded-xl px-3 py-3">{{ __('Connect an Instagram, Facebook or TikTok account first.') }}</div>
                @endif
            </div>

            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Caption') }}</span>
                <textarea name="caption" rows="4" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[13px] resize-y focus:outline-none focus:border-wa-deep" placeholder="{{ __('Write your caption…') }}"></textarea></label>

            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Image') }}</span><input name="media" type="file" accept="image/*" class="mt-1 w-full text-[11.5px] file:mr-2 file:px-2.5 file:py-1 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[11.5px] file:font-semibold"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Video (reel)') }}</span><input name="media_video" type="file" accept="video/mp4,video/quicktime" class="mt-1 w-full text-[11.5px] file:mr-2 file:px-2.5 file:py-1 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[11.5px] file:font-semibold"></label>
            </div>
            <div data-sched-preview class="hidden rounded-xl overflow-hidden border border-paper-200"><img data-sched-preview-img class="w-full max-h-48 object-cover" alt=""></div>

            <div>
                <div class="text-[11px] font-semibold text-ink-700 mb-1.5">{{ __('When') }}</div>
                <div class="flex items-center gap-2">
                    <input type="date" name="date" data-sched-date class="flex-1 rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                    <input type="time" name="time" data-sched-time value="10:00" class="rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                </div>
                <label class="flex items-center gap-2 mt-2 text-[12px] text-ink-700"><input type="checkbox" name="publish_now" value="1" data-sched-now class="accent-wa-deep"> {{ __('Publish now instead') }}</label>
            </div>

            <div data-sched-error class="hidden text-[12px] text-accent-coral"></div>
        </form>
        <div class="px-5 py-4 border-t border-paper-200 flex items-center gap-2">
            <button type="button" data-sched-close class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium">{{ __('Cancel') }}</button>
            <button type="button" data-sched-submit class="flex-1 px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Schedule post') }}</button>
        </div>
    </aside>
</x-layouts.user>
