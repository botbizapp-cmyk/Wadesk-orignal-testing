@props(['active' => 'dashboard'])

{{-- Operator-console sidebar (matches ui/dash/dashboard-v3). Shown only when the
     admin picks the "sidebar" user-dashboard layout. Colour is admin-set
     (Appearance → Sidebar colour); ALL text/icons/panels auto-adapt to the
     chosen background's brightness (dark bg → light text, light bg → dark text)
     via CSS variables, so any colour stays readable. --}}

@php
    $groups = \App\Support\UserNav::groups();
    $u = auth()->user();
    $ws = $u?->currentWorkspace;
    $wsName = $ws?->name ?: brand_name();
    $wsInitials = \Illuminate\Support\Str::of($wsName)->trim()->limit(2, '')->upper()->__toString();
    $planLabel = $ws?->billingPackage()?->pname ?: __('Free');
    $walletMoney = \App\Support\FormatSettings::display((int) round(((int) ($u->wallet_credits ?? 0)) * \App\Services\MessageCreditRate::minorPerCredit()) / 100);
    $logoUrl = \App\Support\Brand::logoUrl(\App\Support\Brand::activeTheme());
    // White-label tenant domain: locked to one workspace — no switch targets/create.
    $__lockWs = ($isTenantDomain ?? false);
    $allWorkspaces = ($u && !$__lockWs) ? $u->workspaces()->orderByDesc('last_active_at')->get() : collect();
    $canCreateWorkspace = ($u && !$__lockWs) ? $u->canCreateWorkspace() : false;

    // Admin-customisable rail colour + auto contrast.
    $railBg = (string) \App\Models\SystemSetting::get('user_sidebar_color', '') ?: '#06100E';
    $__hex = ltrim($railBg, '#');
    if (strlen($__hex) === 3) {
        $__hex = $__hex[0].$__hex[0].$__hex[1].$__hex[1].$__hex[2].$__hex[2];
    }
    $__lum = 0.05;
    if (strlen($__hex) === 6 && ctype_xdigit($__hex)) {
        $__r = hexdec(substr($__hex, 0, 2));
        $__g = hexdec(substr($__hex, 2, 2));
        $__b = hexdec(substr($__hex, 4, 2));
        $__lum = (0.299 * $__r + 0.587 * $__g + 0.114 * $__b) / 255;
    }
    $light = $__lum > 0.6; // light background → use dark text (auto)

    // Optional admin overrides.
    $textColor = (string) \App\Models\SystemSetting::get('user_sidebar_text_color', '');
    $accentColor = (string) \App\Models\SystemSetting::get('user_sidebar_accent_color', '') ?: '#25D366';

    // hex → "r,g,b" helper for building translucent tokens.
    $rgb = function (string $hex): string {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
        if (strlen($h) !== 6 || !ctype_xdigit($h)) { return '255,255,255'; }
        return hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2));
    };

    if ($textColor !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $textColor)) {
        // Explicit text colour → derive the whole scale from it.
        $t = $rgb($textColor);
        $vars = [
            '--rfg' => "rgba($t,0.72)", '--rfgs' => $textColor, '--rfgm' => "rgba($t,0.5)",
            '--rcap' => "rgba($t,0.42)", '--rhover' => "rgba($t,0.08)", '--rpanel' => "rgba($t,0.06)",
            '--rpanelbd' => "rgba($t,0.14)", '--rdot' => "rgba($t,0.07)", '--rscroll' => "rgba($t,0.2)",
        ];
    } else {
        $vars = [
            '--rfg'      => $light ? 'rgba(11,31,28,0.68)'  : 'rgba(255,255,255,0.62)',
            '--rfgs'     => $light ? '#0B1F1C'              : '#FBFAF6',
            '--rfgm'     => $light ? 'rgba(11,31,28,0.5)'   : 'rgba(255,255,255,0.45)',
            '--rcap'     => $light ? 'rgba(11,31,28,0.42)'  : 'rgba(255,255,255,0.32)',
            '--rhover'   => $light ? 'rgba(0,0,0,0.05)'     : 'rgba(255,255,255,0.06)',
            '--rpanel'   => $light ? 'rgba(0,0,0,0.045)'    : 'rgba(255,255,255,0.05)',
            '--rpanelbd' => $light ? 'rgba(0,0,0,0.10)'     : 'rgba(255,255,255,0.10)',
            '--rdot'     => $light ? 'rgba(0,0,0,0.05)'     : 'rgba(255,255,255,0.06)',
            '--rscroll'  => $light ? 'rgba(0,0,0,0.18)'     : 'rgba(255,255,255,0.15)',
        ];
    }
    $vars['--racc'] = $accentColor;
    $vars['--racc-tint'] = 'rgba(' . $rgb($accentColor) . ',0.16)';

    // A custom background is a fixed inline colour (overrides theme). Without one,
    // the CSS below drives the background AND the text/panel colours per active
    // theme, so the rail live-switches light↔dark like the admin sidebar (the app
    // themes paper/bright/doodle are LIGHT, only `dark` is dark).
    $hasCustomBg = trim((string) \App\Models\SystemSetting::get('user_sidebar_color', '')) !== '';
    $applyInlineText = $hasCustomBg || ($textColor !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $textColor));
    $styleVars = $hasCustomBg ? ('background:' . $railBg . ';') : '';
    foreach ($vars as $k => $v) {
        // Accent (brand) always applies inline; text/panel vars only when a custom
        // colour is configured — otherwise the theme CSS below owns them so the
        // rail adapts to light/dark on its own.
        if ($k === '--racc' || $k === '--racc-tint' || $applyInlineText) {
            $styleVars .= $k . ':' . $v . ';';
        }
    }
