@props(['title' => 'Dashboard'])

{{-- Slim 64px top strip for the sidebar layout — breadcrumb + jump-search +
     notifications + user menu. NO nav menu here (all nav lives in the sidebar).
     Height is EXACTLY 64px (h-16) so full-height pages using calc(100vh-64px)
     stay exact. --}}
@php
    // NEVER assume there is a user. This component renders inside the user
    // layout, which also wraps error pages — so a request whose session has
    // just expired (or an exception rendered after logout) reaches here with
    // auth() empty. Reading ->name on null threw a ViewException that replaced
    // the real page with a 500, hiding whatever the actual problem was. The
    // sibling components already use `?->`; this one did not.
    $u     = auth()->user();
    $uName = trim((string) ($u?->name ?? ''));
    $uIsAdmin = (bool) ($u?->isAdmin() ?? false);

    // Build the "jump to" index from the sidebar nav so search covers exactly
    // the pages this user can reach — plus a few always-there destinations.
    $jump = [];
    foreach (\App\Support\UserNav::groups() as $g) {
        foreach ($g['items'] as $it) {
            $jump[] = ['label' => $it['label'], 'href' => $it['href'], 'group' => $g['label']];
        }
    }
    foreach ([
        ['Account', url('/account')], ['Settings', url('/settings')],
        ['Wallet', url('/account?tab=wallet')], ['Notifications', url('/notifications')],
    ] as [$l, $h]) {
        $jump[] = ['label' => __($l), 'href' => $h, 'group' => __('Account')];
    }
@endphp

