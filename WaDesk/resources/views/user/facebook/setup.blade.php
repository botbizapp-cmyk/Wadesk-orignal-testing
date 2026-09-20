<x-layouts.user :title="__('Messenger Setup')" nav-key="facebook-setup" page="user-facebook-setup">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- ── Heading ─────────────────────────────────────────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Facebook') }} · {{ __('Messenger') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Messenger') }} <span class="italic text-wa-deep">{{ __('setup') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Set up how your Messenger chat greets and guides customers — the Get Started button, a welcome greeting, an always-on menu, and tappable FAQs. Pick a feature on the left, read what it does, then fill it in. Changes save straight to Facebook.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if ($pages->count() > 1)
                    <form method="GET" action="{{ route('user.facebook.setup') }}">
                        <select name="page" onchange="this.form.submit()" class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] focus:outline-none focus:border-wa-deep">
                            @foreach ($pages as $p)
                                <option value="{{ $p->id }}" @selected($page && $page->id === $p->id)>{{ $p->name ?: $p->page_id }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <a href="{{ route('user.facebook.posts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Composer') }}</a>
            </div>
        </div>

        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($pages->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-8 text-center">
                <p class="text-[13.5px] text-ink-700">{{ __('No Facebook Page connected yet.') }}</p>
                <a href="{{ url('/devices') }}" class="mt-3 inline-flex px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Connect a Facebook account') }}</a>
            </div>
        @else
            @php
                $tabs = [
                    'getstarted'  => ['label' => __('Get Started button'), 'set' => $getStarted !== '',      'icon' => 'M4 8h8M8 4l4 4-4 4'],
                    'greeting'    => ['label' => __('Greeting'),           'set' => $greeting !== '',        'icon' => 'M3 5.5A2.5 2.5 0 0 1 5.5 3h5A2.5 2.5 0 0 1 13 5.5v3A2.5 2.5 0 0 1 10.5 11H8l-3.5 2v-2A2.5 2.5 0 0 1 3 8.5z'],
                    'menu'        => ['label' => __('Persistent menu'),    'set' => count($menuItems) > 0,   'icon' => 'M2.5 4h11M2.5 8h11M2.5 12h11'],
                    'icebreakers' => ['label' => __('Ice breakers'),       'set' => count($iceBreakers) > 0, 'icon' => 'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2zM6 6.5a2 2 0 0 1 4 0c0 1.5-2 1.5-2 3M8 12h.01'],
                ];
                $firstTab = 'getstarted';
                foreach ($tabs as $k => $t) { if ($t['set']) { $firstTab = $k; break; } }
            @endphp

            <div data-fb-setup data-initial-tab="{{ $firstTab }}" class="space-y-5">

                {{-- ── Feature tabs (horizontal bar) ───────────────────────── --}}
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="flex items-center gap-2.5 shrink-0">
                        <span class="w-8 h-8 rounded-lg grid place-items-center shrink-0" style="background:#1877F2">
                            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <div class="text-[12.5px] font-semibold text-ink-800 truncate leading-tight">{{ $page->name ?: $page->page_id }}</div>
                            <div class="text-[9.5px] text-ink-500 font-mono uppercase tracking-[0.12em]">{{ __('Messenger profile') }}</div>
                        </div>
                    </div>
                    <div class="sm:ml-auto overflow-x-auto -mx-1 px-1">
                        <div class="inline-flex items-center gap-1 rounded-full border border-paper-200 bg-paper-0 p-1 shadow-card">
                            @foreach ($tabs as $key => $t)
                                <button type="button" data-fb-tab="{{ $key }}"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-[12.5px] whitespace-nowrap text-ink-700 hover:bg-paper-50 transition">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5"><path d="{{ $t['icon'] }}"/></svg>
                                    <span>{{ $t['label'] }}</span>
                                    <span data-fb-tab-dot class="w-1.5 h-1.5 rounded-full shrink-0 {{ $t['set'] ? 'bg-wa-green' : 'bg-paper-300' }}" title="{{ $t['set'] ? __('Set up') : __('Not set up') }}"></span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- ── One panel per feature ───────────────────────────────── --}}
                <div class="min-w-0">

                    {{-- ══ Get Started ══════════════════════════════════════ --}}
                    <section data-fb-panel="getstarted" class="hidden">
                        <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6">
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6 space-y-5">
                                <div>
                                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Get Started button') }}</h2>
                                    <p class="text-[12.5px] text-ink-600 mt-1.5">{{ __('The button a brand-new customer taps to begin the chat.') }}</p>
                                </div>
                                <x-facebook.setup-explainer
                                    :what="__('It is the very first thing someone sees when they open your Messenger — a single “Get Started” button on the welcome screen.')"
                                    :customer="__('They tap it once, and the conversation begins. It only ever shows before the first message.')"
                                    :how="__('Give it a payload (a keyword like GET_STARTED). Then in Flows, add a Trigger that listens for that payload so a Welcome flow runs the moment they tap.')" />

                                <form method="POST" action="{{ route('user.facebook.setup.getstarted') }}" class="space-y-3 pt-1 border-t border-paper-100">@csrf
                                    <input type="hidden" name="facebook_page_id" value="{{ $page->id }}">
                                    <label class="block">
                                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Payload') }}</span>
                                        <input name="payload" type="text" maxlength="1000" value="{{ $getStarted }}" placeholder="GET_STARTED" data-fb-gs-input
                                            class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                                        <span class="block mt-1 text-[11px] text-ink-500">{{ __('This word is sent to you when the button is tapped. Match it in a Flow trigger to start automation.') }}</span>
                                    </label>
                                    <div class="flex items-center justify-between pt-1">
                                        @if ($getStarted !== '')
                                            <button form="fb-del-getstarted" class="text-[11.5px] text-accent-coral hover:underline">{{ __('Remove') }}</button>
                                        @else <span></span> @endif
                                        <button type="submit" class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save') }}</button>
                                    </div>
                                </form>
                                <form id="fb-del-getstarted" method="POST" action="{{ route('user.facebook.setup.getstarted.delete') }}" data-confirm="{{ __('Remove the Get Started button?') }}" class="hidden">@csrf @method('DELETE')<input type="hidden" name="facebook_page_id" value="{{ $page->id }}"></form>
                            </div>

                            {{-- Preview: welcome screen with the Get Started button --}}
                            <x-facebook.messenger-frame :page="$page">
                                <div class="flex-1 flex flex-col items-center justify-end pb-4 gap-3">
                                    <div class="w-14 h-14 rounded-full grid place-items-center text-white text-[18px] font-semibold" style="background:#1877F2">{{ strtoupper(mb_substr($page->name ?: 'FB', 0, 1)) }}</div>
                                    <div class="text-[12px] font-semibold text-ink-800">{{ $page->name ?: __('Your Page') }}</div>
                                    <div class="text-[10.5px] text-ink-500">{{ __('Typically replies instantly') }}</div>
                                </div>
                                <button type="button" class="w-full rounded-full bg-[#0084FF] text-white text-[13px] font-semibold py-2.5">{{ __('Get Started') }}</button>
                            </x-facebook.messenger-frame>
                        </div>
                    </section>

                    {{-- ══ Greeting ═════════════════════════════════════════ --}}
                    <section data-fb-panel="greeting" class="hidden">
                        <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6">
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6 space-y-5">
                                <div>
                                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Greeting') }}</h2>
                                    <p class="text-[12.5px] text-ink-600 mt-1.5">{{ __('The friendly welcome line shown before anyone types.') }}</p>
                                </div>
                                <x-facebook.setup-explainer
                                    :what="__('A short welcome message on the very first screen of your chat, right under your Page name.')"
                                    :customer="__('They read it the instant they open your Messenger — it sets the tone and tells them what you can help with.')"
                                    :how="__('Keep it to one warm line (max 160 characters). Tip: add a first-name tag and Messenger greets each customer by name.')" />

                                <form method="POST" action="{{ route('user.facebook.setup.greeting') }}" class="space-y-3 pt-1 border-t border-paper-100">@csrf
                                    <input type="hidden" name="facebook_page_id" value="{{ $page->id }}">
                                    <label class="block">
                                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Welcome text') }}</span>
                                        <textarea name="greeting" rows="4" maxlength="160" data-fb-greeting placeholder="{{ __('Hi there! How can we help you today?') }}"
                                            class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13.5px] focus:outline-none focus:border-wa-deep">{{ $greeting }}</textarea>
                                        <span class="block mt-1 text-[11px] text-ink-500"><span data-fb-greeting-count>0</span>/160</span>
                                    </label>
                                    <div class="flex items-center justify-between pt-1">
                                        @if ($greeting !== '')
                                            <button form="fb-del-greeting" class="text-[11.5px] text-accent-coral hover:underline">{{ __('Remove') }}</button>
                                        @else <span></span> @endif
                                        <button type="submit" class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save') }}</button>
                                    </div>
                                </form>
                                <form id="fb-del-greeting" method="POST" action="{{ route('user.facebook.setup.greeting.delete') }}" data-confirm="{{ __('Remove the greeting?') }}" class="hidden">@csrf @method('DELETE')<input type="hidden" name="facebook_page_id" value="{{ $page->id }}"></form>
                            </div>

                            <x-facebook.messenger-frame :page="$page">
                                <div class="flex-1 flex flex-col items-center justify-center gap-3 text-center px-1">
                                    <div class="w-14 h-14 rounded-full grid place-items-center text-white text-[18px] font-semibold" style="background:#1877F2">{{ strtoupper(mb_substr($page->name ?: 'FB', 0, 1)) }}</div>
                                    <div class="text-[12px] font-semibold text-ink-800">{{ $page->name ?: __('Your Page') }}</div>
                                    <div data-fb-greeting-preview class="text-[12px] text-ink-600 leading-snug">{{ $greeting ?: __('Hi! How can we help you today?') }}</div>
                                </div>
                            </x-facebook.messenger-frame>
                        </div>
                    </section>

                    {{-- ══ Persistent Menu ══════════════════════════════════ --}}
                    <section data-fb-panel="menu" class="hidden">
                        <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6">
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6 space-y-5">
                                <div>
                                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Persistent menu') }}</h2>
                                    <p class="text-[12.5px] text-ink-600 mt-1.5">{{ __('The always-on shortcut menu inside the message bar.') }}</p>
                                </div>
                                <x-facebook.setup-explainer
                                    :what="__('A menu (the ☰ icon next to the text box) that stays available all through the chat — up to 3 shortcuts.')"
                                    :customer="__('They tap ☰ at any point to jump straight to what they need — “Talk to a human”, “Track my order”, “Visit shop”.')"
                                    :how="__('Add up to 3 items. Each one either runs a Flow (send a payload) or opens a link (URL). Optionally hide the text box so people only use the menu.')" />

                                <form method="POST" action="{{ route('user.facebook.setup.menu') }}" class="space-y-4 pt-1 border-t border-paper-100">@csrf
                                    <input type="hidden" name="facebook_page_id" value="{{ $page->id }}">
                                    <div data-fb-menu-list class="space-y-3">
                                        @php $menuSeed = count($menuItems) ? $menuItems : [['title' => '', 'type' => 'postback', 'url' => '', 'payload' => '']]; @endphp
                                        @foreach ($menuSeed as $m)
                                            <div data-fb-menu-row class="rounded-xl border border-paper-200 bg-paper-50/60 p-3.5 grid sm:grid-cols-[1fr_auto] gap-3 items-start">
                                                <div class="grid sm:grid-cols-2 gap-3 min-w-0">
                                                    <label class="block">
                                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Title') }}</span>
                                                        <input name="menu_title[]" type="text" maxlength="30" value="{{ $m['title'] }}" placeholder="{{ __('Talk to us') }}"
                                                            class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Action') }}</span>
                                                        <select name="menu_type[]" data-fb-menu-type
                                                            class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                                            <option value="postback" @selected($m['type'] === 'postback')>{{ __('Run a flow (send payload)') }}</option>
                                                            <option value="web_url" @selected($m['type'] === 'web_url')>{{ __('Open a link (URL)') }}</option>
                                                        </select>
                                                    </label>
                                                    <label class="block sm:col-span-2" data-fb-menu-payload @if($m['type'] === 'web_url') hidden @endif>
                                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Payload') }}</span>
                                                        <input name="menu_payload[]" type="text" maxlength="1000" value="{{ $m['payload'] }}" placeholder="MENU_TALK"
                                                            class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                                                    </label>
                                                    <label class="block sm:col-span-2" data-fb-menu-url @if($m['type'] !== 'web_url') hidden @endif>
                                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('URL') }}</span>
                                                        <input name="menu_url[]" type="url" value="{{ $m['url'] }}" placeholder="https://…"
                                                            class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                                                    </label>
                                                </div>
                                                <button type="button" data-fb-menu-remove title="{{ __('Remove item') }}"
                                                    class="mt-1 w-8 h-8 rounded-lg grid place-items-center text-ink-400 hover:text-accent-coral hover:bg-accent-coral/10">
                                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="flex items-center justify-between flex-wrap gap-3">
                                        <button type="button" data-fb-menu-add class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-wa-deep hover:underline">
                                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 3.5v9M3.5 8h9"/></svg>{{ __('Add menu item') }}
                                        </button>
                                        <label class="inline-flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" name="composer_input_disabled" value="1" @checked($composerDisabled)
                                                class="w-4 h-4 rounded border-paper-300 text-wa-deep focus:ring-wa-deep/20">
                                            <span class="text-[12px] text-ink-800">{{ __('Hide the text box (menu only)') }}</span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between pt-2 border-t border-paper-100">
                                        <button form="fb-del-menu" class="text-[11.5px] text-accent-coral hover:underline mt-3">{{ __('Remove menu') }}</button>
                                        <button type="submit" class="mt-3 px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save menu') }}</button>
                                    </div>
                                </form>
                                <form id="fb-del-menu" method="POST" action="{{ route('user.facebook.setup.menu.delete') }}" data-confirm="{{ __('Remove the persistent menu?') }}" class="hidden">@csrf @method('DELETE')<input type="hidden" name="facebook_page_id" value="{{ $page->id }}"></form>
                            </div>

                            <x-facebook.messenger-frame :page="$page">
                                <div class="flex-1"></div>
                                <div class="rounded-2xl bg-paper-0 border border-paper-200 shadow-card overflow-hidden">
                                    <div class="px-3 py-2 border-b border-paper-100 text-[10px] font-mono uppercase tracking-[0.14em] text-ink-500">{{ __('Menu') }}</div>
                                    @php $menuPrev = array_slice(count($menuItems) ? $menuItems : [['title'=>__('Talk to us')],['title'=>__('Track my order')],['title'=>__('Visit shop')]], 0, 3); @endphp
                                    @foreach ($menuPrev as $mp)
                                        <div class="px-3 py-2.5 text-[12.5px] text-ink-800 border-b border-paper-100 last:border-0">{{ $mp['title'] ?: __('Menu item') }}</div>
                                    @endforeach
                                </div>
                                <div class="mt-2 flex items-center gap-2 rounded-full border border-paper-200 bg-paper-0 px-3 py-1.5">
                                    <svg viewBox="0 0 16 16" class="w-4 h-4 text-[#0084FF]" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2.5 4h11M2.5 8h11M2.5 12h7"/></svg>
                                    <span class="text-[11.5px] text-ink-400">{{ __('Aa') }}</span>
                                </div>
                            </x-facebook.messenger-frame>
                        </div>
                    </section>

                    {{-- ══ Ice Breakers ═════════════════════════════════════ --}}
                    <section data-fb-panel="icebreakers" class="hidden">
                        <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6">
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6 space-y-5">
                                <div>
                                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Ice breakers') }}</h2>
                                    <p class="text-[12.5px] text-ink-600 mt-1.5">{{ __('Suggested questions shown before the first message.') }}</p>
                                </div>
                                <x-facebook.setup-explainer
                                    :what="__('Up to 4 tappable questions on the welcome screen — the common things people ask, ready to tap instead of type.')"
                                    :customer="__('Instead of thinking what to write, they tap a question like “What are your hours?” and get an instant answer.')"
                                    :how="__('Add your top FAQs. Each tap sends its payload — match that payload in a Flow trigger to answer automatically.')" />

                                <form method="POST" action="{{ route('user.facebook.setup.icebreakers') }}" class="space-y-4 pt-1 border-t border-paper-100">@csrf
                                    <input type="hidden" name="facebook_page_id" value="{{ $page->id }}">
                                    <div data-fb-ib-list class="space-y-3">
                                        @php $ibSeed = count($iceBreakers) ? $iceBreakers : [['question' => '', 'payload' => '']]; @endphp
                                        @foreach ($ibSeed as $ib)
                                            <div data-fb-ib-row class="rounded-xl border border-paper-200 bg-paper-50/60 p-3.5 grid sm:grid-cols-[1fr_auto] gap-3 items-start">
                                                <div class="grid sm:grid-cols-2 gap-3 min-w-0">
                                                    <label class="block">
                                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Question') }}</span>
                                                        <input name="ib_question[]" type="text" maxlength="80" value="{{ $ib['question'] }}" placeholder="{{ __('What are your hours?') }}"
                                                            class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Payload') }}</span>
                                                        <input name="ib_payload[]" type="text" maxlength="1000" value="{{ $ib['payload'] }}" placeholder="ICE_HOURS"
                                                            class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                                                    </label>
                                                </div>
                                                <button type="button" data-fb-ib-remove title="{{ __('Remove ice breaker') }}"
                                                    class="mt-1 w-8 h-8 rounded-lg grid place-items-center text-ink-400 hover:text-accent-coral hover:bg-accent-coral/10">
                                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="button" data-fb-ib-add class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-wa-deep hover:underline">
                                        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 3.5v9M3.5 8h9"/></svg>{{ __('Add ice breaker') }}
                                    </button>
                                    <div class="flex items-center justify-between pt-2 border-t border-paper-100">
                                        <button form="fb-del-ib" class="text-[11.5px] text-accent-coral hover:underline mt-3">{{ __('Remove all') }}</button>
                                        <button type="submit" class="mt-3 px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save ice breakers') }}</button>
                                    </div>
                                </form>
                                <form id="fb-del-ib" method="POST" action="{{ route('user.facebook.setup.icebreakers.delete') }}" data-confirm="{{ __('Remove all ice breakers?') }}" class="hidden">@csrf @method('DELETE')<input type="hidden" name="facebook_page_id" value="{{ $page->id }}"></form>
                            </div>

                            <x-facebook.messenger-frame :page="$page">
                                <div class="flex-1 flex flex-col items-center justify-center gap-2">
                                    <div class="w-12 h-12 rounded-full grid place-items-center text-white text-[16px] font-semibold" style="background:#1877F2">{{ strtoupper(mb_substr($page->name ?: 'FB', 0, 1)) }}</div>
                                    <div class="text-[11.5px] font-semibold text-ink-800">{{ $page->name ?: __('Your Page') }}</div>
                                </div>
                                <div class="space-y-2">
                                    @php $ibPrev = array_slice(count($iceBreakers) ? $iceBreakers : [['question'=>__('What are your hours?')],['question'=>__('Where do you ship?')],['question'=>__('Talk to a human')]], 0, 4); @endphp
                                    @foreach ($ibPrev as $ip)
                                        <div class="rounded-full border border-[#0084FF]/40 text-[#0084FF] text-[12px] text-center py-2 bg-[#0084FF]/5">{{ $ip['question'] ?: __('Question') }}</div>
                                    @endforeach
                                </div>
                            </x-facebook.messenger-frame>
                        </div>
                    </section>

                </div>
            </div>

            <p class="text-[11px] text-ink-500">{{ __('These settings write to the Facebook Messenger profile via the Graph API and apply to new conversations. Meta may take a moment to propagate changes.') }}</p>
        @endif
    </main>
</x-layouts.user>
