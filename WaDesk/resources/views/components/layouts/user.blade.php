@props([
    'title' => 'Dashboard',
    'navKey' => 'dashboard',
    'page' => null,
    'hideHeader' => false,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ \App\Support\LocaleSettings::directionFor(app()->getLocale()) }}"
    data-user-shell="{{ \App\Support\UserNav::layout() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Real-time (Pusher) — public key + cluster only (never the secret); the
         current workspace id for the private inbox channel. Blank when an admin
         hasn't enabled real-time, in which case Echo stays off and the inbox
         keeps polling. --}}
    @php $__rt = \App\Support\RealtimeSettings::publicConfig(); @endphp
    <meta name="ws-id" content="{{ auth()->user()?->current_workspace_id }}">
    <meta name="pusher-key" content="{{ ($__rt['enabled'] ?? false) ? $__rt['key'] : '' }}">
    <meta name="pusher-cluster" content="{{ ($__rt['enabled'] ?? false) ? $__rt['cluster'] : '' }}">
    {{-- Sub-folder base path (e.g. /public) so client-side AJAX honours the deploy location under a sub-directory. --}}
    <meta name="app-base" content="{{ wd_base() }}">
    @php $defCountry = app_default_country(); @endphp
    <meta name="default-country-code" content="{{ $defCountry['code'] }}">
    <meta name="default-country-iso"  content="{{ $defCountry['iso'] }}">
    {{-- Active currency symbol (workspace override → platform default) for
 chart JS so axes/tooltips follow the chosen currency, not '$'. --}}
    <meta name="currency-symbol" content="{{ \App\Support\FormatSettings::symbol(auth()->user()?->current_workspace) }}">
    @php $faviconUrl = \App\Support\Brand::faviconUrl(); @endphp
    @if ($faviconUrl)
        <link rel="icon" type="image/x-icon" href="{{ $faviconUrl }}">
        <link rel="shortcut icon" href="{{ $faviconUrl }}">
    @endif
    {{-- Server flash → toast. Any controller that does
 redirect()->with('status' / 'success' / 'error') ends up
 here as a meta tag, and resources/js/wa-toaster.js shows
 it on DOMContentLoaded. Keeps UI feedback consistent
 without each page rolling its own banner. --}}
    @php
        $flashVariant = session('error') ? 'error' : (session('warning') ? 'warn' : 'success');
        $flashMessage = session('error') ?? (session('warning') ?? (session('success') ?? session('status')));
    @endphp
    @if ($flashMessage)
        <meta name="wa-flash" content='@json(['variant' => $flashVariant, 'message' => $flashMessage])'>
    @endif
    @php $__brandName = (string) brand_name(); @endphp
    <title>{{ $title }} — {{ $__brandName }}</title>
    {{-- SEO meta block — single source at /admin/settings/seo. --}}
    @include('partials.seo-meta', ['seoOverrides' => ['title' => $title . ' — ' . $__brandName]])
    {{-- On the Team-Inbox page, when the separate Team-Inbox PWA is enabled,
         emit ITS scoped manifest instead of the app-wide one (a page may carry
         only one effective manifest). Every other page keeps the app PWA. --}}
    @if (($navKey ?? '') === 'team-inbox' && \App\Models\SystemSetting::get('ti_pwa_enabled', false))
        @include('partials.teaminbox-pwa')
    @else
        @include('partials.pwa-meta')
    @endif
    @include('partials.site-analytics')
    <x-theme-bootstrap />
    @php
        // Per-theme logo URLs for live theme-switch in wadesk.js.
        $brandLogos = [];
        foreach (['paper', 'bright', 'dark', 'doodle'] as $__t) {
            $brandLogos[$__t] = \App\Support\Brand::logoUrl($__t);
        }
    @endphp
    <script>
        window.WADESK_BRAND = {
            logos: @json($brandLogos),
            appName: @json(brand_name())
        };
    </script>
    {{-- Sprint 6 · Branding tab — workspace-scoped brand colors get
 emitted as CSS vars AND class overrides. Only renders when
 at least one brand_* column is set on the current workspace;
 otherwise the default Tailwind palette wins. --}}
    @auth
        @php
            $brandWs = auth()->user()->currentWorkspace;
        @endphp
        @if ($brandWs && ($brandWs->brand_primary || $brandWs->brand_accent || $brandWs->brand_background))
            <style>
                :root {
                    @if ($brandWs->brand_primary)
                        --brand-primary: {{ $brandWs->brand_primary }};
                    @endif
                    @if ($brandWs->brand_accent)
                        --brand-accent: {{ $brandWs->brand_accent }};
                    @endif
                    @if ($brandWs->brand_background)
                        --brand-bg: {{ $brandWs->brand_background }};
                    @endif
                }

                @if ($brandWs->brand_primary)
                    .bg-wa-deep,
                    .hover\:bg-wa-deep:hover {
                        background-color: var(--brand-primary) !important;
                    }

                    .text-wa-deep {
                        color: var(--brand-primary) !important;
                    }

                    .border-wa-deep,
                    .hover\:border-wa-deep:hover,
                    .focus\:border-wa-deep:focus {
                        border-color: var(--brand-primary) !important;
                    }
                @endif
                @if ($brandWs->brand_accent)
                    .bg-wa-teal,
                    .hover\:bg-wa-teal:hover {
                        background-color: var(--brand-accent) !important;
                    }

                    .text-wa-teal {
                        color: var(--brand-accent) !important;
                    }
                @endif
                @if ($brandWs->brand_background)
                    body {
                        background-color: var(--brand-bg) !important;
                    }
                @endif
            </style>
        @endif
    @endauth
    @auth
        <script>
            @php
                $ws = auth()->user()->currentWorkspace;
                $planLabel = $ws?->billingPackage()?->pname ?: __('Free');
                $roleLabel = $ws && (int) $ws->owner_user_id === (int) auth()->id() ? __('workspace owner') : (auth()->user()->isAdmin() ? __('admin') : __('member'));
            @endphp
            window.WADESK_USER = {
                name: @json(auth()->user()->name),
                email: @json(auth()->user()->email),
                role: @json(auth()->user()->role ?? 'user'),
                isAdmin: @json(auth()->user()->isAdmin()),
                initials: @json(\Illuminate\Support\Str::of(auth()->user()->name)->trim()->limit(2, '')->upper()->__toString()),
                credits: @json((int) (auth()->user()->wallet_credits ?? 0)),
                creditsPerMessage: @json((int) \App\Models\SystemSetting::get('credits_per_message', 1)),
                walletMoney: @json(\App\Support\FormatSettings::display((int) round(((int) (auth()->user()->wallet_credits ?? 0)) * \App\Services\MessageCreditRate::minorPerCredit()) / 100)),
                referralCode: @json(auth()->user()->referral_code ?? ''),
                plan: @json($planLabel),
                roleLabel: @json($roleLabel),
                appName: @json(brand_name()),
                version: @json(config('version.version', config('app.version', '1.0.0'))),
            };
        </script>
    @endauth
    @auth
        {{-- Guided product tour bootstrap. `run` is true only until the user has
             seen it once (users.has_seen_intro); the tour persists progress in
             localStorage so it can span page navigations, and POSTs to seenUrl
             when finished/skipped so it never auto-runs again. --}}
        <script>
            window.WADESK_TOUR = {
                run: @json(!(bool) (auth()->user()->has_seen_intro ?? false)),
                seenUrl: @json(url('/tour/seen')),
                csrf: @json(csrf_token()),
                path: @json('/' . ltrim(request()->path(), '/')),
            };
        </script>
    @endauth
    {{-- JS i18n helper. Client-side renderers (chat / team-inbox) build UI from
         string literals; window.t(en) returns the active locale's translation for
         that English string (populated per-page via partials.js-i18n) or the
         English key itself as a graceful fallback. Defined before the bundle so
         it always exists by the time any render code calls it. --}}
    <script>
        window.t = function (s) {
            return (window.__i18n && Object.prototype.hasOwnProperty.call(window.__i18n, s)) ? window.__i18n[s] : s;
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.app-font')
    {{-- Admin-set dashboard theme colour overrides — LAST in head so they win --}}
    {!! theme_css() !!}
</head>

<body data-nav="{{ $navKey }}" @if ($page) data-page="{{ $page }}" @endif
    data-theme="{{ auth()->check() ? \App\Support\Brand::activeTheme() : 'paper' }}"
    class="min-h-screen font-sans antialiased bg-paper-50 text-ink-900 overflow-x-clip">
    {{-- Impersonation strip — only present when ImpersonationBanner middleware
 shared a non-null `$impersonation`. Sticky so it survives scroll on
 every page; the form posts to /admin/impersonate/stop which clears
 the session and audit-logs the duration. --}}
    @if (!empty($impersonation) && ($impersonation['active'] ?? false))
        <div class="sticky top-0 z-[60] bg-accent-amber text-ink-900 border-b border-accent-amber/60 shadow-sm">
            <div class="max-w-screen-2xl mx-auto px-4 py-2 flex items-center gap-3 text-[12.5px]">
                <span class="font-mono uppercase tracking-[0.16em] text-[10px]">{{ __('Impersonating') }}</span>
                <span class="font-semibold">{{ $impersonation['target_workspace_name'] ?? 'workspace' }}</span>
                <span class="hidden md:inline text-ink-700">— {{ $impersonation['reason'] }}</span>
                <form method="POST" action="{{ url('/admin/impersonate/stop') }}" class="ml-auto">
                    @csrf
                    <button type="submit"
                        class="px-3 py-1 rounded-full bg-ink-900 text-paper-0 text-[11.5px] font-semibold hover:bg-ink-700">
                        {{ __('Stop impersonating') }}
                    </button>
                </form>
            </div>
        </div>
    @endif

    @php $__userLayout = \App\Support\UserNav::layout(); @endphp

    @if ($__userLayout === 'sidebar' && !$hideHeader)
        {{-- Admin-selected SIDEBAR layout — dark left rail (all nav, no /more,
             no top menu header). Every page's $slot renders unchanged inside the
             main column, so no page can break. --}}
        {{-- App shell — copied 1:1 from ui/dash/dashboard-v3.html: plain flex,
             fixed-height viewport, rail is shrink-0 + h-screen (stays put), and
             ONLY the inner content div scrolls. --}}
        <div class="flex h-screen overflow-hidden">
            <div id="user-rail-backdrop"
                class="fixed inset-0 bg-ink-950/50 z-40 hidden md:hidden transition-opacity"></div>
            <aside id="user-rail"
                class="fixed inset-y-0 left-0 z-50 w-[260px] shrink-0 h-screen transform -translate-x-full md:static md:translate-x-0 transition-transform duration-300 ease-in-out md:transition-none flex flex-col">
                <x-user.sidebar-rail :active="$navKey" />
            </aside>
            <div class="flex-1 min-w-0 flex flex-col h-screen overflow-hidden">
                <x-announcement-bar />
                <x-trial-bar />
                <x-user.topstrip :title="$title" />
                <div class="flex-1 min-h-0 overflow-y-auto">
                    {{ $slot }}
                </div>
            </div>
        </div>
        <script>
            (function () {
                var rail = document.getElementById('user-rail'),
                    bd = document.getElementById('user-rail-backdrop');
                function open() { if (!rail) return; rail.classList.remove('-translate-x-full'); bd && bd.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
                function close() { if (!rail) return; rail.classList.add('-translate-x-full'); bd && bd.classList.add('hidden'); document.body.style.overflow = ''; }
                document.querySelectorAll('[data-user-rail-toggle]').forEach(function (b) { b.addEventListener('click', open); });
                document.querySelectorAll('[data-user-rail-close]').forEach(function (b) { b.addEventListener('click', close); });
                bd && bd.addEventListener('click', close);
                document.querySelectorAll('[data-user-toggle]').forEach(function (btn) {
                    btn.addEventListener('click', function (e) { e.stopPropagation(); var p = btn.parentElement.querySelector('[data-user-pane]'); if (p) p.classList.toggle('hidden'); });
                });
                // Workspace switcher dropdown (in the sidebar rail).
                document.querySelectorAll('[data-ws-toggle]').forEach(function (btn) {
                    btn.addEventListener('click', function (e) { e.stopPropagation(); var m = btn.parentElement.querySelector('[data-ws-menu]'); if (m) m.classList.toggle('hidden'); });
                });
                document.querySelectorAll('[data-ws-menu]').forEach(function (m) { m.addEventListener('click', function (e) { e.stopPropagation(); }); });
                document.addEventListener('click', function () {
                    document.querySelectorAll('[data-user-pane]:not(.hidden)').forEach(function (p) { p.classList.add('hidden'); });
                    document.querySelectorAll('[data-ws-menu]:not(.hidden)').forEach(function (p) { p.classList.add('hidden'); });
                });

                // Collapsible nav sections (open/close). Persists which groups the
                // operator collapsed in localStorage, but ALWAYS keeps the group
                // that holds the current page expanded so you never lose your place.
                var RAIL_LS = 'wd_rail_collapsed';
                function railCollapsedSet() {
                    try { return JSON.parse(localStorage.getItem(RAIL_LS) || '[]') || []; } catch (e) { return []; }
                }
                document.querySelectorAll('[data-rail-group]').forEach(function (g) {
                    var key = g.getAttribute('data-rail-key');
                    var items = g.querySelector('[data-rail-items]');
                    // Apply the restored collapsed state WITHOUT the max-height
                    // animation — otherwise every page load visibly animates the
                    // previously-collapsed sections shut, shoving the rail "up".
                    if (items) items.style.transition = 'none';
                    if (!g.hasAttribute('data-rail-active') && railCollapsedSet().indexOf(key) !== -1) {
                        g.classList.add('collapsed');
                    }
                    var btn = g.querySelector('[data-rail-toggle]');
                    btn && btn.addEventListener('click', function () {
                        g.classList.toggle('collapsed');
                        var set = {};
                        railCollapsedSet().forEach(function (k) { set[k] = 1; });
                        if (g.classList.contains('collapsed')) { set[key] = 1; } else { delete set[key]; }
                        try { localStorage.setItem(RAIL_LS, JSON.stringify(Object.keys(set))); } catch (e) {}
                    });
                });
                // Re-enable the collapse animation AFTER the initial state settles,
                // so user-initiated toggles still animate smoothly.
                requestAnimationFrame(function () {
                    document.querySelectorAll('#user-rail [data-rail-items]').forEach(function (it) { it.style.transition = ''; });
                });

                // Keep the rail's scroll position across full-page navigations. A
                // menu click reloads the page, and without this the rail snapped
                // back to the top — so the item you just clicked scrolled out of
                // view and looked inactive, and the whole rail "jumped up". Restore
                // the saved position, then make sure the active item is on-screen.
                var railScroll = document.querySelector('#user-rail .rail-scroll');
                if (railScroll) {
                    var RAIL_SCROLL_LS = 'wd_rail_scroll';
                    try {
                        var saved = parseInt(sessionStorage.getItem(RAIL_SCROLL_LS) || '0', 10);
                        if (saved > 0) railScroll.scrollTop = saved;
                    } catch (e) {}
                    railScroll.addEventListener('scroll', function () {
                        try { sessionStorage.setItem(RAIL_SCROLL_LS, String(railScroll.scrollTop)); } catch (e) {}
                    }, { passive: true });
                    var activeLink = railScroll.querySelector('.rail-link.active');
                    if (activeLink) {
                        var aTop = activeLink.offsetTop, aBot = aTop + activeLink.offsetHeight;
                        if (aTop < railScroll.scrollTop || aBot > railScroll.scrollTop + railScroll.clientHeight) {
                            activeLink.scrollIntoView({ block: 'nearest' });
                        }
                    }
                }
            })();
        </script>
    @else
        @unless ($hideHeader)
            <x-announcement-bar />
            <x-trial-bar />
            <x-user.header :active="$navKey" />
        @endunless

        {{ $slot }}
    @endif

    {{-- In-app paywall — slides up over the page when the current plan
 doesn't include the feature being viewed. Self-guards (admins /
 unlocked / non-gated pages render nothing). --}}
    <x-plan-paywall />

    {{-- Global "Connect device" popover — opened from any device picker via a
         [data-connect-device] trigger / window.openConnectDevice(). Iframes the
         devices connect flow so a user can add a number without leaving the page. --}}
    <x-user.connect-device-sheet />

    {{-- GDPR cookie consent — auto-opens on first visit; admin can
 disable globally at /admin/settings/pwa. --}}
    @include('partials.cookie-consent')

    @stack('scripts')

    @auth
        <form id="logoutForm" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>

        {{-- Global "new messages" notification widget. Appears bottom-right
 on every page EXCEPT the team-inbox itself (where you already
 see the chats live). Polls /team-inbox/api/unread-summary
 every 15s, pauses when the tab is hidden, exponential
 backoff on 429 / errors. --}}
        @if (in_array($navKey, ['team-inbox'], true) === false)
            <x-user.inbox-bell />
        @endif

        {{-- Global quick-access edge drawer + its editor modal — jump anywhere
             and customise shortcuts from any page. --}}
        <x-user.quick-access-drawer />
        <x-user.quick-access-modal />
    @endauth
</body>

</html>
