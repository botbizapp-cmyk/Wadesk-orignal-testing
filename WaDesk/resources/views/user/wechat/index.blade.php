<x-layouts.user :title="__('WeChat')" nav-key="more" page="user-wechat-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Channel') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">
                    {{ __('WeChat') }} <span class="italic" style="color:#07C160">{{ __('Official Account') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">
                    {{ __('Connect a WeChat Official Account (certified Service Account). Inbound lands in your shared Team Inbox and replies, keyword rules and auto-responders work exactly like every other channel.') }}
                </p>
            </div>
            @if ($channels->isNotEmpty())
                <div class="shrink-0 flex items-center gap-2 flex-wrap">
                    <a href="{{ route('user.wechat.menu') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Menu') }}</a>
                    <a href="{{ route('user.wechat.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Insights') }}</a>
                    @if (\Illuminate\Support\Facades\Route::has('user.wechat.broadcasts.index'))
                        <a href="{{ route('user.wechat.broadcasts.index') }}" class="px-4 py-2 rounded-full text-white text-[12px] font-semibold inline-flex items-center gap-2" style="background:#07C160">
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
                    <p class="text-[12.5px] text-ink-600 mt-1">{{ __('Paste the AppID, AppSecret and the server Token from the WeChat Official Accounts Platform (Settings → Basic Configuration).') }}</p>

                    <form method="POST" action="{{ route('user.wechat.connect') }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Account name') }}</span>
                            <input name="account_name" type="text" value="{{ old('account_name') }}"
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] focus:outline-none focus:border-wa-deep"
                                placeholder="{{ __('e.g. Aurora Cafe (WeChat)') }}">
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-[11.5px] font-semibold text-ink-700">{{ __('AppID') }}</span>
                                <input name="app_id" type="text" required value="{{ old('app_id') }}"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                    placeholder="wx0123456789abcdef">
                            </label>
                            <label class="block">
                                <span class="text-[11.5px] font-semibold text-ink-700">{{ __('AppSecret') }}</span>
                                <input name="app_secret" type="password" required autocomplete="new-password"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                    placeholder="••••••••">
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Server Token') }}</span>
                            <input name="verify_token" type="text" required value="{{ old('verify_token') }}"
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                placeholder="{{ __('the Token you set in Server Config') }}">
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-[11.5px] font-semibold text-ink-700">{{ __('EncodingAESKey (optional)') }}</span>
                                <input name="encoding_aes_key" type="text" value="{{ old('encoding_aes_key') }}"
                                    class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep"
                                    placeholder="{{ __('43 chars — only for Safe mode') }}">
                            </label>
                            <label class="block">
                                <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Message mode') }}</span>
                                <select name="enc_mode" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] focus:outline-none focus:border-wa-deep">
                                    <option value="compat">{{ __('Compatible (recommended)') }}</option>
                                    <option value="plain">{{ __('Plaintext') }}</option>
                                    <option value="safe">{{ __('Safe / Encrypted (Phase 2)') }}</option>
                                </select>
                            </label>
                        </div>
                        <button type="submit"
                            class="px-4 py-2 rounded-full text-paper-0 text-[12px] font-semibold" style="background:#07C160">
                            {{ __('Connect WeChat') }}
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
                                <span class="w-10 h-10 rounded-xl grid place-items-center text-paper-0 font-bold text-[13px] shrink-0" style="background:#07C160">W</span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[13px] font-semibold">{{ $ch->account_name ?: __('WeChat account') }}
                                        @if ($ch->wx_id)<span class="text-[11px] font-mono text-ink-500">{{ $ch->wx_id }}</span>@endif
                                    </div>
                                    <div class="text-[11px] text-ink-500 mt-0.5">
                                        {{ $ch->active ? __('Active') : __('Paused') }} · <span class="font-mono">{{ $ch->app_id }}</span>
                                        @if ($ch->last_error)· <span class="text-accent-coral">{{ $ch->last_error }}</span>@endif
                                    </div>
                                    <div class="mt-2 rounded-xl bg-paper-50 border border-paper-200 p-3 space-y-1.5">
                                        <div class="text-[10px] uppercase tracking-[0.14em] font-mono text-ink-500">{{ __('Paste into WeChat → Server Config') }}</div>
                                        <div class="text-[10.5px] font-mono text-ink-700 break-all"><span class="text-ink-400">URL:</span> {{ $ch->webhookUrl() }}</div>
                                        <div class="text-[10.5px] font-mono text-ink-700 break-all"><span class="text-ink-400">Token:</span> {{ $ch->verify_token }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <form method="POST" action="{{ route('user.wechat.retry', $ch->id) }}">@csrf
                                        <button class="text-[11px] px-2 py-1 rounded-lg border border-paper-200 hover:bg-paper-50">{{ __('Re-check') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.wechat.toggle', $ch->id) }}">@csrf
                                        <button class="text-[11px] px-2 py-1 rounded-lg border border-paper-200 hover:bg-paper-50">{{ $ch->active ? __('Pause') : __('Enable') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('user.wechat.destroy', $ch->id) }}" onsubmit="return confirm('{{ __('Remove this WeChat channel?') }}')">@csrf @method('DELETE')
                                        <button class="text-[11px] px-2 py-1 rounded-lg text-accent-coral hover:bg-accent-coral/10">{{ __('Remove') }}</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-[12px] text-ink-400">{{ __('No WeChat channels connected yet.') }}</div>
                        @endforelse
                    </div>
                </section>
            </div>

            {{-- Setup guide --}}
            <aside class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('How to connect') }}</div>
                <h3 class="font-serif text-[18px] leading-tight mt-0.5">{{ __('Get your credentials') }}</h3>
                <ol class="mt-3 space-y-2.5 text-[12px] text-ink-700 list-decimal list-inside">
                    <li>{{ __('You need a certified WeChat Official Account (Service Account).') }}</li>
                    <li>{{ __('Open the WeChat Official Accounts Platform → Settings → Basic Configuration → copy the AppID and AppSecret.') }}</li>
                    <li>{{ __('Set a Server Config: choose a Token (any string) and mode (Compatible), and — for Safe mode — an EncodingAESKey.') }}</li>
                    <li>{{ __('Paste AppID, AppSecret and the Token here and Connect.') }}</li>
                    <li>{{ __('Copy the webhook URL + Token shown for your channel back into the OA’s Server Config and Enable it.') }}</li>
                </ol>
                <p class="text-[11px] text-ink-500 mt-3">{{ __('The webhook must be public HTTPS. You may also need to whitelist the server IP in the OA’s IP allowlist. All secrets are encrypted at rest.') }}</p>
                <div class="mt-4 pt-3 border-t border-paper-100">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1.5">{{ __('Good to know') }}</div>
                    <ul class="space-y-1.5 text-[11.5px] text-ink-600">
                        <li class="flex gap-2"><span style="color:#07C160">•</span> {{ __('A certified Service Account is required — Subscription / unverified accounts cannot use these APIs.') }}</li>
                        <li class="flex gap-2"><span style="color:#07C160">•</span> {{ __('Get the AppID + AppSecret from the WeChat Official Accounts Platform (Settings → Basic Configuration).') }}</li>
                        <li class="flex gap-2"><span style="color:#07C160">•</span> {{ __('Paste the webhook URL + Token shown per channel into the OA’s Server Config to activate it.') }}</li>
                        <li class="flex gap-2"><span style="color:#07C160">•</span> {{ __('Free-form replies work within 48 hours of the user’s last message; outside that a Template Message is required.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </main>
</x-layouts.user>
