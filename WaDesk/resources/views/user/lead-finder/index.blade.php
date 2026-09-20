@php $counts = $counts ?? ['all'=>0,'whatsapp'=>0,'email'=>0,'not_crm'=>0]; @endphp
<x-layouts.user :title="__('Lead Finder')" nav-key="lead-finder">
    {{-- Leaflet (free OpenStreetMap map — no API key). Loaded from CDN. --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7" id="lf-root"
        data-search-url="{{ route('user.lead-finder.search') }}"
        data-bulk-url="{{ route('user.lead-finder.bulk-add') }}"
        data-clear-url="{{ route('user.lead-finder.clear') }}"
        data-chat-base="{{ url('/chat') }}">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4 mb-5">
            <div>
                <div class="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 1.5c-2.8 0-5 2.1-5 4.9 0 3.4 5 8.1 5 8.1s5-4.7 5-8.1c0-2.8-2.2-4.9-5-4.9Z"/><circle cx="8" cy="6.3" r="1.7"/></svg>
                    {{ __('Audience · Prospecting') }}
                </div>
                <h1 class="font-serif text-[26px] leading-tight">{{ __('Lead') }} <span class="italic text-wa-deep">{{ __('finder') }}</span></h1>
                <p class="text-[12.5px] text-ink-500 mt-1 max-w-2xl">{{ __('Pull businesses from the map by category and city, grab their WhatsApp number & email, then add them to Contacts or launch a campaign.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if ($hasGoogle ?? false)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold" style="background:#E8F0FE;color:#1a73e8">
                        <span class="w-1.5 h-1.5 rounded-full" style="background:#1a73e8"></span>{{ __('Google Places') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-wa-mint text-wa-deep">
                        <span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('OpenStreetMap · free') }}
                    </span>
                @endif
                <button id="lf-settings-open" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-paper-200 bg-paper-0 hover:border-wa-deep text-[12px] font-semibold text-ink-700 hover:text-wa-deep transition">
                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 1.5c-2.6 0-4.6 2-4.6 4.5C3.4 9.2 8 14 8 14s4.6-4.8 4.6-8C12.6 3.5 10.6 1.5 8 1.5Z"/><circle cx="8" cy="6" r="1.6"/></svg>
                    {{ ($hasGoogle ?? false) ? __('Google connected') : __('Use Google Maps') }}
                </button>
            </div>
        </div>

        {{-- Data-source settings modal (BYOK Google key) --}}
        <div id="lf-settings" class="hidden fixed inset-0 z-[70] bg-ink-900/40 grid place-items-center p-4">
            <div class="w-full max-w-md bg-paper-0 rounded-2xl shadow-2xl border border-paper-200 p-5">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-[16px] font-semibold">{{ __('Lead data source') }}</h3>
                    <button id="lf-settings-close" class="text-ink-400 hover:text-ink-900 text-[18px] leading-none">&times;</button>
                </div>
                <p class="text-[12px] text-ink-500 mb-4">{{ __('Free by default (OpenStreetMap). Paste your own Google Maps / Places API key to unlock richer data & the Google map. Your key is stored encrypted and used only for your searches.') }}</p>
                <label class="block text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500 mb-1">{{ __('Google Places API key') }}</label>
                <input id="lf-key-input" type="text" autocomplete="off" placeholder="AIza…"
                    class="w-full px-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] font-mono focus:outline-none focus:border-wa-deep">
                <div class="flex items-center justify-between gap-2 mt-4">
                    <button id="lf-key-remove" class="text-[12px] text-accent-coral font-semibold hover:underline {{ ($hasGoogle ?? false) ? '' : 'invisible' }}">{{ __('Remove key & use free source') }}</button>
                    <div class="flex items-center gap-2">
                        <button id="lf-key-cancel" class="px-3 py-2 rounded-xl text-[12.5px] font-semibold text-ink-600 hover:bg-paper-50">{{ __('Cancel') }}</button>
                        <button id="lf-key-save" class="px-4 py-2 rounded-xl bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Save & verify') }}</button>
                    </div>
                </div>
                <p class="text-[10.5px] text-ink-400 mt-3">{{ __('Get a key at console.cloud.google.com → enable “Places API”. Google may charge per its pricing (has a monthly free credit).') }}</p>
            </div>
        </div>

        {{-- Search bar --}}
        <form id="lf-search" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto_auto] gap-2.5 mb-2.5">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-400" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m11 11 3 3"/></svg>
                <input id="lf-category" type="text" autocomplete="off"
                    placeholder="{{ __('Category e.g. Restaurants, Salons… (blank = all businesses)') }}"
                    class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
            </div>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-400" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 1.5c-2.6 0-4.6 2-4.6 4.5C3.4 9.2 8 14 8 14s4.6-4.8 4.6-8C12.6 3.5 10.6 1.5 8 1.5Z"/><circle cx="8" cy="6" r="1.6"/></svg>
                <input id="lf-place" type="text" autocomplete="off"
                    placeholder="{{ __('City / area e.g. Los Angeles, CA') }}"
                    class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-paper-200 bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10">
            </div>
            <button id="lf-go" type="submit"
                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal transition">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 8.5 6 12l8-8"/><path d="M2 4h4"/></svg>
                <span id="lf-go-label">{{ __('Find on map') }}</span>
            </button>
            <button id="lf-scan" type="button"
                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-ink-900 text-paper-0 text-[13px] font-semibold hover:opacity-90 transition">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 3v10M3 8h10" stroke-linecap="round"/><circle cx="8" cy="8" r="6.5"/></svg>
                <span id="lf-scan-label">{{ __('Scan this area') }}</span>
            </button>
        </form>
        {{-- Advanced: range + click-to-scan --}}
        <div class="flex flex-wrap items-center gap-3 mb-5 text-[12px] text-ink-600">
            <label class="inline-flex items-center gap-1.5">
                <span class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('Range') }}</span>
                <select id="lf-radius" class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1 text-[12px] focus:outline-none focus:border-wa-deep">
                    <option value="1000">1 km</option>
                    <option value="2000">2 km</option>
                    <option value="3000" selected>3 km</option>
                    <option value="5000">5 km</option>
                    <option value="10000">10 km</option>
                    <option value="25000">25 km</option>
                </select>
            </label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                <input id="lf-clickscan" type="checkbox" checked class="rounded border-paper-300 text-wa-deep focus:ring-wa-deep">
                <span>{{ __('Click any spot on the map to auto-scan around it') }}</span>
            </label>
            <span class="text-ink-400">{{ __('Zoom to a place → click it, or use “Scan this area”. Leads load automatically.') }}</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_460px] gap-5 items-start">
            {{-- MAP --}}
            <div class="rounded-2xl border border-paper-200 bg-paper-0 shadow-card overflow-hidden">
                <div class="px-4 py-2.5 border-b border-paper-200 flex items-center gap-2 text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500">
                    <span class="w-2 h-2 rounded-full bg-wa-green animate-pulse"></span>{{ __('Live map') }} · {{ __('OpenStreetMap') }}
                </div>
                <div id="lf-map" class="w-full" style="height: 560px; z-index:0;"></div>
            </div>

            {{-- RESULTS --}}
            <div class="rounded-2xl border border-paper-200 bg-paper-0 shadow-card flex flex-col" style="max-height: 620px;">
                <div class="p-3 border-b border-paper-200">
                    <div class="flex items-center justify-between gap-2 mb-2.5">
                        <div class="inline-flex items-center gap-1 bg-paper-50 rounded-full p-1 text-[11.5px] font-semibold overflow-x-auto">
                            <button data-tab="all"      class="lf-tab px-2.5 py-1 rounded-full bg-wa-deep text-paper-0">{{ __('Everything') }} <span data-c="all">{{ $counts['all'] }}</span></button>
                            <button data-tab="whatsapp" class="lf-tab px-2.5 py-1 rounded-full text-ink-600 hover:bg-paper-100">{{ __('With number') }} <span data-c="whatsapp">{{ $counts['whatsapp'] }}</span></button>
                            <button data-tab="email"    class="lf-tab px-2.5 py-1 rounded-full text-ink-600 hover:bg-paper-100">{{ __('With email') }} <span data-c="email">{{ $counts['email'] }}</span></button>
                            <button data-tab="not_crm"  class="lf-tab px-2.5 py-1 rounded-full text-ink-600 hover:bg-paper-100">{{ __('Uncontacted') }} <span data-c="not_crm">{{ $counts['not_crm'] }}</span></button>
                        </div>
                        <button id="lf-clear" class="text-[11px] text-accent-coral font-semibold hover:underline shrink-0">{{ __('Clear all') }}</button>
                    </div>
                    <div class="relative">
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink-400" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m11 11 3 3"/></svg>
                        <input id="lf-filter" type="text" placeholder="{{ __('Filter by name, email…') }}"
                            class="w-full pl-8 pr-3 py-2 rounded-lg border border-paper-200 bg-paper-50 text-[12px] focus:outline-none focus:bg-paper-0 focus:border-wa-deep">
                    </div>
                </div>

                {{-- List --}}
                <div id="lf-list" class="flex-1 overflow-y-auto divide-y divide-paper-100"></div>

                {{-- Empty / loading state --}}
                <div id="lf-empty" class="flex-1 grid place-items-center px-6 py-10 text-center">
                    <div>
                        <div class="w-12 h-12 mx-auto rounded-2xl bg-wa-bubble grid place-items-center text-wa-deep mb-3">
                            <svg viewBox="0 0 16 16" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M8 1.5c-2.8 0-5 2.1-5 4.9 0 3.4 5 8.1 5 8.1s5-4.7 5-8.1c0-2.8-2.2-4.9-5-4.9Z"/><circle cx="8" cy="6.3" r="1.7"/></svg>
                        </div>
                        <div class="text-[13px] font-semibold text-ink-800">{{ __('Search to find leads') }}</div>
                        <p class="text-[12px] text-ink-500 mt-1">{{ __('Type a business category and a city, then hit Find on map.') }}</p>
                    </div>
                </div>

                <div class="px-3 py-2 border-t border-paper-200 text-[10.5px] text-ink-400">
                    {{ __('Leads are saved to your workspace. Use responsibly, follow each platform’s terms, and respect consent before messaging.') }}
                </div>
            </div>
        </div>

        {{-- Bulk action bar (hidden until selection) --}}
        <div id="lf-bulk" class="hidden fixed bottom-5 left-1/2 -translate-x-1/2 z-[60] bg-ink-900 text-paper-0 rounded-2xl shadow-2xl px-4 py-3 flex items-center gap-3">
            <span class="text-[12.5px] font-semibold"><span id="lf-bulk-n">0</span> {{ __('selected') }}</span>
            <button id="lf-bulk-add" class="px-3 py-1.5 rounded-full bg-paper-0 text-ink-900 text-[12px] font-semibold hover:bg-paper-100 transition">{{ __('Add to Contacts') }}</button>
            <button id="lf-bulk-campaign" class="px-3 py-1.5 rounded-full bg-wa-green text-ink-950 text-[12px] font-semibold hover:opacity-90 transition">{{ __('Add + start campaign') }}</button>
            <button id="lf-bulk-clear" class="text-paper-0/60 hover:text-paper-0 text-[16px] leading-none">&times;</button>
        </div>
    </main>

    {{-- Bootstrap saved leads for first paint --}}
    @php
        $lfSeed = $leads->map(fn ($l) => [
            'id' => $l->id, 'name' => $l->name, 'category' => $l->category, 'phone' => $l->phone,
            'phone_e164' => $l->phone_e164, 'email' => $l->email, 'website' => $l->website,
            'address' => $l->address, 'lat' => $l->lat, 'lng' => $l->lng, 'in_crm' => (bool) $l->in_crm,
        ])->values();
    @endphp
    <script id="lf-seed" type="application/json">{!! json_encode($lfSeed) !!}</script>

    <script>
        window.LF = {
            gmapsKey: @json($gmapsKey ?? ''),
            keySave: @json(route('user.lead-finder.key.save')),
            keyRemove: @json(route('user.lead-finder.key.remove')),
        };
    </script>

    <script>
    (function () {
        const root = document.getElementById('lf-root');
        if (!root || typeof L === 'undefined') return;
        const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
        const t = (s) => s;
        let leads = [];
        try { leads = JSON.parse(document.getElementById('lf-seed').textContent) || []; } catch (e) {}
        let tab = 'all', filter = '', selected = new Set();
        let busy = false, lastClick = 0;   // in-flight lock + click debounce (prevents chaining/loop crash)

        // ── Map adapter: Google Maps when a key is present, else Leaflet/OSM ──
        const GKEY = (window.LF && window.LF.gmapsKey) || '';
        const MAP = makeMapAdapter(GKEY);

        function makeMapAdapter(gkey) {
            if (gkey) return googleAdapter(gkey);
            return leafletAdapter();
        }

        function leafletAdapter() {
            const map = L.map('lf-map', { zoomControl: true }).setView([20, 0], 2);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
            const layer = L.layerGroup().addTo(map);
            let circle = null;
            return {
                ready: (cb) => cb(),
                setView: (lat, lng, z) => map.setView([lat, lng], z || 13),
                clear: () => layer.clearLayers(),
                marker: (lat, lng, html) => L.marker([lat, lng]).addTo(layer).bindPopup(html),
                fit: (pts) => { try { map.fitBounds(L.latLngBounds(pts).pad(0.15)); } catch (e) {} },
                bounds: () => { const b = map.getBounds(); return [b.getSouth(), b.getWest(), b.getNorth(), b.getEast()]; },
                onClick: (cb) => map.on('click', e => cb(e.latlng.lat, e.latlng.lng)),
                scanCircle: (lat, lng, r) => { if (circle) map.removeLayer(circle); circle = L.circle([lat, lng], { radius: r, color: '#128C7E', weight: 1, fillOpacity: 0.06 }).addTo(map); },
                resize: () => map.invalidateSize(),
            };
        }

        function googleAdapter(gkey) {
            let gmap, markers = [], circle = null, readyCbs = [], isReady = false;
            window.__lfGmapInit = function () {
                gmap = new google.maps.Map(document.getElementById('lf-map'), { center: { lat: 20, lng: 0 }, zoom: 2, mapTypeControl: false, streetViewControl: false });
                isReady = true; readyCbs.forEach(cb => cb());
            };
            const s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(gkey) + '&callback=__lfGmapInit';
            s.async = true; s.defer = true; document.head.appendChild(s);
            return {
                ready: (cb) => { isReady ? cb() : readyCbs.push(cb); },
                setView: (lat, lng, z) => gmap && gmap.setCenter({ lat, lng }) || (gmap && gmap.setZoom(z || 13)),
                clear: () => { markers.forEach(m => m.setMap(null)); markers = []; },
                marker: (lat, lng, html) => {
                    if (!gmap) return; const m = new google.maps.Marker({ position: { lat, lng }, map: gmap });
                    const iw = new google.maps.InfoWindow({ content: html }); m.addListener('click', () => iw.open(gmap, m)); markers.push(m);
                },
                fit: (pts) => { if (!gmap || !pts.length) return; const b = new google.maps.LatLngBounds(); pts.forEach(p => b.extend({ lat: p[0], lng: p[1] })); gmap.fitBounds(b); },
                bounds: () => { const b = gmap.getBounds(); const ne = b.getNorthEast(), sw = b.getSouthWest(); return [sw.lat(), sw.lng(), ne.lat(), ne.lng()]; },
                onClick: (cb) => gmap.addListener('click', e => cb(e.latLng.lat(), e.latLng.lng())),
                scanCircle: (lat, lng, r) => { if (circle) circle.setMap(null); circle = new google.maps.Circle({ center: { lat, lng }, radius: r, map: gmap, strokeColor: '#128C7E', strokeWeight: 1, fillColor: '#128C7E', fillOpacity: 0.06 }); },
                resize: () => {},
            };
        }

        const esc = (s) => (s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])));

        function counts() {
            return {
                all: leads.length,
                whatsapp: leads.filter(l => l.phone_e164).length,
                email: leads.filter(l => l.email).length,
                not_crm: leads.filter(l => !l.in_crm).length,
            };
        }
        function refreshCounts() {
            const c = counts();
            document.querySelectorAll('[data-c]').forEach(el => el.textContent = c[el.dataset.c] ?? 0);
        }
        function visible() {
            return leads.filter(l => {
                if (tab === 'whatsapp' && !l.phone_e164) return false;
                if (tab === 'email' && !l.email) return false;
                if (tab === 'not_crm' && l.in_crm) return false;
                if (filter) {
                    const hay = (l.name + ' ' + (l.email || '') + ' ' + (l.category || '') + ' ' + (l.address || '')).toLowerCase();
                    if (!hay.includes(filter)) return false;
                }
                return true;
            });
        }

        function render() {
            const list = document.getElementById('lf-list');
            const empty = document.getElementById('lf-empty');
            const rows = visible();
            refreshCounts();
            if (!leads.length) { list.innerHTML = ''; empty.style.display = 'grid'; return; }
            empty.style.display = 'none';
            list.innerHTML = rows.map(l => {
                const wa = l.phone_e164 ? `https://wa.me/${l.phone_e164}` : null;
                const checked = selected.has(l.id) ? 'checked' : '';
                return `<div class="p-3 hover:bg-paper-50/60">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" data-pick="${l.id}" ${checked} ${l.phone_e164 ? '' : 'disabled'}
                            class="mt-1 rounded border-paper-300 text-wa-deep focus:ring-wa-deep disabled:opacity-30">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-[13px] font-semibold text-ink-900 truncate">${esc(l.name)}</span>
                                ${l.in_crm ? '<span class="text-[9px] font-mono px-1.5 py-0.5 rounded-full bg-wa-mint text-wa-deep shrink-0">'+t('IN CRM')+'</span>' : ''}
                            </div>
                            <div class="text-[11px] text-ink-500 truncate">${esc(l.category || '')}${l.address ? ' · ' + esc(l.address) : ''}</div>
                            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                ${l.phone_e164 ? `<span class="inline-flex items-center gap-1 text-[10.5px] font-mono px-2 py-0.5 rounded-full bg-wa-bubble text-wa-deep">${esc(l.phone)}</span>` : ''}
                                ${l.email ? `<span class="inline-flex items-center gap-1 text-[10.5px] font-mono px-2 py-0.5 rounded-full bg-paper-100 text-ink-600">${esc(l.email)}</span>` : ''}
                                ${l.website ? `<a href="${esc(l.website)}" target="_blank" rel="noopener" class="text-[10.5px] px-2 py-0.5 rounded-full border border-paper-200 text-ink-500 hover:border-wa-deep">${t('Website')}</a>` : ''}
                            </div>
                            <div class="flex items-center gap-2 mt-2.5">
                                ${l.in_crm
                                    ? `<span class="text-[11px] text-ink-400">${t('Added')}</span>`
                                    : `<button data-add="${l.id}" class="inline-flex items-center gap-1 text-[11.5px] font-semibold text-wa-deep hover:underline">+ ${t('Add to Contacts')}</button>`}
                                ${(!l.phone_e164 && l.website) ? `<button data-enrich="${l.id}" class="inline-flex items-center gap-1 text-[11.5px] font-semibold text-accent-amber hover:underline">${t('Find number')}</button>` : ''}
                                ${wa ? `<a href="${wa}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11.5px] font-semibold text-ink-700 hover:text-wa-deep">${t('Message')} →</a>` : ''}
                            </div>
                        </div>
                    </div>
                </div>`;
            }).join('') || `<div class="p-6 text-center text-[12px] text-ink-400">${t('No leads match this filter.')}</div>`;
        }

        function drawMarkers(fit) {
            MAP.clear();
            const pts = leads.filter(l => l.lat && l.lng);
            pts.forEach(l => MAP.marker(l.lat, l.lng, `<b>${esc(l.name)}</b><br>${esc(l.phone || l.email || '')}`));
            if (pts.length && fit) MAP.fit(pts.map(l => [l.lat, l.lng]));
        }

        function updateBulkBar() {
            const bar = document.getElementById('lf-bulk');
            document.getElementById('lf-bulk-n').textContent = selected.size;
            bar.classList.toggle('hidden', selected.size === 0);
        }

        // Events
        document.querySelectorAll('.lf-tab').forEach(b => b.addEventListener('click', () => {
            tab = b.dataset.tab;
            document.querySelectorAll('.lf-tab').forEach(x => {
                x.classList.toggle('bg-wa-deep', x === b);
                x.classList.toggle('text-paper-0', x === b);
                x.classList.toggle('text-ink-600', x !== b);
            });
            render();
        }));
        document.getElementById('lf-filter').addEventListener('input', e => { filter = e.target.value.trim().toLowerCase(); render(); });

        document.getElementById('lf-list').addEventListener('click', async (e) => {
            const add = e.target.closest('[data-add]');
            if (add) { e.preventDefault(); await addOne(parseInt(add.dataset.add, 10), add); return; }
            const enr = e.target.closest('[data-enrich]');
            if (enr) { e.preventDefault(); await enrichOne(parseInt(enr.dataset.enrich, 10), enr); return; }
        });

        async function enrichOne(id, btn) {
            if (busy) { window.waToast && window.waToast(t('One moment…'), 'warn'); return; }
            busy = true;
            if (btn) { btn.disabled = true; btn.textContent = t('Searching…'); }
            try {
                const r = await post(`{{ url('/lead-finder') }}/${id}/enrich`, {});
                if (r.ok && r.lead) {
                    const i = leads.findIndex(x => x.id === id);
                    if (i >= 0) leads[i] = r.lead;
                    render();
                    window.waToast && window.waToast(r.lead.phone_e164 ? t('Number found') : t('Found email only'), r.lead.phone_e164 ? 'success' : 'warn');
                } else {
                    window.waToast && window.waToast(t('No number on their site'), 'warn');
                    if (btn) { btn.disabled = false; btn.textContent = t('Find number'); }
                }
            } catch (e2) {
                if (btn) { btn.disabled = false; btn.textContent = t('Find number'); }
            } finally {
                busy = false;
            }
        }
        document.getElementById('lf-list').addEventListener('change', (e) => {
            const pick = e.target.closest('[data-pick]');
            if (pick) {
                const id = parseInt(pick.dataset.pick, 10);
                pick.checked ? selected.add(id) : selected.delete(id);
                updateBulkBar();
            }
        });

        async function post(url, body) {
            const res = await fetch(url, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify(body || {}),
            });
            return res.json();
        }

        async function addOne(id, btn) {
            if (btn) { btn.disabled = true; btn.textContent = t('Adding…'); }
            const r = await post(`{{ url('/lead-finder') }}/${id}/contact`, {});
            if (r.ok) {
                const l = leads.find(x => x.id === id); if (l) l.in_crm = true;
                render();
                window.waToast && window.waToast(t('Added to Contacts'), 'success');
            } else {
                window.waToast && window.waToast(t('Could not add — no phone or email'), 'error');
                if (btn) { btn.disabled = false; btn.textContent = '+ ' + t('Add to Contacts'); }
            }
        }

        // Generic runner used by all three modes. Hard single-flight lock so a
        // burst of map clicks can never stack requests and crash the server.
        async function runSearch(payload, btn, busyLabel, idleLabel, fit) {
            if (busy) { window.waToast && window.waToast(t('Still scanning — one moment'), 'warn'); return; }
            busy = true;
            if (btn) { btn.disabled = true; if (busyLabel) busyLabel.el.textContent = t('Scanning…'); }
            try {
                const r = await post(root.dataset.searchUrl, payload);
                if (!r.ok) {
                    const msg = {
                        location_not_found: t('Location not found'),
                        need_category_and_place: t('Enter a category and a city'),
                        source_unavailable: t('Map source is busy — try again in a moment'),
                    }[r.error] || t('Search source is busy, try again');
                    window.waToast && window.waToast(msg, 'error');
                } else {
                    const byId = new Map(leads.map(l => [l.id, l]));
                    r.leads.forEach(l => byId.set(l.id, l));
                    leads = Array.from(byId.values());
                    render(); drawMarkers(fit);
                    if (r.center && fit) MAP.setView(r.center.lat, r.center.lng, 13);
                    window.waToast && window.waToast(r.leads.length + ' ' + t('leads found') + (r.leads.length === 0 ? ' — ' + t('try a bigger range or another area') : ''), r.leads.length ? 'success' : 'warn');
                }
            } catch (err) {
                window.waToast && window.waToast(t('Search failed'), 'error');
            } finally {
                busy = false;
                if (btn) { btn.disabled = false; if (busyLabel && idleLabel) busyLabel.el.textContent = idleLabel; }
            }
        }
        const radiusVal = () => parseInt(document.getElementById('lf-radius').value, 10) || 3000;

        // Find on map (place name)
        document.getElementById('lf-search').addEventListener('submit', (e) => {
            e.preventDefault();
            const category = document.getElementById('lf-category').value.trim();
            const place = document.getElementById('lf-place').value.trim();
            if (!place) { window.waToast && window.waToast(t('Enter a city / area'), 'error'); return; }
            runSearch({ mode: 'place', category, place }, document.getElementById('lf-go'),
                { el: document.getElementById('lf-go-label') }, t('Find on map'), true);
        });

        // Scan this area (current map bounds)
        document.getElementById('lf-scan').addEventListener('click', () => {
            const category = document.getElementById('lf-category').value.trim();
            runSearch({ mode: 'bbox', category, bbox: MAP.bounds() },
                document.getElementById('lf-scan'), { el: document.getElementById('lf-scan-label') }, t('Scan this area'), false);
        });

        // Click the map to scan around a point (radius). Registered once the map is ready.
        function wireClickScan() {
            MAP.onClick((lat, lng) => {
                if (!document.getElementById('lf-clickscan').checked) return;
                if (busy) return;                              // ignore clicks while a scan runs
                const now = Date.now ? Date.now() : +new Date();
                if (now - lastClick < 1200) return;            // debounce rapid clicks
                lastClick = now;
                const category = document.getElementById('lf-category').value.trim();
                MAP.scanCircle(lat, lng, radiusVal());
                runSearch({ mode: 'around', category, lat, lng, radius: radiusVal() }, null, null, null, false);
            });
        }

        // Bulk
        document.getElementById('lf-bulk-clear').addEventListener('click', () => {
            selected.clear(); document.querySelectorAll('[data-pick]').forEach(c => c.checked = false); updateBulkBar();
        });
        async function bulkAdd(startCampaign) {
            if (!selected.size) return;
            const r = await post(root.dataset.bulkUrl, { lead_ids: Array.from(selected) });
            if (r.ok) {
                selected.forEach(id => { const l = leads.find(x => x.id === id); if (l) l.in_crm = true; });
                selected.clear(); updateBulkBar(); render();
                window.waToast && window.waToast(r.added + ' ' + t('added to') + ' “' + r.group + '”', 'success');
                if (startCampaign && r.campaign_url) window.location.href = r.campaign_url;
            } else {
                window.waToast && window.waToast(t('Bulk add failed'), 'error');
            }
        }
        document.getElementById('lf-bulk-add').addEventListener('click', () => bulkAdd(false));
        document.getElementById('lf-bulk-campaign').addEventListener('click', () => bulkAdd(true));

        // Clear all
        document.getElementById('lf-clear').addEventListener('click', async () => {
            if (!leads.length) return;
            if (!confirm(t('Remove all saved leads?'))) return;
            const r = await post(root.dataset.clearUrl, {});
            if (r.ok) { leads = []; selected.clear(); updateBulkBar(); render(); drawMarkers(); }
        });

        // Settings modal — BYOK Google Places key.
        const settings = document.getElementById('lf-settings');
        const openS = () => settings.classList.remove('hidden');
        const closeS = () => settings.classList.add('hidden');
        document.getElementById('lf-settings-open').addEventListener('click', openS);
        document.getElementById('lf-settings-close').addEventListener('click', closeS);
        document.getElementById('lf-key-cancel').addEventListener('click', closeS);
        settings.addEventListener('click', (e) => { if (e.target === settings) closeS(); });
        document.getElementById('lf-key-save').addEventListener('click', async () => {
            const key = document.getElementById('lf-key-input').value.trim();
            if (!key) return;
            const btn = document.getElementById('lf-key-save'); btn.disabled = true; btn.textContent = t('Verifying…');
            const r = await post(window.LF.keySave, { key });
            if (r.ok) { window.waToast && window.waToast(t('Google key saved — reloading'), 'success'); setTimeout(() => location.reload(), 600); }
            else { window.waToast && window.waToast(r.error === 'invalid_key' ? t('That key was rejected by Google') : t('Could not save key'), 'error'); btn.disabled = false; btn.textContent = t('Save & verify'); }
        });
        const rmBtn = document.getElementById('lf-key-remove');
        if (rmBtn) rmBtn.addEventListener('click', async () => {
            const res = await fetch(window.LF.keyRemove, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
            if ((await res.json()).ok) { window.waToast && window.waToast(t('Switched to free source — reloading'), 'success'); setTimeout(() => location.reload(), 600); }
        });

        // First paint
        render();
        MAP.ready(() => {
            MAP.resize();
            drawMarkers(true);
            wireClickScan();
        });
    })();
    </script>
</x-layouts.user>
