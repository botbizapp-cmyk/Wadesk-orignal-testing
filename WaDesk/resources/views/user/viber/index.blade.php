<x-layouts.user :title="__('Viber')" nav-key="more" page="user-viber-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Channel') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">
                    {{ __('Viber') }} <span class="italic" style="color:#7360F2">{{ __('Public Account') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">
                    {{ __('Connect a Viber Public Account (Bot). Inbound lands in your shared Team Inbox and replies, keyword rules and auto-responders work exactly like every other channel — we register the webhook for you automatically.') }}
                </p>
            </div>
            @if ($channels->isNotEmpty())
                <div class="shrink-0 flex items-center gap-2 flex-wrap">
                    <a href="{{ route('user.viber.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Insights') }}</a>
                    @if (\Illuminate\Support\Facades\Route::has('user.viber.broadcasts.index'))
                        <a href="{{ route('user.viber.broadcasts.index') }}" class="px-4 py-2 rounded-full text-white text-[12px] font-semibold inline-flex items-center gap-2" style="background:#7360F2">
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

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start">

            {{-- Connect form + connected channels --}}
            <div class="space-y-6 min-w-0">
                <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-6">
                    <h2 class="font-serif text-[22px] leading-tight">{{ __('Connect a channel') }}</h2>
                    <p class="text-[12.5px] text-ink-600 mt-1">{{ __('Paste the authentication token from your Viber Public Account (Admin Panel → your bot → Edit info).') }}</p>

                    <form method="POST" action="{{ route('user.viber.connect') }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Auth token') }}</span>
                            <input name="auth_token" type="text" required autocomplete="off"
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                placeholder="445da6az1s345z78-dazcczb2542zv51a-e0vc5fva17480im9">
                        </label>
                        <button type="submit"
                            class="px-4 py-2 rounded-full text-paper-0 text-[12px] font-semibold" style="background:#7360F2">
                            {{ __('Connect Viber') }}
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
                                <span class="w-10 h-10 rounded-xl grid place-items-center text-paper-0 font-bold text-[13px] shrink-0" style="background:#7360F2">V</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[13px] font-semibold">{{ $ch->bot_name ?: __('Viber bot') }}
                                        @if ($ch->bot_uri)<span class="text-[11px] font-mono text-ink-500">viber.com/{{ $ch->bot_uri }}</span>@endif
                                    </div>
                                    <div class="text-[11px] text-ink-500 mt-0.5">
                                        {{ $ch->active ? __('Active') : __('Paused') }}
                                        @if ($ch->last_error)· <span class="text-accent-coral">{{ $ch->last_error }}</span>@endif
                                    </div>
                                    <div class="text-[10.5px] font-mono text-ink-400 mt-1 break-all">{{ $ch->webhookUrl() }}</div>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <form method="POST" action="{{ route('user.viber.retry', $ch->id) }}">@csrf
                                        <button class="text-[11px] px-2 py-1 rounded-lg border border-paper-200 hover:bg-paper-50">{{ __('Re-hook') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.viber.toggle', $ch->id) }}">@csrf
                                        <button class="text-[11px] px-2 py-1 rounded-lg border border-paper-200 hover:bg-paper-50">{{ $ch->active ? __('Pause') : __('Enable') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.viber.destroy', $ch->id) }}" onsubmit="return confirm('{{ __('Remove this Viber channel?') }}')">@csrf @method('DELETE')
                                        <button class="text-[11px] px-2 py-1 rounded-lg text-accent-coral hover:bg-accent-coral/10">{{ __('Remove') }}</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-[12px] text-ink-400">{{ __('No Viber channels connected yet.') }}</div>
                        @endforelse
                    </div>
                </section>
            </div>

            {{-- Setup guide + restrictions --}}
            <aside class="space-y-4">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('How to connect') }}</div>
                    <h3 class="font-serif text-[18px] leading-tight mt-0.5">{{ __('Where to get the token') }}</h3>
                    <ol class="mt-3 space-y-2.5 text-[12px] text-ink-700 list-decimal list-inside">
                        <li>{{ __('Create a Viber Public Account (Bot) at') }} <span class="font-mono">partners.viber.com</span> {{ __('(or the Viber Admin Panel).') }}</li>
                        <li>{{ __('Open your bot → Edit info → copy the authentication token.') }}</li>
                        <li>{{ __('Paste it here and Connect — we validate it and register the webhook automatically.') }}</li>
                    </ol>
                </div>

                <div class="bg-accent-amber/5 border border-accent-amber/30 rounded-2xl p-5">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-[#7B5A14]">{{ __('Good to know') }}</div>
                    <ul class="mt-2 space-y-2 text-[12px] text-ink-700">
                        <li class="flex gap-2"><span style="color:#7360F2">•</span> {{ __('The webhook needs a public HTTPS domain with a valid CA certificate — a local or IP address is rejected by Viber.') }}</li>
                        <li class="flex gap-2"><span style="color:#7360F2">•</span> {{ __('You can only message a user AFTER they open a chat with your account (subscribe). Until then, a send returns “user not subscribed”.') }}</li>
                        <li class="flex gap-2"><span style="color:#7360F2">•</span> {{ __('There is no 24/48-hour window — once a user has subscribed you can reply anytime.') }}</li>
                        <li class="flex gap-2"><span style="color:#7360F2">•</span> {{ __('Broadcasts send to up to 300 subscribed users per batch (Phase 2). Interactive buttons / carousels arrive in Phase 2.') }}</li>
                        <li class="flex gap-2"><span style="color:#7360F2">•</span> {{ __('The token is encrypted at rest and never shown to the browser again.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </main>
</x-layouts.user>
