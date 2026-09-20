@php
    /** @var \Illuminate\Support\Collection $broadcasts */
    /** @var \Illuminate\Support\Collection $pages */
    /** @var array $audience */
    $fbBlue = '#1877F2';
    $firstPage = $pages->first();
    $statusPill = fn ($s) => match ($s) {
        'sending' => ['bg-accent-amber/15 text-[#7B5A14]', __('Sending')],
        'done'    => ['bg-wa-mint text-wa-deep', __('Done')],
        'failed'  => ['bg-accent-coral/10 text-accent-coral', __('Failed')],
        default   => ['bg-paper-100 text-ink-600', __('Draft')],
    };
@endphp

<x-layouts.user :title="__('Facebook broadcasts')" nav-key="facebook" page="user-facebook-broadcasts">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6"
        id="fb-broadcasts" data-audience='@json($audience)'>

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Facebook') }} · {{ __('Broadcasts') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Messenger') }} <span class="italic text-wa-deep">{{ __('broadcasts') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Message people who chatted with your Page recently. Meta only allows a Page to message someone within 24 hours of their last message — the list below is your reachable audience right now.') }}</p>
            </div>
            <a href="{{ url('/team-inbox') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium shrink-0">{{ __('Inbox') }}</a>
        </div>

        @if (session('status') || session('success'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') ?: session('success') }}</div>
        @endif
        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        @if ($pages->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white" style="background:{{ $fbBlue }}"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="currentColor"><path d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.6 4.5-4.6 1.3 0 2.6.2 2.6.2v2.9h-1.5c-1.5 0-1.9.9-1.9 1.8V12h3.3l-.5 3.5h-2.8v8.4A12 12 0 0 0 24 12z"/></svg></span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a Facebook Page first.') }}</p>
                <a href="{{ route('facebook.connect') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:{{ $fbBlue }}">{{ __('Connect a Page') }}</a>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">

                {{-- Broadcast list --}}
                <div class="space-y-3">
                    @forelse ($broadcasts as $b)
                        @php [$cls, $lbl] = $statusPill($b->status); @endphp
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4" data-bcast="{{ $b->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-[14px] font-semibold text-ink-900 truncate">{{ $b->name }}</div>
                                    <div class="text-[11px] text-ink-500 font-mono mt-0.5">@if($b->page){{ $b->page->name }} · @endif{{ __(':n recipients', ['n' => $b->total]) }}</div>
                                </div>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-mono shrink-0 {{ $cls }}" data-bcast-status>{{ $lbl }}</span>
                            </div>
                            <div class="mt-3 h-1.5 rounded-full bg-paper-100 overflow-hidden"><div class="h-full rounded-full bg-wa-deep transition-all" style="width:{{ $b->progress() }}%" data-bcast-bar></div></div>
                            <div class="flex items-center justify-between mt-2 text-[11px] font-mono text-ink-500">
                                <span><span data-bcast-sent>{{ $b->sent }}</span> {{ __('sent') }} · <span data-bcast-failed>{{ $b->failed }}</span> {{ __('failed') }} · <span data-bcast-blocked>{{ $b->blocked }}</span> {{ __('blocked') }}</span>
                                <div class="flex items-center gap-1">
                                    @if (in_array($b->status, ['draft', 'sending'], true))
                                        <form method="POST" action="{{ route('user.facebook.broadcasts.start', $b->id) }}" data-bcast-start>@csrf
                                            <button type="submit" class="px-3 py-1 rounded-full text-white text-[11px] font-semibold" style="background:{{ $fbBlue }}">{{ $b->status === 'sending' ? __('Resume') : __('Start sending') }}</button>
                                        </form>
                                    @elseif ($b->status === 'failed' || ($b->failed + $b->blocked) > 0)
                                        <form method="POST" action="{{ route('user.facebook.broadcasts.retry', $b->id) }}">@csrf
                                            <button type="submit" class="px-3 py-1 rounded-full border border-paper-200 text-[11px] font-medium hover:bg-paper-50">{{ __('Retry failed') }}</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('user.facebook.broadcasts.destroy', $b->id) }}" data-confirm="{{ __('Delete this broadcast?') }}">@csrf @method('DELETE')
                                        <button type="submit" class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card text-[12.5px] text-ink-500">{{ __('No broadcasts yet. Compose one on the right.') }}</div>
                    @endforelse
                </div>

                {{-- Composer --}}
                <aside class="lg:sticky lg:top-6">
                    <form method="POST" action="{{ route('user.facebook.broadcasts.store') }}" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
                        @csrf
                        <div class="font-serif text-[18px]">{{ __('New broadcast') }}</div>
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Name') }}</span>
                            <input name="name" type="text" value="{{ old('name') }}" placeholder="{{ __('e.g. Weekend sale') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Page') }}</span>
                            <select name="facebook_page_id" id="fb-bcast-page" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-semibold focus:outline-none focus:border-wa-deep">
                                @foreach ($pages as $page)
                                    <option value="{{ $page->id }}">{{ $page->name ?: ('Page '.$page->page_id) }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if (($templates ?? collect())->count())
                            <label class="block">
                                <span class="text-[11px] font-semibold text-ink-700">{{ __('Template (optional)') }}</span>
                                <select name="template_id" id="fb-bcast-template" data-templates='@json($templates)'
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                    <option value="">{{ __('— none (write your own) —') }}</option>
                                    @foreach ($templates as $t)
                                        <option value="{{ $t['id'] }}">{{ $t['name'] }}@if (count($t['buttons'])) · {{ count($t['buttons']) }} {{ __('button(s)') }}@endif</option>
                                    @endforeach
                                </select>
                                <span class="text-[10.5px] text-ink-400">{{ __('Prefills the message and sends the template’s buttons.') }}</span>
                            </label>
                        @endif
                        <label class="block">
                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Message') }}</span>
                            <textarea name="body" id="fb-bcast-body" rows="4" maxlength="2000" placeholder="{{ __('Hi {name}! …') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[13px] resize-y focus:outline-none focus:border-wa-deep">{{ old('body') }}</textarea>
                            <span class="text-[10.5px] text-ink-400">{{ __('Use {name} to personalise.') }}</span>
                            <div id="fb-bcast-btn-preview" class="mt-1.5 flex flex-wrap gap-1.5"></div>
                        </label>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[11px] font-semibold text-ink-700">{{ __('Recipients') }} <span class="font-normal text-ink-400">{{ __('(within 24h)') }}</span></span>
                                <button type="button" id="fb-bcast-all" class="text-[11px] text-wa-deep font-semibold hover:underline">{{ __('Select all') }}</button>
                            </div>
                            <div id="fb-bcast-recipients" class="max-h-64 overflow-y-auto border border-paper-200 rounded-xl divide-y divide-paper-100">
                                {{-- populated by JS from data-audience for the selected page --}}
                            </div>
                            <div class="text-[10.5px] text-ink-400 mt-1"><span id="fb-bcast-count">0</span> {{ __('selected') }}</div>
                        </div>
                        <button type="submit" class="w-full py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:{{ $fbBlue }}">{{ __('Create broadcast') }}</button>
                    </form>
                </aside>
            </div>
        @endif
    </main>
</x-layouts.user>
