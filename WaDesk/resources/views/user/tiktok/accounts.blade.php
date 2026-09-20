@php
    /** @var \Illuminate\Support\Collection $accounts */
    /** @var bool $configured */
    $tiktokGlyph = '<path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/>';
@endphp

<x-layouts.user :title="__('TikTok accounts')" nav-key="tiktok-accounts" page="user-tiktok-accounts">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- ===== Header ===== --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Channel') }} · {{ __('TikTok') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('TikTok') }} <span class="italic text-wa-deep">{{ __('accounts') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Connect a TikTok account to see its profile and, soon, publish content and read insights straight from :brand.', ['brand' => brand_name()]) }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if ($configured && $accounts->isNotEmpty())
                    <a href="{{ route('user.tiktok.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Insights') }}</a>
                @endif
                @if ($configured)
                    <a href="{{ route('user.tiktok.connect') }}"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-ink-900 text-paper-0 text-[13px] font-semibold hover:bg-ink-800 transition">
                        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor">{!! $tiktokGlyph !!}</svg>
                        {{ __('Connect TikTok') }}
                    </a>
                @endif
            </div>
        </div>

        {{-- ===== Flash + errors ===== --}}
        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if (! $configured)
            {{-- Not configured by the platform admin yet --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $tiktokGlyph !!}</svg>
                </span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('TikTok is not enabled yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 max-w-md mx-auto">{{ __('The platform admin needs to add the TikTok app credentials under Settings → :brand Message before accounts can be connected.', ['brand' => brand_name()]) }}</p>
            </div>
        @elseif ($accounts->isEmpty())
            {{-- Empty state --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $tiktokGlyph !!}</svg>
                </span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('No TikTok account connected yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 mb-4 max-w-md mx-auto">{{ __('Connect a TikTok Business account to manage it here. You will be redirected to TikTok to authorize :brand.', ['brand' => brand_name()]) }}</p>
                <a href="{{ route('user.tiktok.connect') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-ink-900 text-paper-0 text-[12.5px] font-semibold hover:bg-ink-800 transition">
                    <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor">{!! $tiktokGlyph !!}</svg>
                    {{ __('Connect TikTok') }}
                </a>
            </div>
        @else
            {{-- Connected accounts --}}
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($accounts as $a)
                    @php
                        $live = $a->isLive();
                        $handle = $a->username ? '@' . ltrim($a->username, '@') : $a->open_id;
                    @endphp
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                        <div class="p-4 flex items-start gap-3.5">
                            @if ($a->avatar_url)
                                <img src="{{ $a->avatar_url }}" alt="" referrerpolicy="no-referrer"
                                    onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='grid';}"
                                    class="w-14 h-14 rounded-2xl object-cover bg-paper-100 shrink-0 border border-paper-200">
                                <span style="display:none" class="w-14 h-14 rounded-2xl place-items-center shrink-0 bg-ink-900 text-white text-[20px] font-semibold">{{ strtoupper(mb_substr($a->display_name ?: 'T', 0, 1)) }}</span>
                            @else
                                <span class="w-14 h-14 rounded-2xl grid place-items-center shrink-0 bg-ink-900 text-white text-[20px] font-semibold">{{ strtoupper(mb_substr($a->display_name ?: 'T', 0, 1)) }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[15px] font-semibold text-ink-900 truncate">{{ $a->display_name ?: $handle }}</span>
                                    @if ($a->is_verified)
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-wa-deep shrink-0" fill="currentColor" title="{{ __('Verified') }}"><path d="M8 1l1.8 1.3 2.2-.2.9 2 2 .9-.2 2.2L16 8l-1.3 1.8.2 2.2-2 .9-.9 2-2.2-.2L8 15l-1.8-1.3-2.2.2-.9-2-2-.9.2-2.2L0 8l1.3-1.8L1.1 4l2-.9.9-2 2.2.2z"/><path d="M6.8 10.4 4.7 8.3l.9-.9 1.2 1.2 3-3 .9.9z" fill="#fff"/></svg>
                                    @endif
                                </div>
                                <div class="text-[12px] text-ink-500 font-mono truncate">{{ $handle }}</div>
                                <div class="mt-1.5">
                                    @if ($live)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-accent-amber/15 text-[#7B5A14] border border-accent-amber/40"><span class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ __('Reconnect') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Stats --}}
                        <div class="grid grid-cols-3 border-t border-paper-100 divide-x divide-paper-100 text-center">
                            <div class="py-2.5"><div class="font-serif text-[17px] leading-none">{{ $a->follower_count !== null ? number_format($a->follower_count) : '—' }}</div><div class="text-[10px] text-ink-500 font-mono mt-0.5">{{ __('followers') }}</div></div>
                            <div class="py-2.5"><div class="font-serif text-[17px] leading-none">{{ $a->likes_count !== null ? number_format($a->likes_count) : '—' }}</div><div class="text-[10px] text-ink-500 font-mono mt-0.5">{{ __('likes') }}</div></div>
                            <div class="py-2.5"><div class="font-serif text-[17px] leading-none">{{ $a->video_count !== null ? number_format($a->video_count) : '—' }}</div><div class="text-[10px] text-ink-500 font-mono mt-0.5">{{ __('videos') }}</div></div>
                        </div>

                        @if ($a->last_error && ! $live)
                            <div class="px-4 py-2 bg-accent-coral/5 text-[11px] text-accent-coral border-t border-paper-100">{{ $a->last_error }}</div>
                        @endif

                        {{-- Actions --}}
                        <div class="px-4 py-3 border-t border-paper-100 flex items-center justify-between gap-2">
                            @unless ($live)
                                <a href="{{ route('user.tiktok.connect') }}" class="text-[12px] font-semibold text-wa-deep hover:underline">{{ __('Reconnect') }}</a>
                            @else
                                <span class="text-[11px] text-ink-400 font-mono">{{ __('Token OK') }}{{ $a->token_expires_at ? ' · ' . $a->token_expires_at->diffForHumans() : '' }}</span>
                            @endunless
                            <div class="flex items-center gap-1">
                                <form method="POST" action="{{ route('user.tiktok.refresh', $a->id) }}">
                                    @csrf
                                    <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition" title="{{ __('Refresh profile') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3"/><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8"/></svg>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('user.tiktok.disconnect', $a->id) }}" data-confirm="{{ __('Disconnect this TikTok account? It will be removed from your workspace — you can reconnect it later.') }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition" title="{{ __('Disconnect') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- DM inbox (Business Messaging) — shown only when the admin has
                             configured the TikTok business app. Partner-gated. --}}
                        @if (\App\Services\Tiktok\TiktokBusinessClient::enabled())
                            @php
                                $biz   = is_array($a->meta_json) ? (array) data_get($a->meta_json, 'business', []) : [];
                                $bizOn = ! empty($biz['access_token'] ?? null);
                            @endphp
                            <div class="px-4 py-3 border-t border-paper-100">
                                <div class="flex items-center justify-between gap-2 mb-1.5">
                                    <span class="text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500">{{ __('DM inbox') }}</span>
                                    @if ($bizOn)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-mono bg-wa-mint text-wa-deep"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}{{ ! empty($biz['region']) ? ' · ' . e($biz['region']) : '' }}</span>
                                    @endif
                                </div>
                                @if ($bizOn)
                                    <p class="text-[11px] text-ink-500 leading-snug">{{ __('Incoming DMs route to the Team Inbox. Register this webhook URL on your TikTok app in the developer portal (subscribe new_message / new_conversation):') }}</p>
                                    <code class="block mt-1 text-[10.5px] bg-paper-100 rounded px-2 py-1 break-all">{{ url('/webhooks/tiktok/business') }}</code>
                                    <form method="POST" action="{{ route('user.tiktok.inbox.disconnect', $a->id) }}" class="mt-2" data-confirm="{{ __('Disconnect the DM inbox for this account?') }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-[11px] font-semibold text-accent-coral hover:underline">{{ __('Disconnect DM inbox') }}</button>
                                    </form>
                                @else
                                    <p class="text-[11px] text-ink-500 leading-snug mb-2">{{ __('Requires TikTok Messaging-Partner approval. Once approved, paste the business ID + access token from your partner authorization to route DMs into the Team Inbox.') }}</p>
                                    <form method="POST" action="{{ route('user.tiktok.inbox.connect', $a->id) }}" class="space-y-2">
                                        @csrf
                                        <input type="text" name="business_id" required placeholder="{{ __('Business ID') }}" class="w-full rounded-lg border border-paper-200 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                                        <input type="text" name="business_access_token" required placeholder="{{ __('Business access token') }}" class="w-full rounded-lg border border-paper-200 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                                        <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-ink-900 text-paper-0 text-[12px] font-semibold hover:bg-ink-800 transition">{{ __('Connect DM inbox') }}</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- ===== Feature availability & requirements ===== --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('What works, and what it needs') }}</div>
                <div class="divide-y divide-paper-100 text-[12.5px]">
                    @php
                        $rows = [
                            ['t' => __('Connect account'), 'ok' => true, 'r' => __('Works everywhere. Needs the TikTok Login Kit app (admin-configured).')],
                            ['t' => __('Profile & video insights'), 'ok' => true, 'r' => __('Works everywhere. Needs the user.info + video.list scopes approved on your TikTok app.')],
                            ['t' => __('Posting to TikTok inbox'), 'ok' => true, 'r' => __('Works everywhere. Needs video.upload scope + a verified video-URL domain. The creator finishes the post in the TikTok app.')],
                            ['t' => __('Direct publish to profile'), 'ok' => 'partial', 'r' => __('Needs TikTok\'s full app audit (demo video/screenshots). Until then posts go to the inbox as drafts.')],
                            ['t' => __('DM inbox (Business Messaging)'), 'ok' => 'partial', 'r' => __('Wired end-to-end and connectable per account once you have a TikTok API for Business app + Messaging-Partner approval — paste the business ID + access token on the account card above. NOT available in the US, EEA, Switzerland or the UK. Capped at 10 messages per 48h after the first user message.')],
                            ['t' => __('Comment reply / moderation'), 'ok' => 'partial', 'r' => __('Reply, hide and list comments need Business Account API approval (poll-based — no comment webhook).')],
                            ['t' => __('Comment-to-DM automation'), 'ok' => false, 'r' => __('TikTok\'s API does not offer comment triggers, so this cannot be built for any vendor.')],
                        ];
                        $dot = ['true' => 'bg-wa-green', 'partial' => 'bg-accent-amber', 'false' => 'bg-accent-coral'];
                    @endphp
                    @foreach ($rows as $row)
                        <div class="px-5 py-3 flex items-start gap-3">
                            <span class="w-2 h-2 rounded-full mt-1.5 shrink-0 {{ $dot[var_export($row['ok'], true)] ?? 'bg-ink-300' }}"></span>
                            <div class="min-w-0">
                                <div class="font-semibold text-ink-800">{{ $row['t'] }}</div>
                                <div class="text-ink-500 text-[11.5px] mt-0.5">{{ $row['r'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="px-5 py-3 bg-paper-50/60 border-t border-paper-100 flex items-center gap-4 text-[10.5px] text-ink-500 font-mono">
                    <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('available') }}</span>
                    <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ __('needs approval') }}</span>
                    <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent-coral"></span>{{ __('gated / not offered') }}</span>
                </div>
            </section>
        @endif
    </main>
</x-layouts.user>
