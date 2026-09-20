@php
    /** @var \Illuminate\Support\Collection $bots */
    $tgGlyph = '<path d="M21.8 4.3 2.9 11.6c-1 .4-1 .95-.17 1.2l4.8 1.5 1.85 5.9c.24.66.43.9.9.9.35 0 .5-.16.7-.35l2.3-2.24 4.78 3.53c.88.48 1.5.23 1.72-.8l3.1-14.6c.32-1.28-.48-1.86-1.3-1.53z"/>';

    $tgAccounts   = $accounts ?? collect();
    $tgAccount    = $tgAccounts->first(fn ($a) => $a->hasSession()) ?? $tgAccounts->first();
    $tgConnected  = $tgAccount && $tgAccount->hasSession();
    $tgPending    = session()->has('telegram.account.login_id');
    $tgNeedPw     = (bool) session('telegram.account.need_password');
    $tgSentTo     = (string) session('telegram.account.sent_to', '');
    $botCount     = $bots->count();
@endphp

<x-layouts.user :title="__('Telegram')" nav-key="telegram" page="user-telegram-index">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Channel') }} · {{ __('Telegram') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Telegram') }} <span class="italic text-wa-deep">{{ __('Connect') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">{{ __('Deploy a Telegram bot to automate messaging workflows, handle client support, and broadcast updates to your subscribers worldwide.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ url('/team-inbox') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Team inbox') }}</a>
                <a href="{{ url('/flows') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Flows') }}</a>
                <a href="{{ url('/broadcasts') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Broadcasts') }}</a>
            </div>
        </div>

        @if (session('status') || session('success'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') ?: session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_400px] gap-6 items-start">

            {{-- ── LEFT: connect cards + connected bots ── --}}
            <div class="space-y-5 min-w-0">

                {{-- Bot connection card --}}
                <div class="bg-gradient-to-br from-[#EAF6FC] to-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <span class="w-12 h-12 rounded-2xl grid place-items-center shrink-0 text-white" style="background:#229ED9"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="currentColor">{!! $tgGlyph !!}</svg></span>
                        @if ($botCount > 0)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-mono bg-wa-mint text-wa-deep border border-wa-green/40"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ $botCount }} {{ __('connected') }}</span>
                        @else
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-mono bg-paper-100 text-ink-500 border border-paper-200">{{ __('Not Connected') }}</span>
                        @endif
                    </div>
                    <div class="font-serif text-[22px] leading-tight mt-4">{{ __('Telegram Bot Connection') }}</div>
                    <p class="text-[12.5px] text-ink-600 mt-1.5 max-w-lg leading-relaxed">{{ __('Paste a bot token from @BotFather to automate messaging, handle support, and broadcast to your subscribers.') }}</p>
                    <div class="mt-4 flex items-center gap-2">
                        <button type="button" onclick="document.getElementById('tg-bot-modal').classList.remove('hidden')"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-full text-white text-[13px] font-semibold hover:opacity-90 transition" style="background:#229ED9">
                            {{ $botCount > 0 ? __('Connect another bot') : __('Connect Bot') }}
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
                        </button>
                    </div>
                </div>

                {{-- Account connection card (MTProto — QR / phone). Second way in. --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <span class="w-12 h-12 rounded-2xl grid place-items-center shrink-0 text-white" style="background:#1B7CB0"><svg viewBox="0 0 16 16" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="8" cy="5" r="3"/><path d="M2.5 13.5a5.5 5.5 0 0 1 11 0"/></svg></span>
                        @if ($tgConnected)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-mono bg-wa-mint text-wa-deep border border-wa-green/40"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                        @else
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-mono bg-paper-100 text-ink-500 border border-paper-200">{{ __('Not Connected') }}</span>
                        @endif
                    </div>
                    <div class="font-serif text-[22px] leading-tight mt-4">{{ __('Telegram Account Connection') }}</div>

                    @if ($tgConnected)
                        <p class="text-[12.5px] text-ink-600 mt-1.5">{{ __('Signed in as') }} <span class="font-semibold text-ink-900">{{ $tgAccount->label() }}</span>{{ $tgAccount->phone ? ' · '.$tgAccount->phone : '' }}. {{ __('Create bots below — no @BotFather typing.') }}</p>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                            {{-- Create a bot --}}
                            <form method="POST" action="{{ route('user.telegram.account.create-bot') }}" class="rounded-2xl border border-paper-200 p-4 space-y-3" data-tg-account>
                                @csrf
                                <input type="hidden" name="account_id" value="{{ $tgAccount->id }}">
                                <div class="text-[13px] font-semibold text-ink-900">{{ __('Create a new bot') }}</div>
                                <div>
                                    <label class="block text-[11px] text-ink-500 mb-1">{{ __('Bot name') }}</label>
                                    <input name="name" type="text" value="{{ old('name') }}" maxlength="64" placeholder="{{ __('Orders Assistant') }}"
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-ink-500 mb-1">{{ __('Username') }} <span class="text-ink-400">{{ __('(must end in “bot”)') }}</span></label>
                                    <div class="flex items-center rounded-xl border border-paper-200 bg-paper-0 focus-within:border-wa-deep overflow-hidden">
                                        <span class="pl-3 pr-1 text-[12.5px] text-ink-400 font-mono">@</span>
                                        <input name="username" data-tg-username type="text" value="{{ old('username') }}" maxlength="32" placeholder="my_orders_bot" autocomplete="off"
                                            class="flex-1 bg-transparent px-1 py-2 text-[12.5px] font-mono focus:outline-none">
                                        <span data-tg-username-status class="pr-3 text-[11px] font-mono"></span>
                                    </div>
                                </div>
                                <button type="submit" class="w-full py-2.5 rounded-full text-white text-[13px] font-semibold hover:opacity-90 transition" style="background:#229ED9">{{ __('Create & connect bot') }}</button>
                            </form>
                            {{-- Account status + actions --}}
                            <div class="rounded-2xl border border-paper-200 bg-paper-50/60 p-4">
                                <div class="flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-full grid place-items-center shrink-0 text-white text-[15px] font-semibold" style="background:#229ED9">{{ mb_strtoupper(mb_substr($tgAccount->label(), $tgAccount->username ? 1 : 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <div class="text-[13.5px] font-semibold text-ink-900 truncate">{{ $tgAccount->label() }}</div>
                                        <div class="text-[11px] text-ink-500 font-mono">{{ __('linked') }} {{ optional($tgAccount->connected_at)->diffForHumans() }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 mt-3">
                                    <form method="POST" action="{{ route('user.telegram.account.check') }}">@csrf
                                        <button type="submit" class="px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[11.5px] font-medium">{{ __('Test session') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.telegram.account.disconnect') }}" data-confirm="{{ __('Log this Telegram account out? Bots you already created keep working.') }}">@csrf @method('DELETE')
                                        <input type="hidden" name="account_id" value="{{ $tgAccount->id }}">
                                        <button type="submit" class="px-3 py-1.5 rounded-full border border-accent-coral/40 text-accent-coral hover:bg-accent-coral/10 text-[11.5px] font-medium">{{ __('Log out') }}</button>
                                    </form>
                                </div>
                                <p class="text-[11px] text-ink-400 mt-3 pt-3 border-t border-paper-200">{{ __('This login can read the account’s chats — stored encrypted, used only to talk to @BotFather.') }}</p>
                            </div>
                        </div>
                    @elseif ($tgPending)
                        {{-- Code entry after a phone-code request. --}}
                        <p class="text-[12.5px] text-ink-600 mt-1.5 mb-3">
                            {{ $tgSentTo === 'telegram_app'
                                ? __('Telegram sent a code to your Telegram app — check your other signed-in devices.')
                                : __('Telegram sent a login code to :phone.', ['phone' => session('telegram.account.phone', __('your number'))]) }}
                        </p>
                        <form method="POST" action="{{ route('user.telegram.account.sign-in') }}" class="max-w-md space-y-3">
                            @csrf
                            <input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="12345" autofocus
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[15px] font-mono tracking-[0.3em] text-center focus:outline-none focus:border-wa-deep">
                            @if ($tgNeedPw)
                                <div>
                                    <label class="block text-[11px] text-ink-500 mb-1">{{ __('Two-step password') }}</label>
                                    <input name="password" type="password" autocomplete="current-password" placeholder="••••••••"
                                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep">
                                    <p class="text-[11px] text-ink-400 mt-1">{{ __('Your account has two-step verification — re-enter the code with your password.') }}</p>
                                </div>
                            @endif
                            <div class="flex items-center gap-2">
                                <button type="submit" class="flex-1 py-2.5 rounded-full text-white text-[13px] font-semibold hover:opacity-90 transition" style="background:#229ED9">{{ __('Sign in') }}</button>
                                <button type="submit" form="tg-account-cancel" class="px-4 py-2.5 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                        <form id="tg-account-cancel" method="POST" action="{{ route('user.telegram.account.cancel') }}" class="hidden">@csrf</form>
                    @else
                        <p class="text-[12.5px] text-ink-600 mt-1.5 max-w-lg leading-relaxed">{{ __('Link your Telegram account to create bots right here — scan a QR or use your phone number, just like WhatsApp.') }}</p>
                        <div class="mt-4">
                            <button type="button" onclick="document.getElementById('tg-account-modal').classList.remove('hidden')"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-full text-white text-[13px] font-semibold hover:opacity-90 transition" style="background:#1B7CB0">
                                {{ __('Connect Account') }}
                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
                            </button>
                            @if (auth()->user()?->isAdmin())
                                <span class="text-[11px] text-ink-400 ml-3">{{ __('Optional — needs a Telegram API id/hash in Admin → Channel Settings.') }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Connected bots --}}
                @if ($botCount > 0)
                    <div class="space-y-4">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Connected bots') }}</div>
                        @foreach ($bots as $b)
                            @php $live = $b->active && ! $b->last_error; @endphp
                            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                                <div class="p-4 flex items-start gap-3.5">
                                    <span class="w-12 h-12 rounded-2xl grid place-items-center shrink-0 text-white" style="background:#229ED9"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="currentColor">{!! $tgGlyph !!}</svg></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[15px] font-semibold text-ink-900 truncate">{{ $b->bot_name ?: $b->bot_username }}</span>
                                            @if ($live)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Live') }}</span>
                                            @elseif (! $b->active)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-paper-100 text-ink-600 shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-ink-300"></span>{{ __('Paused') }}</span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-accent-amber/15 text-[#7B5A14] border border-accent-amber/40 shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ __('Needs attention') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-[12px] text-ink-500 font-mono">@if($b->bot_username)@ {{ ltrim($b->bot_username, '@') }}@endif</div>
                                        @if ($b->last_error)<div class="text-[11px] text-accent-coral mt-1">{{ $b->last_error }}</div>@endif
                                        @if ($b->last_inbound_at)<div class="text-[10.5px] text-ink-400 font-mono mt-1">{{ __('Last message') }} {{ $b->last_inbound_at->diffForHumans() }}</div>@endif
                                    </div>
                                </div>
                                {{-- Payments --}}
                                <details class="border-t border-paper-100">
                                    <summary class="px-4 py-2.5 cursor-pointer select-none text-[11.5px] text-ink-600 hover:bg-paper-50 flex items-center gap-2">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-ink-400" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="4" width="12" height="8" rx="1.5"/><path d="M2 7h12M4.5 10h3"/></svg>
                                        {{ __('Payments') }}
                                        @if ($b->payment_provider_token)<span class="text-[10px] font-mono px-1.5 py-0.5 rounded-full bg-wa-mint text-wa-deep">{{ __('on') }}</span>@endif
                                    </summary>
                                    <form method="POST" action="{{ route('user.telegram.payments', $b->id) }}" class="px-4 pb-4 pt-1 space-y-2">
                                        @csrf
                                        <p class="text-[11px] text-ink-500 leading-snug">{{ __('Paste a payment provider token from @BotFather (/mybots → Payments) to enable the Payment flow node — native in-chat invoices.') }}</p>
                                        <input name="payment_provider_token" type="password" autocomplete="new-password"
                                            placeholder="{{ $b->payment_provider_token ? '•••••••• '.__('(saved — leave blank to keep)') : '284685063:TEST:…' }}"
                                            class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep">
                                        <div class="flex items-center justify-between gap-2">
                                            @if ($b->payment_provider_token)
                                                <label class="flex items-center gap-1.5 text-[11px] text-ink-500"><input type="checkbox" name="clear_payment_token" value="1" class="accent-accent-coral">{{ __('Remove token') }}</label>
                                            @else<span></span>@endif
                                            <button type="submit" class="px-3 py-1.5 rounded-full text-white text-[11.5px] font-semibold" style="background:#229ED9">{{ __('Save') }}</button>
                                        </div>
                                    </form>
                                </details>
                                <div class="px-4 py-3 border-t border-paper-100 flex items-center justify-between gap-2">
                                    <span class="text-[10.5px] text-ink-400 font-mono truncate">{{ __('Webhook') }}: {{ \Illuminate\Support\Str::limit($b->webhookUrl(), 42, '…') }}</span>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <form method="POST" action="{{ route('user.telegram.retry', $b->id) }}">@csrf
                                            <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500" title="{{ __('Fix inbound (re-register webhook)') }}"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3"/><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8"/></svg></button>
                                        </form>
                                        <form method="POST" action="{{ route('user.telegram.toggle', $b->id) }}">@csrf
                                            <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500" title="{{ $b->active ? __('Pause') : __('Resume') }}">
                                                @if ($b->active)<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 4v8M10 4v8"/></svg>@else<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="currentColor"><path d="M5 3l8 5-8 5z"/></svg>@endif
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('user.telegram.destroy', $b->id) }}" data-confirm="{{ __('Disconnect this Telegram bot? Its inbox threads stay, but it stops sending/receiving.') }}">@csrf @method('DELETE')
                                            <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10" title="{{ __('Disconnect') }}"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg></button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ── RIGHT: setup guide + prerequisites + what's unlocked + tip ── --}}
            <aside class="space-y-5 lg:sticky lg:top-6">

                {{-- Setup guide + prerequisites --}}
                <div class="bg-gradient-to-br from-[#EAF6FC] to-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 flex items-start gap-3 border-b border-paper-200/70">
                        <span class="w-10 h-10 rounded-2xl grid place-items-center shrink-0 text-white" style="background:#229ED9"><svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="4.5" width="10" height="8" rx="1.5"/><path d="M6 4.5V3.5a2 2 0 0 1 4 0v1M6.2 8.5h.01M9.8 8.5h.01"/></svg></span>
                        <div>
                            <div class="font-serif text-[18px] leading-tight">{{ __('Telegram Bot Setup Guide') }}</div>
                            <p class="text-[11.5px] text-ink-600 mt-0.5">{{ __('Complete these steps to link your Telegram channel and enable automated workflows.') }}</p>
                        </div>
                    </div>
                    <div class="px-5 py-4">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2 flex items-center gap-1.5"><svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v3M8 5.2h.01"/></svg>{{ __('Prerequisites') }}</div>
                        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-[12px] text-ink-700">
                            @foreach ([__('An active Telegram account'), __('A unique name for your bot'), __('Access to Telegram Web/App')] as $pre)
                                <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-wa-green shrink-0"></span>{{ $pre }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                {{-- Connection steps --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('Connection Steps') }}</div>
                    <div class="space-y-4">
                        @foreach ([
                            ['<rect x="3" y="4.5" width="10" height="8" rx="1.5"/><path d="M6 4.5V3.5a2 2 0 0 1 4 0v1"/>', __('Locate BotFather'), __('Open Telegram and search for the official @BotFather account (look for the verified badge).')],
                            ['<path d="M5 4l-3 4 3 4M11 4l3 4-3 4"/>', __('Create a new bot'), __('Send /newbot to @BotFather and follow the prompts to name your bot and pick a username.')],
                            ['<circle cx="6" cy="8" r="2.5"/><path d="M8.5 8H14M12 8v2M14 8v2"/>', __('Retrieve API token'), __('BotFather generates an HTTP API token (e.g. 123456789:ABC…). Copy it safely.')],
                            ['<path d="M6.5 9.5a2.5 2.5 0 0 0 3.5 0l2-2a2.5 2.5 0 0 0-3.5-3.5l-1 1M9.5 6.5a2.5 2.5 0 0 0-3.5 0l-2 2a2.5 2.5 0 0 0 3.5 3.5l1-1"/>', __('Connect the bot here'), __('Click “Connect Bot”, paste your token in the box, and submit to finish the link.')],
                        ] as $i => [$icon, $title, $desc])
                            <div class="flex items-start gap-3">
                                <span class="w-8 h-8 rounded-xl grid place-items-center shrink-0 bg-[#EAF6FC] text-[#1B7CB0]"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg></span>
                                <div class="min-w-0">
                                    <div class="inline-block px-1.5 py-0.5 rounded-md text-[9.5px] font-mono uppercase tracking-wide bg-wa-mint text-wa-deep mb-1">{{ __('Step') }} {{ $i + 1 }}</div>
                                    <div class="text-[13px] font-semibold text-ink-900 leading-tight">{{ $title }}</div>
                                    <p class="text-[11.5px] text-ink-600 mt-0.5 leading-relaxed">{{ $desc }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- What's unlocked --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('What’s unlocked?') }}</div>
                    <div class="grid grid-cols-1 gap-3">
                        @foreach ([
                            ['<circle cx="8" cy="8" r="2"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2M4 4l1.5 1.5M11 11l1 1"/>', __('Automate support'), __('Let AI bots handle FAQs and route conversations 24/7.')],
                            ['<path d="M2 6l9-3v10l-9-3zM11 5l3 1v4l-3 1"/>', __('Broadcast campaigns'), __('Send promotions, alerts and newsletters to all subscribers at once.')],
                            ['<rect x="2" y="3" width="12" height="9" rx="1.5"/><path d="M2 6h12M5 9h3"/>', __('Rich interactive media'), __('Engage users with buttons, menus, invoices and images.')],
                        ] as [$icon, $title, $desc])
                            <div class="rounded-xl border border-paper-200 p-3.5 flex items-start gap-3">
                                <span class="w-8 h-8 rounded-lg grid place-items-center shrink-0 bg-paper-100 text-ink-600"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg></span>
                                <div class="min-w-0">
                                    <div class="text-[12.5px] font-semibold text-ink-900">{{ $title }}</div>
                                    <p class="text-[11px] text-ink-600 mt-0.5 leading-relaxed">{{ $desc }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Configuration tip --}}
                <div class="rounded-2xl border border-accent-amber/40 bg-accent-amber/10 p-4">
                    <div class="text-[12.5px] font-semibold text-[#7B5A14] flex items-center gap-1.5"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 12h4M6.5 13.5h3M8 2a4 4 0 0 1 2.5 7.2c-.4.3-.5.6-.5 1H6c0-.4-.1-.7-.5-1A4 4 0 0 1 8 2Z"/></svg>{{ __('Configuration tip') }}</div>
                    <p class="text-[11.5px] text-[#7B5A14]/90 mt-1 leading-relaxed">{{ __('Never share your bot API token publicly. If it is compromised, message @BotFather and run /revoke to generate a new one immediately. On localhost, a tunnel’s address changes each restart — press “Fix inbound” to re-register.') }}</p>
                </div>
            </aside>
        </div>
    </main>

    {{-- ── MODAL: Connect a bot (paste @BotFather token) ── --}}
    <div id="tg-bot-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-900/40" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="bg-paper-0 rounded-2xl shadow-soft border border-paper-200 max-w-lg w-full overflow-hidden">
            <div class="px-6 py-5 border-b border-paper-200 flex items-start justify-between gap-4">
                <div class="flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-xl grid place-items-center text-white" style="background:#229ED9"><svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor">{!! $tgGlyph !!}</svg></span>
                    <h3 class="font-serif text-[20px] leading-tight">{{ __('Connect Telegram Bot') }}</h3>
                </div>
                <button type="button" onclick="document.getElementById('tg-bot-modal').classList.add('hidden')" class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center" title="{{ __('Close') }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('user.telegram.connect') }}" class="p-6 space-y-4">
                @csrf
                <p class="text-[12.5px] text-ink-600">{{ __('Enter your HTTP API bot token to configure the webhook and start handling Telegram chats.') }}</p>
                <div>
                    <label class="block text-[11.5px] font-semibold text-ink-700 mb-1.5">{{ __('Bot token') }} <span class="text-accent-coral">*</span></label>
                    <input name="bot_token" type="text" value="{{ old('bot_token') }}" required autocomplete="off" spellcheck="false" placeholder="e.g. 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ"
                        class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                </div>
                <div class="rounded-xl bg-paper-50 border border-paper-200 p-4 text-[12px] text-ink-600">
                    <div class="font-semibold text-ink-800 mb-2 flex items-center gap-1.5"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v3M8 5.2h.01"/></svg>{{ __('How to get a bot token?') }}</div>
                    <ol class="list-decimal list-inside space-y-1">
                        <li>{{ __('Open Telegram and search for the verified') }} <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="text-wa-deep font-semibold hover:underline">@BotFather</a> {{ __('account.') }}</li>
                        <li>{{ __('Send') }} <span class="font-mono">/newbot</span> {{ __('and choose a display name and username.') }}</li>
                        <li>{{ __('Copy the generated HTTP API token and paste it above.') }}</li>
                    </ol>
                </div>
                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" onclick="document.getElementById('tg-bot-modal').classList.add('hidden')" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium">{{ __('Cancel') }}</button>
                    <button type="submit" class="px-5 py-2 rounded-full text-white text-[12.5px] font-semibold hover:opacity-90 transition" style="background:#229ED9">{{ __('Connect Bot') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── MODAL: Connect an account (QR / phone code) ── --}}
    <div id="tg-account-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-900/40" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="bg-paper-0 rounded-2xl shadow-soft border border-paper-200 max-w-2xl w-full overflow-hidden">
            <div class="px-6 py-5 border-b border-paper-200 flex items-start justify-between gap-4">
                <div class="flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-xl grid place-items-center text-white" style="background:#1B7CB0"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="8" cy="5" r="3"/><path d="M2.5 13.5a5.5 5.5 0 0 1 11 0"/></svg></span>
                    <h3 class="font-serif text-[20px] leading-tight">{{ __('Connect Telegram Account') }}</h3>
                </div>
                <button type="button" onclick="document.getElementById('tg-account-modal').classList.add('hidden')" class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center" title="{{ __('Close') }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>
            <div class="p-6">
                <p class="text-[12.5px] text-ink-600 mb-4">{{ __('Two ways in, like WhatsApp: scan a QR (no number typed), or use your phone number. This links your account so you can create bots without @BotFather.') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-start"
                    data-tg-qr data-qr-start="{{ route('user.telegram.account.qr-start') }}" data-qr-poll="{{ route('user.telegram.account.qr-poll') }}">
                    {{-- QR --}}
                    <div class="rounded-2xl border border-paper-200 p-4">
                        <div class="text-[13px] font-semibold text-ink-900 mb-2">{{ __('Scan to log in') }}</div>
                        <div class="flex items-start gap-4">
                            <div class="shrink-0 w-[150px] h-[150px] rounded-xl bg-paper-50 border border-paper-200 grid place-items-center overflow-hidden">
                                <img data-tg-qr-img alt="{{ __('Telegram login QR') }}" class="w-full h-full object-contain" hidden>
                                <span data-tg-qr-idle class="text-[11px] text-ink-400 text-center px-3">{{ __('Press “Show QR”') }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <ol class="text-[11px] text-ink-600 space-y-1 list-decimal list-inside">
                                    <li>{{ __('Open Telegram on your phone') }}</li>
                                    <li>{{ __('Settings → Devices → Link Desktop Device') }}</li>
                                    <li>{{ __('Point it at this code') }}</li>
                                </ol>
                                <button type="button" data-tg-qr-start class="mt-3 px-4 py-2 rounded-full text-white text-[12px] font-semibold hover:opacity-90 transition" style="background:#229ED9">{{ __('Show QR') }}</button>
                                <p data-tg-qr-msg class="text-[11.5px] text-ink-600 mt-2"></p>
                                <div data-tg-qr-pass-wrap class="mt-2" hidden>
                                    <label class="block text-[11px] text-ink-500 mb-1">{{ __('Two-step password') }}</label>
                                    <div class="flex gap-2">
                                        <input data-tg-qr-pass type="password" autocomplete="current-password" placeholder="••••••••" class="flex-1 rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                        <button type="button" data-tg-qr-pass-go class="px-3 py-2 rounded-full text-white text-[12px] font-semibold" style="background:#229ED9">{{ __('Continue') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{-- Phone --}}
                    <div class="rounded-2xl border border-paper-200 p-4">
                        <div class="text-[13px] font-semibold text-ink-900 mb-2">{{ __('Or use your phone number') }}</div>
                        <p class="text-[11.5px] text-ink-500 mb-3">{{ __('Telegram sends a login code to your Telegram app (or SMS).') }}</p>
                        <form method="POST" action="{{ route('user.telegram.account.send-code') }}" class="space-y-3">
                            @csrf
                            <input name="phone" type="tel" value="{{ old('phone') }}" placeholder="+919876543210"
                                class="w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                            <button type="submit" class="w-full py-2.5 rounded-full text-white text-[13px] font-semibold hover:opacity-90 transition" style="background:#229ED9">{{ __('Send login code') }}</button>
                        </form>
                    </div>
                </div>
                {{-- Setup hint is for the operator who configures the server, not the
                     end user — gate it to admins so the workspace portal never exposes
                     an internal admin path. --}}
                @if (auth()->user()?->isAdmin())
                    <div class="mt-3 pt-3 border-t border-paper-100 text-[11px] text-ink-400">
                        {{ __('Both need a Telegram API id/hash on the server (set in Admin → Channel Settings, from my.telegram.org).') }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.user>