@endphp

<style>
    .rail-link { position:relative; display:flex; align-items:center; gap:12px; padding:9px 13px; border-radius:12px; font-size:13.5px; font-weight:500; color:var(--rfg); transition:.15s; }
    .rail-link:hover { background:var(--rhover); color:var(--rfgs); }
    .rail-link.active { background:var(--racc-tint); color:var(--rfgs); }
    .rail-link.active::before { content:""; position:absolute; left:-13px; top:50%; transform:translateY(-50%); width:3px; height:20px; border-radius:0 3px 3px 0; background:var(--racc); }
    /* Plan-locked: readable but clearly not active. Muted rather than hidden so
       the product's shape stays visible; the lock sits at the row's end. */
    .rail-link.locked { color:var(--rfgm); }
    .rail-link.locked .rail-ic { opacity:.55; }
    .rail-link.locked:hover { background:var(--rhover); color:var(--rfg); }
    .rail-lock { width:13px; height:13px; margin-left:auto; flex:none; opacity:.6; }
    .rail-ic { width:18px; height:18px; flex-shrink:0; }
    .rail-cap { font-family:ui-monospace,'JetBrains Mono',monospace; font-size:9px; text-transform:uppercase; letter-spacing:0.18em; color:var(--rcap); padding:0 13px; margin:16px 0 6px; }
    {{-- Collapsible section header (open/close) --}}
    .rail-cap-btn { display:flex; align-items:center; justify-content:space-between; gap:8px; width:100%; background:none; border:0; cursor:pointer; text-align:left; }
    .rail-cap-btn:hover { color:var(--rfgm); }
    .rail-cap-chev { width:11px; height:11px; flex-shrink:0; transition:transform .2s ease; opacity:.55; }
    .rail-group.collapsed .rail-cap-chev { transform:rotate(-90deg); }
    .rail-group-items { overflow:hidden; max-height:1200px; transition:max-height .24s ease; }
    .rail-group.collapsed .rail-group-items { max-height:0; }
    .rail-scroll::-webkit-scrollbar { width:7px; }
    .rail-scroll::-webkit-scrollbar-thumb { background:var(--rscroll); border-radius:4px; }
    .rail-dot { background-image:radial-gradient(circle at 1px 1px, var(--rdot) 1px, transparent 0); background-size:16px 16px; }
    .rail-panel { background:var(--rpanel); border:1px solid var(--rpanelbd); transition:.15s; }
    .rail-panel:hover { background:var(--rhover); }
    .rail-fg  { color:var(--rfgs); }
    .rail-fgm { color:var(--rfgm); }
    {{-- Theme-adaptive rail — active only when the admin has NOT set a custom
         sidebar colour (a custom colour is inlined on the element and wins). The
         app's paper/bright/doodle themes are LIGHT → light rail with dark text;
         `dark` is the only dark theme → dark rail with light text. This is what
         makes the user rail follow the theme like the admin sidebar. --}}
    .user-rail-root {
        --rfg:rgba(11,31,28,0.66); --rfgs:#0B1F1C; --rfgm:rgba(11,31,28,0.5);
        --rcap:rgba(11,31,28,0.42); --rhover:rgba(0,0,0,0.05); --rpanel:rgba(0,0,0,0.04);
        --rpanelbd:rgba(0,0,0,0.10); --rdot:rgba(0,0,0,0.05); --rscroll:rgba(0,0,0,0.18);
        background:#FBFAF6; border-right:1px solid rgba(0,0,0,0.07);
    }
    :root[data-theme="bright"] .user-rail-root { background:#FFFFFF; }
    {{-- doodle is a green-tinted light theme → match it with a soft green rail --}}
    :root[data-theme="doodle"] .user-rail-root { background:#EDF6E7; border-right-color:rgba(11,31,28,0.08); }
    :root[data-theme="dark"] .user-rail-root {
        --rfg:rgba(255,255,255,0.62); --rfgs:#FBFAF6; --rfgm:rgba(255,255,255,0.45);
        --rcap:rgba(255,255,255,0.32); --rhover:rgba(255,255,255,0.06); --rpanel:rgba(255,255,255,0.05);
        --rpanelbd:rgba(255,255,255,0.10); --rdot:rgba(255,255,255,0.06); --rscroll:rgba(255,255,255,0.15);
        background:#0A0F0E; border-right:1px solid rgba(255,255,255,0.06);
    }
</style>

<div class="user-rail-root w-full h-full flex flex-col relative overflow-hidden" style="{{ $styleVars }}">
    <div class="absolute inset-0 rail-dot opacity-70 pointer-events-none"></div>

    {{-- Mobile close (X) --}}
    <button type="button" data-user-rail-close
        class="md:hidden absolute top-3 right-3 z-10 w-8 h-8 grid place-items-center rounded-lg rail-panel rail-fg">
        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4l8 8M12 4l-8 8"/></svg>
    </button>

    {{-- Brand --}}
    <a href="{{ url('/dashboard') }}" class="relative px-5 h-[64px] flex items-center gap-2.5 shrink-0">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ brand_name() }}" class="h-7 w-auto max-w-[150px] object-contain">
        @else
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-wa-green text-ink-950">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.96.57 3.79 1.55 5.34L2 22l4.78-1.5A9.93 9.93 0 0 0 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2Z"/></svg>
            </span>
            <div class="leading-none">
                <div class="text-[19px] font-semibold rail-fg">{{ brand_name() }}</div>
                <div class="text-[8px] font-mono uppercase tracking-[0.2em] rail-fgm mt-1">{{ __('console') }}</div>
            </div>
        @endif
    </a>

    {{-- Workspace switcher --}}
    <div class="relative px-3.5 pt-1 shrink-0" data-ws-wrap>
        <button type="button" data-ws-toggle
            class="w-full rounded-xl px-3 py-2.5 flex items-center gap-2.5 rail-panel">
            <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-accent-coral to-accent-amber text-paper-0 text-[11px] font-bold grid place-items-center">{{ $wsInitials }}</span>
            <div class="text-left flex-1 min-w-0">
                <div class="text-[12.5px] font-semibold rail-fg truncate">{{ $wsName }}</div>
                <div class="text-[9px] font-mono rail-fgm truncate">{{ $planLabel }}</div>
            </div>
            <svg class="w-3.5 h-3.5 rail-fgm" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 4.5l3 3 3-3"/></svg>
        </button>
        @unless ($__lockWs)
        <div data-ws-menu
            class="hidden absolute left-3.5 right-3.5 mt-2 bg-paper-0 border border-paper-200 rounded-2xl shadow-soft p-2 z-[60]">
            <div class="px-2 py-1 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Your workspaces') }}</div>
            <div class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach ($allWorkspaces as $w)
                    @php $isActive = $ws && $w->id === $ws->id; @endphp
                    <form method="POST" action="{{ route('workspaces.switch', $w->id) }}" class="block">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-2 px-2 py-2 rounded-xl text-left {{ $isActive ? 'bg-wa-mint' : 'hover:bg-paper-50' }}">
                            <span class="w-7 h-7 rounded-full text-paper-0 grid place-items-center text-[10.5px] font-semibold" style="background:{{ workspace_brand_color($w) }};">{{ strtoupper(substr($w->name, 0, 2)) }}</span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-[12.5px] font-semibold text-ink-900 truncate">{{ $w->name }}</span>
                                <span class="block text-[10.5px] text-ink-500 font-mono truncate">{{ $w->slug }}</span>
                            </span>
                            @if ($isActive)
                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-wa-deep" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l3 3 7-7"/></svg>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
            @if ($canCreateWorkspace)
                <div class="border-t border-paper-200 mt-2 pt-2">
                    <a href="{{ route('workspaces.create') }}" class="flex items-center gap-2 px-2 py-2 rounded-xl hover:bg-paper-50 text-[12.5px] font-semibold text-wa-deep">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10"/></svg>
                        {{ __('Create new workspace') }}
                    </a>
                </div>
            @endif
        </div>
        @endunless
    </div>

    {{-- Nav (min-h-0 so flex-1 + overflow-y-auto actually scrolls, not expands) --}}
    @php
        // Active highlighting: match the passed nav-key OR the current URL path.
        // Many pages set nav-key="more" (for the topbar's overflow menu), which
        // never matches a sidebar item key — so fall back to the URL so the right
        // item lights up in sidebar mode without touching every page's nav-key.
        $curPath = '/' . trim(request()->path(), '/');
        $isItemActive = function ($item) use ($active, $curPath) {
            if ($active !== null && $active === ($item['key'] ?? null)) {
                return true;
            }
            $itemPath = '/' . trim((string) (parse_url((string) ($item['href'] ?? ''), PHP_URL_PATH) ?: ''), '/');
            if ($itemPath === '/' ) {
                return false;
            }
            return $curPath === $itemPath || str_starts_with($curPath, $itemPath . '/');
        };
    @endphp
    <nav class="relative flex-1 min-h-0 overflow-y-auto rail-scroll px-3.5 pb-4 mt-1">
        @foreach ($groups as $gi => $group)
            @continue(empty($group['items']))
            @php $groupActive = collect($group['items'])->contains(fn ($it) => $isItemActive($it)); @endphp
            <div class="rail-group" data-rail-group data-rail-key="g{{ $gi }}" @if ($groupActive) data-rail-active @endif>
                <button type="button" class="rail-cap rail-cap-btn" data-rail-toggle
                    aria-label="{{ __('Toggle :section', ['section' => $group['label']]) }}">
                    <span>{{ $group['label'] }}</span>
                    <svg class="rail-cap-chev" viewBox="0 0 12 12" fill="none" stroke="currentColor"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 4.5l3 3 3-3" />
                    </svg>
                </button>
                <div class="rail-group-items" data-rail-items>
                    @foreach ($group['items'] as $item)
                        @php $locked = ! empty($item['locked']); @endphp
                        {{-- A locked item points at the plans page, NOT its own
                             route: EnforcePlanFeature would only bounce them
                             back with a warning, so send them where they can
                             actually act on it. --}}
                        <a href="{{ $locked ? url('/account/plans') : $item['href'] }}"
                            @class(['rail-link', 'active' => ! $locked && $isItemActive($item), 'locked' => $locked])
                            @if ($locked) title="{{ __('Not included in your plan — tap to upgrade') }}" @endif>
                            <svg class="rail-ic" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                stroke-width="{{ $item['sw'] ?? 1.5 }}" stroke-linecap="round" stroke-linejoin="round">
                                {!! $item['icon'] !!}
                            </svg>
                            <span class="truncate">{{ $item['label'] }}</span>
                            @if ($locked)
                                <svg class="rail-lock" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3.5" y="7" width="9" height="6.5" rx="1.5" />
                                    <path d="M5.75 7V5.25a2.25 2.25 0 0 1 4.5 0V7" />
                                </svg>
                                <span class="sr-only">{{ __('Upgrade required') }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    {{-- Wallet card --}}
    <div class="relative p-3.5 shrink-0">
        <div class="rounded-2xl rail-panel p-3.5">
            <div class="flex items-center justify-between">
                <div class="text-[9px] font-mono uppercase tracking-widest rail-fgm">{{ __('Wallet') }}</div>
                <span class="text-[9px] font-mono px-2 py-0.5 rounded-full" style="background:var(--racc-tint);color:var(--racc)">{{ $planLabel }}</span>
            </div>
            <div class="text-[22px] font-semibold leading-none mt-1.5 rail-fg">{{ $walletMoney }}</div>
            <a href="{{ url('/account?tab=wallet') }}"
                class="mt-2.5 block text-center w-full text-ink-950 rounded-full text-[11.5px] font-semibold py-1.5 transition hover:opacity-90" style="background:var(--racc)">{{ __('Top up') }} →</a>
        </div>
    </div>
</div>
