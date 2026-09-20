<x-layouts.user :title="__('LINE')" nav-key="more" page="user-line-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Channel') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">
                    {{ __('LINE') }} <span class="italic" style="color:#06C755">{{ __('Official Account') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">
                    {{ __('Connect a LINE Official Account (Messaging API). Inbound lands in your shared Team Inbox and replies, keyword rules and auto-responders work exactly like every other channel.') }}
                </p>
            </div>
            @if ($channels->isNotEmpty())
                <div class="shrink-0 flex items-center gap-2 flex-wrap">
                    <a href="{{ route('user.line.rich-menus.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Rich menus') }}</a>
                    <a href="{{ route('user.line.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Insights') }}</a>
                    @if (\Illuminate\Support\Facades\Route::has('user.line.broadcasts.index'))
                        <a href="{{ route('user.line.broadcasts.index') }}" class="px-4 py-2 rounded-full text-white text-[12px] font-semibold inline-flex items-center gap-2" style="background:#06C755">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 8l12-5-4 12-2.5-4.5L2 8z"/></svg>
                            {{ __('Broadcasts') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>

        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">

            {{-- Connect form + connected channels --}}
            <div class="space-y-6 min-w-0">
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6">
                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Connect a channel') }}</h2>
                    <p class="text-[12.5px] text-ink-600 mt-1">{{ __('Paste the Channel access token and Channel secret from the LINE Developers Console (Messaging API channel).') }}</p>

                    <form method="POST" action="{{ route('user.line.connect') }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Channel access token') }}</span>
                            <textarea name="channel_access_token" rows="3" required
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                placeholder="{{ __('Long-lived channel access token…') }}"></textarea>
                        </label>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Channel secret') }}</span>
                            <input name="channel_secret" type="text" required autocomplete="off"
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                placeholder="{{ __('32-character channel secret') }}">
                        </label>
                        <button type="submit"
                            class="px-4 py-2 rounded-full text-paper-0 text-[12px] font-semibold" style="background:#06C755">
                            {{ __('Connect LINE') }}
                        </button>
                    </form>
                </section>

                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                        <span class="text-[13px] font-semibold">{{ __('Connected channels') }}</span>
                        <span class="text-[11px] font-mono text-ink-400">{{ $channels->count() }}</span>
                    </div>
                    <div class="divide-y divide-paper-100">
                        @forelse ($channels as $ch)
                            <div class="px-5 py-4 flex items-start gap-4">
                                <span class="w-10 h-10 rounded-xl grid place-items-center text-paper-0 font-bold text-[13px] shrink-0" style="background:#06C755">L</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[13px] font-semibold">{{ $ch->display_name ?: __('LINE channel') }}
                                        @if ($ch->basic_id)<span class="text-[11px] font-mono text-ink-500">{{ $ch->basic_id }}</span>@endif
                                    </div>
                                    <div class="text-[11px] text-ink-500 mt-0.5">
                                        {{ $ch->active ? __('Active') : __('Paused') }}
                                        @if ($ch->last_error)· <span class="text-accent-coral">{{ $ch->last_error }}</span>@endif
                                    </div>
                                    <div class="text-[10.5px] font-mono text-ink-400 mt-1 break-all">{{ $ch->webhookUrl() }}</div>
                                    <details class="mt-2 group">
                                        <summary class="text-[11px] text-ink-500 cursor-pointer hover:text-ink-700 select-none inline-flex items-center gap-1">
                                            <svg viewBox="0 0 16 16" class="w-3 h-3 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 4l4 4-4 4"/></svg>
                                            {{ __('Token rotation (v2.1)') }}
                                            @if ($ch->rotationEnabled())<span class="ml-1 px-1.5 py-0.5 rounded-full text-[9px] font-mono bg-wa-mint text-wa-deep">{{ __('on') }}</span>@endif
                                        </summary>
                                        <form method="POST" action="{{ route('user.line.rotation', $ch->id) }}" class="mt-2 space-y-2 p-3 rounded-xl bg-paper-50 border border-paper-200">
                                            @csrf
                                            <p class="text-[10.5px] text-ink-500 leading-snug">{{ __('Optional. Paste the Assertion Signing Key (RSA private key) and its kid from the LINE console to auto-issue short-lived, revocable tokens. Leave blank to keep the pasted long-lived token.') }}</p>
                                            <input name="assertion_kid" type="text" value="{{ $ch->assertion_kid }}" placeholder="{{ __('Key ID (kid)') }}" class="w-full rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[11px] font-mono focus:outline-none focus:border-wa-deep">
                                            <textarea name="assertion_private_key" rows="3" placeholder="-----BEGIN PRIVATE KEY-----" class="w-full rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[10.5px] font-mono focus:outline-none focus:border-wa-deep">{{ $ch->rotationEnabled() ? '' : '' }}</textarea>
                                            <div class="flex items-center gap-2">
                                                <button class="px-3 py-1 rounded-full text-white text-[11px] font-semibold" style="background:#06C755">{{ $ch->rotationEnabled() ? __('Re-issue / update') : __('Enable rotation') }}</button>
                                                @if ($ch->rotationEnabled() && $ch->rotating_token_expires_at)
                                                    <span class="text-[10px] font-mono text-ink-400">{{ __('renews') }} {{ $ch->rotating_token_expires_at->diffForHumans() }}</span>
                                                @endif
                                            </div>
                                        </form>
                                    </details>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <form method="POST" action="{{ route('user.line.retry', $ch->id) }}">@csrf
                                        <button class="text-[11px] px-2 py-1 rounded-lg border border-paper-200 hover:bg-paper-50">{{ __('Re-hook') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.line.toggle', $ch->id) }}">@csrf
                                        <button class="text-[11px] px-2 py-1 rounded-lg border border-paper-200 hover:bg-paper-50">{{ $ch->active ? __('Pause') : __('Enable') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.line.destroy', $ch->id) }}" onsubmit="return confirm('{{ __('Remove this LINE channel?') }}')">@csrf @method('DELETE')
                                        <button class="text-[11px] px-2 py-1 rounded-lg text-accent-coral hover:bg-accent-coral/10">{{ __('Remove') }}</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-[12px] text-ink-400">{{ __('No LINE channels connected yet.') }}</div>
                        @endforelse
                    </div>
                </section>
            </div>

            {{-- Setup guide --}}
            <aside class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('How to connect') }}</div>
                <h3 class="font-serif text-[18px] leading-tight mt-0.5">{{ __('Get your credentials') }}</h3>
                <ol class="mt-3 space-y-2.5 text-[12px] text-ink-700 list-decimal list-inside">
                    <li>{{ __('Create a LINE Official Account, then enable the Messaging API on it (this creates the channel).') }}</li>
                    <li>{{ __('Open the LINE Developers Console → your Messaging API channel.') }}</li>
                    <li>{{ __('Basic settings → copy the Channel secret.') }}</li>
                    <li>{{ __('Messaging API → issue and copy a long-lived Channel access token.') }}</li>
                    <li>{{ __('Paste both here and Connect — we set the webhook for you automatically.') }}</li>
                </ol>
                <p class="text-[11px] text-ink-500 mt-3">{{ __('The webhook must be public HTTPS. Both values are encrypted at rest and never shown to the browser again.') }}</p>
                <div class="mt-4 pt-3 border-t border-paper-100">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1.5">{{ __('Good to know') }}</div>
                    <ul class="space-y-1.5 text-[11.5px] text-ink-600">
                        <li class="flex gap-2"><span style="color:#06C755">•</span> {{ __('The channel access token + secret are per-channel — get them in the LINE Developers Console (your Messaging API channel).') }}</li>
                        <li class="flex gap-2"><span style="color:#06C755">•</span> {{ __('The webhook needs a public HTTPS URL — we register it for you automatically on connect.') }}</li>
                        <li class="flex gap-2"><span style="color:#06C755">•</span> {{ __('A user must add your LINE account as a friend before you can message them.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </main>
</x-layouts.user>