<header class="h-16 bg-paper-0 border-b border-paper-200 shrink-0 flex items-center gap-3 px-4 md:px-6 sticky top-0 z-30">
    {{-- Mobile: open sidebar drawer --}}
    <button type="button" data-user-rail-toggle
        class="md:hidden w-9 h-9 -ml-1 grid place-items-center rounded-lg text-ink-700 hover:bg-paper-50 shrink-0">
        <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>

    <div class="flex items-center gap-2.5 text-[11.5px] font-mono uppercase tracking-widest text-ink-500 min-w-0">
        <span class="hidden sm:inline">{{ brand_name() }}</span>
        <svg class="hidden sm:block w-3 h-3 shrink-0" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.5 3l3 3-3 3"/></svg>
        <span class="text-ink-900 truncate">{{ $title }}</span>
    </div>

    {{-- Global "jump to" search — filters the nav and Enter opens the top match. --}}
    <div class="hidden md:block relative flex-1 max-w-[520px] ml-4" id="ts-search-wrap">
        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-500 pointer-events-none" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3"/></svg>
        <input id="ts-search" type="search" autocomplete="off" placeholder="{{ __('Search or jump to…') }}"
            class="w-full rounded-full bg-paper-50 border border-paper-200 pl-10 pr-12 py-2 text-[12.5px] text-ink-800 placeholder:text-ink-500 focus:outline-none focus:border-wa-deep focus:bg-paper-0 transition" />
        <kbd class="hidden lg:block absolute right-3 top-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded-md bg-paper-0 border border-paper-200 text-[10px] font-mono text-ink-500">⌘K</kbd>
        {{-- Results dropdown --}}
        <div id="ts-jump" class="hidden absolute left-0 right-0 mt-2 bg-paper-0 border border-paper-200 rounded-2xl shadow-soft overflow-hidden z-50 max-h-[360px] overflow-y-auto"></div>
    </div>

    {{-- Centered greeting + live clock — fills the gap elegantly without clutter.
         Greeting text + clock are rendered client-side so they stay live and
         match the viewer's own local time. --}}
    <div class="flex-1 hidden lg:flex items-center justify-center min-w-0 px-4" id="ts-greet-wrap" data-name="{{ \Illuminate\Support\Str::of($uName)->explode(' ')->first() }}">
        <div class="text-center leading-tight select-none">
            <div id="ts-greet" class="text-[12.5px] font-semibold text-ink-800"></div>
            <div id="ts-clock" class="text-[10.5px] font-mono uppercase tracking-[0.14em] text-ink-500 mt-0.5"></div>
        </div>
    </div>

    {{-- Spacer for < lg (where the greeting is hidden) so controls stay flush right. --}}
    <div class="flex-1 lg:hidden"></div>

    <x-locale-switcher />

    {{-- Theme toggle --}}
    <button id="wa-theme-btn" type="button" title="{{ __('Theme') }}"
        class="w-9 h-9 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-700 shrink-0">
        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="3.2"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.3 3.3l1.4 1.4M11.3 11.3l1.4 1.4M3.3 12.7l1.4-1.4M11.3 4.7l1.4-1.4"/></svg>
    </button>

    {{-- Notification bell + dropdown (feed from /notifications/recent) --}}
    <div class="relative shrink-0" data-notif-wrap>
        <button id="notif-toggle" type="button" title="{{ __('Notifications') }}"
            class="relative w-9 h-9 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-700">
            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6a5 5 0 0 1 10 0v3l1.5 2.5h-13L3 9V6Z"/><path d="M6.5 13a1.5 1.5 0 0 0 3 0"/></svg>
            <span id="notif-badge" class="hidden absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-accent-coral text-paper-0 text-[9.5px] font-bold leading-[16px] text-center">0</span>
        </button>
        <div id="notif-pane"
            class="hidden fixed left-3 right-3 top-16 w-auto md:absolute md:inset-auto md:right-0 md:left-auto md:top-auto md:mt-2 md:w-[360px] bg-paper-0 border border-paper-200 rounded-2xl shadow-soft overflow-hidden z-50">
            <div class="px-4 py-3 border-b border-paper-200 flex items-center justify-between gap-3">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Inbox') }}</div>
                    <div class="font-serif text-[16px] text-ink-900">{{ __('Notifications') }}</div>
                </div>
                <button id="notif-read-all" type="button" class="text-[11px] font-semibold text-wa-deep hover:underline">{{ __('Mark all read') }}</button>
            </div>
            <div id="notif-list" class="max-h-[420px] overflow-y-auto divide-y divide-paper-100">
                <div class="px-4 py-10 text-center text-[12px] text-ink-500">{{ __('Loading…') }}</div>
            </div>
            <div class="px-4 py-2.5 border-t border-paper-200 flex items-center justify-between bg-paper-50/60">
                <button id="notif-clear" type="button" class="text-[11.5px] font-semibold text-accent-coral hover:underline">{{ __('Clear all') }}</button>
                <a href="{{ url('/notifications') }}" class="text-[11.5px] font-semibold text-wa-deep hover:underline">{{ __('View all →') }}</a>
            </div>
        </div>
    </div>

    {{-- User menu --}}
    <div class="relative shrink-0">
        <button type="button" data-user-toggle
            class="flex items-center gap-2 hover:bg-paper-50 rounded-full pl-1 pr-2.5 py-1">
            <span class="w-8 h-8 rounded-full bg-gradient-to-br from-wa-teal to-wa-deep text-paper-0 text-[11px] font-semibold grid place-items-center">{{ \Illuminate\Support\Str::of($uName)->limit(2, '')->upper() }}</span>
            <span class="hidden sm:block text-left leading-tight">
                <span class="block text-[12.5px] font-semibold text-ink-900">{{ \Illuminate\Support\Str::limit($uName, 16) }}</span>
                <span class="block text-[10.5px] text-ink-500">{{ $uIsAdmin ? __('Admin') : __('Member') }}</span>
            </span>
            <svg class="w-3 h-3 text-ink-500" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5l3 3 3-3"/></svg>
        </button>
        <div data-user-pane
            class="hidden absolute right-0 mt-2 w-56 bg-paper-0 border border-paper-200 rounded-2xl shadow-soft p-2 z-50">
            <a href="{{ url('/account') }}" class="block px-3 py-2 rounded-lg text-[13px] text-ink-800 hover:bg-paper-50">{{ __('Account') }}</a>
            <a href="{{ url('/settings') }}" class="block px-3 py-2 rounded-lg text-[13px] text-ink-800 hover:bg-paper-50">{{ __('Settings') }}</a>
            <a href="{{ url('/account?tab=wallet') }}" class="block px-3 py-2 rounded-lg text-[13px] text-ink-800 hover:bg-paper-50">{{ __('Wallet') }}</a>
            @if ($uIsAdmin)
                <a href="{{ url('/admin') }}" class="block px-3 py-2 rounded-lg text-[13px] text-ink-800 hover:bg-paper-50">{{ __('Admin dashboard') }}</a>
            @endif
            <div class="my-1 border-t border-paper-100"></div>
            <button type="button" onclick="document.getElementById('logoutForm').submit()"
                class="w-full text-left px-3 py-2 rounded-lg text-[13px] text-accent-coral hover:bg-paper-50">{{ __('Log out') }}</button>
        </div>
    </div>

    <script id="ts-jump-data" type="application/json">{!! json_encode(array_values($jump)) !!}</script>
    <script>
    (function () {
        if (window.__tsStripWired) return;
        window.__tsStripWired = true;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

        /* ── Jump-to search ── */
        let pages = [];
        try { pages = JSON.parse(document.getElementById('ts-jump-data').textContent) || []; } catch (e) {}
        const input = document.getElementById('ts-search');
        const jump = document.getElementById('ts-jump');
        let matches = [], active = 0;
        function match(q) {
            q = q.trim().toLowerCase();
            if (!q) return [];
            return pages.filter(p => (p.label + ' ' + (p.group || '')).toLowerCase().includes(q)).slice(0, 8);
        }
        function renderJump() {
            if (!matches.length) { jump.classList.add('hidden'); jump.innerHTML = ''; return; }
            jump.innerHTML = matches.map((p, i) => `
                <a href="${esc(p.href)}" data-i="${i}" class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-[13px] ${i === active ? 'bg-paper-50' : ''} hover:bg-paper-50">
                    <span class="font-medium text-ink-800">${esc(p.label)}</span>
                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-400">${esc(p.group || '')}</span>
                </a>`).join('');
            jump.classList.remove('hidden');
        }
        input?.addEventListener('input', () => { matches = match(input.value); active = 0; renderJump(); });
        input?.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, matches.length - 1); renderJump(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); renderJump(); }
            else if (e.key === 'Enter') { e.preventDefault(); if (matches[active]) window.location.href = matches[active].href; }
            else if (e.key === 'Escape') { jump.classList.add('hidden'); input.blur(); }
        });
        // ⌘K / Ctrl-K focuses the search.
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); input?.focus(); }
        });
        document.addEventListener('click', (e) => { if (!document.getElementById('ts-search-wrap')?.contains(e.target)) jump.classList.add('hidden'); });

        /* ── Time-aware greeting + live clock ── */
        const gw = document.getElementById('ts-greet-wrap');
        if (gw) {
            const name = (gw.dataset.name || '').trim();
            const gEl = document.getElementById('ts-greet');
            const cEl = document.getElementById('ts-clock');
            function greetFor(h) {
                if (h < 5) return '{{ __('Burning the midnight oil') }}';
                if (h < 12) return '{{ __('Good morning') }}';
                if (h < 17) return '{{ __('Good afternoon') }}';
                if (h < 21) return '{{ __('Good evening') }}';
                return '{{ __('Good night') }}';
            }
            function tick() {
                const d = new Date();
                gEl.textContent = name ? (greetFor(d.getHours()) + ', ' + name) : greetFor(d.getHours());
                const date = d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
                const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                cEl.textContent = date + ' · ' + time;
            }
            tick();
            setInterval(tick, 20000);
        }

        /* ── Notification dropdown ── */
        const nBtn = document.getElementById('notif-toggle');
        const nPane = document.getElementById('notif-pane');
        const nList = document.getElementById('notif-list');
        const nBadge = document.getElementById('notif-badge');
        function renderItems(items) {
            if (!items?.length) { nList.innerHTML = '<div class="px-4 py-10 text-center text-[12px] text-ink-500">{{ __('No notifications yet.') }}</div>'; return; }
            nList.innerHTML = items.map(n => {
                const tone = (n.severity === 'error' || n.severity === 'critical') ? 'text-accent-coral' : (n.severity === 'warning' ? 'text-[#7B5A14]' : 'text-ink-900');
                const dot = n.unread ? '<span class="w-1.5 h-1.5 rounded-full bg-wa-deep flex-shrink-0 mt-1.5"></span>' : '<span class="w-1.5 h-1.5 flex-shrink-0 mt-1.5"></span>';
                const href = n.action_url || '{{ url('/notifications') }}';
                return `<a href="${esc(href)}" class="block px-4 py-3 hover:bg-paper-50 transition flex items-start gap-2.5">${dot}
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] ${tone} font-semibold truncate">${esc(n.title || '(no title)')}</div>
                        ${n.message ? `<div class="text-[11.5px] text-ink-500 mt-0.5 line-clamp-2">${esc(n.message)}</div>` : ''}
                        <div class="text-[10.5px] text-ink-500 font-mono mt-1">${esc(n.time_ago)}</div>
                    </div></a>`;
            }).join('');
        }
        function renderBadge(u) { if (!nBadge) return; if (u > 0) { nBadge.textContent = u > 99 ? '99+' : String(u); nBadge.classList.remove('hidden'); } else nBadge.classList.add('hidden'); }
        async function loadNotifs() {
            try {
                const res = await fetch('{{ route('user.notifications.recent') }}', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return;
                const d = await res.json();
                renderItems(d.items || []); renderBadge(Number(d.unread || 0));
            } catch (e) {}
        }
        nBtn?.addEventListener('click', (e) => { e.stopPropagation(); const open = nPane?.classList.contains('hidden'); nPane?.classList.toggle('hidden'); if (open) loadNotifs(); });
        document.getElementById('notif-read-all')?.addEventListener('click', async (e) => { e.stopPropagation(); await fetch('{{ route('user.notifications.read-all') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }); loadNotifs(); });
        document.getElementById('notif-clear')?.addEventListener('click', async (e) => { e.stopPropagation(); await fetch('{{ route('user.notifications.destroy-all') }}', { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }); loadNotifs(); });
        document.addEventListener('click', (e) => { if (nPane && !nPane.classList.contains('hidden') && !document.querySelector('[data-notif-wrap]')?.contains(e.target)) nPane.classList.add('hidden'); });

        // Prime the unread badge on load + refresh every 60s.
        loadNotifs();
        setInterval(loadNotifs, 60000);
    })();
    </script>
</header>
