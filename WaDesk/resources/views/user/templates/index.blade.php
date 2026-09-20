@php
    $templates = $templates ?? collect();
    $categoryCounts = $categoryCounts ?? ['all' => 0];
    $statusCounts = $statusCounts ?? ['all' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
    $totalCount = $totalCount ?? 0;
    $currentCategory = $currentCategory ?? 'all';
    $currentStatus = $currentStatus ?? 'all';
    $currentSearch = $currentSearch ?? '';
    $currentSort = $currentSort ?? 'newest';
    $currentChannel = $currentChannel ?? 'all';
    $channelCounts = $channelCounts ?? ['all' => $totalCount, 'whatsapp' => $totalCount, 'instagram' => 0, 'facebook' => 0];
@endphp

<x-layouts.user :title="__('Template Library')" nav-key="templates" page="user-templates-index">

    <!-- ========== TOP BAR (shared) ========== -->


    <!-- ========== BODY ========== -->
    <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7" data-tpl-state data-tpl-category="{{ $currentCategory }}"
        data-tpl-status="{{ $currentStatus }}" data-tpl-search="{{ $currentSearch }}" data-tpl-sort="{{ $currentSort }}"
        data-tpl-channel="{{ $currentChannel }}"
        data-tpl-page="{{ method_exists($templates, 'currentPage') ? $templates->currentPage() : 1 }}">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

            <!-- ===== SIDEBAR ===== -->
            <aside class="lg:sticky lg:top-6 self-start space-y-3">
                <x-side-tip>
                    Submit templates with clear, variable-friendly copy (@{{ name }},
                    @{{ order_id }}). Approval times stay under 24h when bodies avoid promotional triggers and
                    match the chosen Meta category.
                </x-side-tip>

                <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card" id="side-rail">
                    <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">
                        {{ __('Campaigns') }}</div>
                    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] text-ink-700 hover:bg-paper-50"
                        href="{{ url('/wa-campaigns') }}">
                        <span class="flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                stroke-width="1.6">
                                <circle cx="8" cy="8" r="6" />
                                <path d="M8 5v3l2 2" />
                            </svg>
                            {{ __('Campaign Overview') }}
                        </span>
                    </a>
                    {{-- Template Messages — collapsed by default. JS in
 user-templates-index toggles both aria-expanded and
 the max-h/opacity classes when the user clicks. --}}
                    <button type="button" id="tpl-msg-toggle" aria-expanded="false"
                        class="rail-link w-full flex items-center justify-between px-3 py-2 rounded-xl text-ink-700 hover:bg-paper-50 text-[13px] font-medium">
                        <span class="flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                stroke-width="1.6">
                                <rect x="2.5" y="2.5" width="11" height="11" rx="1.5" />
                                <path d="M2.5 6h11M6 13.5V6" />
                            </svg>
                            {{ __('Template Messages') }}
                        </span>
                        <svg id="tpl-msg-chev" viewBox="0 0 12 12" class="w-3 h-3 transition-transform" fill="none"
                            stroke="currentColor" stroke-width="1.6">
                            <path d="M3 4l3 3 3-3" />
                        </svg>
                    </button>
                    <div id="tpl-msg-sub"
                        class="overflow-hidden transition-[max-height,opacity] duration-200 max-h-0 opacity-0">
                        <a class="rail-sub flex items-center justify-between pl-9 pr-3 py-2 rounded-xl bg-paper-50 text-ink-900 text-[12.5px] font-medium"
                            href="{{ url('/templates') }}">
                            <span>{{ __('Template Library') }}</span>
                        </a>
                        <a class="rail-sub flex items-center justify-between pl-9 pr-3 py-2 rounded-xl text-ink-700 text-[12.5px] hover:bg-paper-50"
                            href="{{ url('/wa-campaigns') }}">
                            <span>{{ __('WhatsApp') }}</span>
                        </a>
                    </div>
                    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] text-ink-700 hover:bg-paper-50"
                        href="{{ url('/scheduled') }}">
                        <span class="flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                stroke-width="1.6">
                                <rect x="2" y="3" width="12" height="11" rx="1.5" />
                                <path d="M2 6h12M5 1v3M11 1v3" />
                            </svg>
                            {{ __('Scheduled Campaigns') }}
                        </span>
                    </a>
                    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] text-ink-700 hover:bg-paper-50"
                        href="{{ url('/analytics') }}">
                        <span class="flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                stroke-width="1.6">
                                <path d="M2 11l3-5 3 3 3-6 3 4" />
                            </svg>
                            {{ __('Performance') }}
                        </span>
                    </a>
                    <a class="rail-link flex items-center justify-between px-3 py-2 rounded-xl text-[13px] text-ink-700 hover:bg-paper-50"
                        href="{{ url('/wa-campaigns') }}">
                        <span class="flex items-center gap-2">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                stroke-width="1.6">
                                <path d="M3 5h10v8H3zM3 5l5 4 5-4" />
                            </svg>
                            {{ __('Drafts') }}
                        </span>
                    </a>
                </div>

                <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card">
                    <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">
                        {{ __('Status') }}</div>
                    @php
                        $statusList = [
                            ['key' => 'all', 'label' => 'All', 'dot' => 'bg-paper-300'],
                            ['key' => 'approved', 'label' => 'Approved', 'dot' => 'bg-wa-green'],
                            ['key' => 'pending', 'label' => 'In review', 'dot' => 'bg-accent-amber'],
                            ['key' => 'rejected', 'label' => 'Rejected', 'dot' => 'bg-accent-coral'],
                        ];
                    @endphp
                    @foreach ($statusList as $s)
                        @php $active = $currentStatus === $s['key']; @endphp
                        <button data-tpl-filter="status" data-tpl-value="{{ $s['key'] }}" type="button"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-[13px] {{ $active ? 'bg-wa-deep text-paper-0 font-semibold' : 'text-ink-700 hover:bg-paper-50' }}">
                            <span class="flex items-center gap-2"><span
                                    class="w-2 h-2 rounded-full {{ $s['dot'] }}"></span>{{ $s['label'] }}</span>
                            <span data-tpl-status-count="{{ $s['key'] }}"
                                class="mono font-mono text-[11px] {{ $active ? 'opacity-90' : 'text-ink-500' }}">{{ number_format($statusCounts[$s['key']] ?? 0) }}</span>
                        </button>
                    @endforeach
                </div>

                <div
                    class="hairline border border-paper-200 rounded-2xl bg-wa-bubble/40 p-3 text-[11px] text-ink-700 leading-relaxed">
                    <div class="font-semibold text-ink-900 mb-1 flex items-center gap-1.5">
                        <svg viewBox="0 0 16 16" class="w-3 h-3 text-wa-deep" fill="currentColor">
                            <circle cx="8" cy="8" r="6" />
                        </svg>
                        Quick start
                    </div>
                    Read the <a href="https://developers.facebook.com/docs/whatsapp/message-templates/guidelines/"
                        target="_blank" rel="noopener"
                        class="text-wa-deep font-medium underline">{{ __('Template Guidelines') }}</a> before
                    submitting to Meta to keep approval times under 24 h.
                </div>
            </aside>

            <!-- ===== MAIN ===== -->
            <main>
                <!-- header -->
                <div class="mb-4 flex items-end justify-between gap-4 flex-wrap">
                    <div class="min-w-0">
                        <div class="mono font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                            {{ __('Campaigns / Templates') }}</div>
                        <h1
                            class="serif font-serif font-normal tracking-[-0.01em] text-[30px] sm:text-[36px] lg:text-[44px] leading-[1.0] tracking-tight">
                            {{ __('Template') }} <span class="italic text-wa-deep">{{ __('library') }}</span>.</h1>
                        <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                            {{ __('Pick a starter or submit your own. All templates must adhere to') }} <a
                                href="https://developers.facebook.com/docs/whatsapp/message-templates/guidelines/"
                                target="_blank" rel="noopener"
                                class="text-wa-deep font-medium underline decoration-wa-deep/40">{{ __("WhatsApp's guidelines") }}</a>
                            before they're approved by Meta.</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 pb-1">
                        @if (!empty($canImportMeta))
                            {{-- Pull templates created/approved directly in Meta Business
                                 Manager into this library. Idempotent — safe to click anytime. --}}
                            <form method="POST" action="{{ route('user.templates.import-from-meta') }}" class="inline">
                                @csrf
                                <button type="submit"
                                    class="px-4 py-2 rounded-full border border-paper-200 hover:border-wa-deep bg-paper-0 text-ink-800 text-[12px] font-semibold flex items-center gap-2 whitespace-nowrap"
                                    title="{{ __('Fetch your approved templates from Meta') }}">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                                        <path d="M13.5 2v3h-3" />
                                    </svg>
                                    {{ __('Sync from Meta') }}
                                </button>
                            </form>
                        @endif

                        {{-- Sync from Instaflow — pulls the workspace's Instagram
                             templates from a REMOTE Instaflow deployment into WaDesk's
                             library. Shown ONLY in remote-Instaflow mode: when the native
                             addon is the engine (instagram_enabled) templates are authored
                             locally via "New Template Message → Instagram", so there is
                             nothing remote to sync and the button is hidden. --}}
                        @php
                            $igTplSyncOn = auth()->user() && auth()->user()->current_workspace_id
                                && ! \App\Models\SystemSetting::get('instagram_enabled', false)
                                && class_exists(\App\Models\WorkspaceIgAccount::class)
                                && \App\Models\WorkspaceIgAccount::hasConnected(auth()->user()->current_workspace_id);
                        @endphp
                        @if ($igTplSyncOn)
                            <form method="POST" action="{{ route('user.templates.sync-instaflow') }}" class="inline">
                                @csrf
                                <button type="submit"
                                    class="px-4 py-2 rounded-full text-white text-[12px] font-semibold flex items-center gap-2 whitespace-nowrap"
                                    style="background:linear-gradient(135deg,#833AB4,#E1306C,#F77737)"
                                    title="{{ __('Import your Instagram templates from :igbrand', ['igbrand' => ig_brand_name()]) }}">
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                        stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9M13.5 2v3h-3" />
                                    </svg>
                                    {{ __('Sync from :igbrand', ['igbrand' => ig_brand_name()]) }}
                                </button>
                            </form>
                        @endif

                        <button type="button"
                            onclick="var m=document.getElementById('type-modal');m.classList.remove('hidden');var c=document.getElementById('tpl-step-channel');if(c){c.classList.remove('hidden');document.getElementById('tpl-step-format').classList.add('hidden');}"
                            class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold flex items-center gap-2 whitespace-nowrap">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M8 3v10M3 8h10" />
                            </svg>
                            New Template Message
                        </button>
                    </div>
                </div>

                {{-- Result banner for the "Sync from Meta" action (success/status or the
                     real Meta error, e.g. token invalid / app missing capability). --}}
                @if (session('status'))
                    <div class="mt-4 rounded-xl border border-wa-green/40 bg-wa-mint px-4 py-3 text-[12.5px] text-wa-deep">
                        {{ session('status') }}
                    </div>
                @endif
                @if ($errors->has('meta'))
                    <div class="mt-4 rounded-xl border border-accent-coral/40 bg-accent-coral/10 px-4 py-3 text-[12.5px] text-[#A1431F]">
                        <span class="font-semibold">{{ __('Sync from Meta failed:') }}</span>
                        {{ $errors->first('meta') }}
                    </div>
                @endif

                {{-- Channel tabs — WhatsApp vs Instagram, colored like the Flows
                     page. Only shown when the workspace actually has Instagram
                     templates (synced from Instaflow); a pure-WhatsApp workspace
                     never sees a pointless second tab. --}}
                @if ((($channelCounts['instagram'] ?? 0) > 0) || (($channelCounts['facebook'] ?? 0) > 0) || (($channelCounts['telegram'] ?? 0) > 0))
                    <div class="mt-5 flex items-center gap-2 flex-wrap" id="tpl-channel-tabs">
                        @php
                            $chTabs = [
                                ['key' => 'all', 'label' => __('All channels'), 'active' => 'border-ink-900 text-ink-900 bg-paper-0'],
                                ['key' => 'whatsapp', 'label' => __('WhatsApp'), 'active' => 'border-wa-deep text-wa-deep bg-wa-mint'],
                            ];
                            // Only surface a channel tab that actually has templates — a
                            // pure-WhatsApp + Instagram workspace never sees a dead Facebook tab.
                            if (($channelCounts['instagram'] ?? 0) > 0) {
                                $chTabs[] = ['key' => 'instagram', 'label' => __('Instagram'), 'active' => 'border-[#E1306C] text-[#C13584] bg-[#FDF2F8]'];
                            }
                            if (($channelCounts['facebook'] ?? 0) > 0) {
                                $chTabs[] = ['key' => 'facebook', 'label' => __('Facebook'), 'active' => 'border-[#1877F2] text-[#1877F2] bg-[#EFF5FF]'];
                            }
                            if (($channelCounts['telegram'] ?? 0) > 0) {
                                $chTabs[] = ['key' => 'telegram', 'label' => __('Telegram'), 'active' => 'border-[#229ED9] text-[#1B7CB0] bg-[#EAF6FC]'];
                            }
                        @endphp
                        @foreach ($chTabs as $ch)
                            @php $active = $currentChannel === $ch['key']; @endphp
                            <button type="button" data-tpl-filter="channel" data-tpl-value="{{ $ch['key'] }}"
                                class="tpl-ch inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full border text-[12.5px] font-medium transition {{ $active ? $ch['active'] : 'border-paper-200 text-ink-600 bg-paper-0 hover:border-ink-300' }}">
                                @if ($ch['key'] === 'instagram')
                                    <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none" />
                                    </svg>
                                @elseif ($ch['key'] === 'facebook')
                                    <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor">
                                        <path d="M12 2C6.5 2 2 6.14 2 11.25c0 2.91 1.45 5.51 3.72 7.21V22l3.4-1.87c.91.25 1.87.39 2.88.39 5.5 0 10-4.14 10-9.25S17.5 2 12 2zm1 12.44l-2.55-2.72-4.98 2.72 5.48-5.81 2.61 2.72 4.91-2.72-5.47 5.81z" />
                                    </svg>
                                @elseif ($ch['key'] === 'telegram')
                                    <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor">
                                        <path d="M21.8 4.3 2.9 11.6c-1 .4-1 .95-.17 1.2l4.8 1.5 1.85 5.9c.24.66.43.9.9.9.35 0 .5-.16.7-.35l2.3-2.24 4.78 3.53c.88.48 1.5.23 1.72-.8l3.1-14.6c.32-1.28-.48-1.86-1.3-1.53z" />
                                    </svg>
                                @elseif ($ch['key'] === 'whatsapp')
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path d="M2.6 11.2 2 14l2.9-.6A6 6 0 1 0 2.6 11.2Z" />
                                    </svg>
                                @else
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path d="M2 5h12M2 8h12M2 11h8" />
                                    </svg>
                                @endif
                                {{ $ch['label'] }}
                                <span data-tpl-ch-count="{{ $ch['key'] }}"
                                    class="count text-[10px] px-1.5 py-px rounded-full bg-white/70 text-ink-600 font-mono">{{ number_format($channelCounts[$ch['key']] ?? 0) }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <!-- filters row (single row, underline tabs like the reference) -->
                <div class="mt-5 hairline-b border-b border-paper-200 flex items-center gap-x-6 gap-y-2 px-2 flex-wrap">
                    <div class="flex items-center gap-6 flex-1 min-w-0 flex-wrap" id="tpl-tabs">
                        @php
                            $catLabels = [
                                'travel' => __('Travel'), 'healthcare' => __('Healthcare'),
                                'education' => __('Education'), 'ecommerce' => __('E-Commerce'),
                                'festival' => __('Festival'), 'finance' => __('Finance'),
                                'utility' => __('Utility'), 'marketing' => __('Marketing'),
                                'authentication' => __('Authentication'),
                            ];
                            // Show ONLY categories that actually have templates (count > 0) — no
                            // empty 0-count tabs. 'All' is always first. The label map titles known
                            // keys; any other category key falls back to a Title-Cased label.
                            $tabs = [['key' => 'all', 'label' => __('All')]];
                            foreach (($categoryCounts ?? []) as $catKey => $catCnt) {
                                if ($catKey === 'all' || (int) $catCnt <= 0) {
                                    continue;
                                }
                                $tabs[] = [
                                    'key' => $catKey,
                                    'label' => $catLabels[$catKey] ?? ucfirst(str_replace(['_', '-'], ' ', $catKey)),
                                ];
                            }
                        @endphp
                        @foreach ($tabs as $tab)
                            @php $active = $currentCategory === $tab['key']; @endphp
                            <button type="button" data-tpl-filter="category" data-tpl-value="{{ $tab['key'] }}"
                                class="tab-line inline-flex items-center gap-2 py-3.5 text-[14px] cursor-pointer bg-transparent border-0 border-b-2 transition whitespace-nowrap {{ $active ? 'text-wa-deep font-semibold border-wa-deep' : 'text-ink-600 border-transparent hover:text-ink-900' }}">
                                {{ $tab['label'] }}
                                <span data-tpl-cat-count="{{ $tab['key'] }}"
                                    class="count text-[10px] px-1.5 py-px rounded-full bg-paper-100 text-ink-600 font-mono">{{ number_format($categoryCounts[$tab['key']] ?? 0) }}</span>
                            </button>
                        @endforeach
                    </div>
                    {{-- WABA number filter (#52) — only shown when the workspace
                         has more than one connected number, otherwise it's noise. --}}
                    @if (!empty($wabaNumbers) && count($wabaNumbers) > 1)
                        <select
                            class="hairline border border-paper-200 rounded-full px-3 py-1.5 text-[12px] mono font-mono bg-paper-0 hover:bg-paper-50 focus:outline-none focus:border-wa-deep shrink-0"
                            title="{{ __('Filter by WhatsApp number') }}"
                            onchange="(function(v){ var u = new URL(window.location); if (v) { u.searchParams.set('provider_config_id', v); } else { u.searchParams.delete('provider_config_id'); } window.location = u.toString(); })(this.value)">
                            <option value="">{{ __('All numbers') }}</option>
                            @foreach ($wabaNumbers as $n)
                                <option value="{{ $n['id'] }}" @selected((int) ($currentProviderConfig ?? 0) === (int) $n['id'])>{{ $n['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select id="tpl-sort"
                        class="hairline border border-paper-200 rounded-full px-3 py-1.5 text-[12px] mono font-mono bg-paper-0 hover:bg-paper-50 focus:outline-none focus:border-wa-deep shrink-0">
                        <option value="newest" @selected($currentSort === 'newest')>{{ __('Newest') }}</option>
                        <option value="oldest" @selected($currentSort === 'oldest')>{{ __('Oldest') }}</option>
                        <option value="name-asc" @selected($currentSort === 'name-asc')>{{ __('Name A→Z') }}</option>
                        <option value="name-desc"@selected($currentSort === 'name-desc')>{{ __('Name Z→A') }}</option>
                    </select>
                    <div class="relative shrink-0">
                        <svg viewBox="0 0 16 16"
                            class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <circle cx="7" cy="7" r="5" />
                            <path d="m11 11 3 3" />
                        </svg>
                        <input id="tpl-search" type="search" value="{{ $currentSearch }}"
                            placeholder="{{ __('Search…') }}"
                            class="hairline border border-paper-200 rounded-full pl-9 pr-3 py-1.5 text-[12px] bg-paper-0 w-64 focus:outline-none focus:border-wa-deep" />
                    </div>
                </div>

                <!-- Sort / showing -->
                <div id="tpl-results-footer"
                    class="mt-3 mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 text-[11px] mono font-mono text-ink-500 {{ (method_exists($templates, 'total') ? $templates->total() : $totalCount) > 0 ? '' : 'hidden' }}">
                    <span>{{ __('Showing') }} <b class="text-ink-900"><span
                                data-tpl-shown>{{ $templates->count() }}</span> of <span
                                data-tpl-total>{{ method_exists($templates, 'total') ? number_format($templates->total()) : number_format($totalCount) }}</span></b>
                        filtered templates</span>
                    <span class="flex items-center gap-3">
                        <span class="flex items-center gap-1.5"><span
                                class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>Approved</span>
                        <span class="flex items-center gap-1.5"><span
                                class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>In review</span>
                        <span class="flex items-center gap-1.5"><span
                                class="w-1.5 h-1.5 rounded-full bg-accent-coral"></span>Rejected</span>
                    </span>
                </div>

                <!-- MESSAGE PRICING — what each template costs to send, up front.
                     Only shown when this workspace is actually billed per message
                     (global pay-per-message ON, or a BSP/bill-through-us workspace).
                     In classic plan mode nothing is charged per send, so showing a
                     per-message price here just confuses the admin. -->
                @if (\App\Services\MessageBillingService::payPerMessageFor(auth()->user()?->currentWorkspace))
                    <x-message-pricing class="mb-4" />
                @endif

                <!-- TEMPLATE GRID -->
                <div id="tpl-grid"
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 items-start transition-opacity">
                    @include('user.templates._cards', ['templates' => $templates])
                </div>

                <div id="tpl-pagination">
                    @include('user.partials.pagination', [
                        'paginator' => $templates,
                        'dataAttr' => 'data-tpl-page',
                        'label' => 'templates',
                    ])
                </div>

                <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 p-5 shadow-card">
                        <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                            {{ __('Help - 01') }}</div>
                        <div class="serif font-serif font-normal tracking-[-0.01em] text-[20px] mb-1">
                            {{ __('What is a template message?') }}</div>
                        <p class="text-[12.5px] text-ink-600 leading-relaxed">
                            {{ __('A pre-approved message format required by Meta for starting new conversations with customers outside the 24-hour service window.') }}
                        </p>
                    </div>
                    <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 p-5 shadow-card">
                        <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                            {{ __('Help - 02') }}</div>
                        <div class="serif font-serif font-normal tracking-[-0.01em] text-[20px] mb-1">
                            {{ __('How to improve approval times?') }}</div>
                        <p class="text-[12.5px] text-ink-600 leading-relaxed">
                            {{ __('Keep your message body clear, avoid overly promotional language in Utility categories, and always provide sample values for variables.') }}
                        </p>
                    </div>
                    <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 p-5 shadow-card">
                        <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                            {{ __('Help - 03') }}</div>
                        <div class="serif font-serif font-normal tracking-[-0.01em] text-[20px] mb-1">
                            {{ __('Why was my template rejected?') }}</div>
                        <p class="text-[12.5px] text-ink-600 leading-relaxed">
                            {{ __('Common reasons include incorrect formatting, mismatched category, or abusive content. Check the rejection reason and edit to resubmit.') }}
                        </p>
                    </div>
                </div>
            </main>
        </div>
    </div>



    <!-- ========== TEMPLATE TYPE MODAL ========== -->
    <div id="type-modal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-[rgba(11,31,28,0.45)]"
        onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="bg-paper-0 rounded-2xl shadow-soft border border-paper-200 max-w-xl w-full overflow-hidden">
            @php
                $__tplWsId = auth()->user()?->current_workspace_id;
                $igTplConnected = auth()->user() && $__tplWsId
                    && class_exists(\App\Models\WorkspaceIgAccount::class)
                    && \App\Models\WorkspaceIgAccount::hasConnected($__tplWsId);
                // Facebook mirrors Instagram: a reusable Messenger DM template. Same
                // "no dead channel" gate — the channel must be enabled system-wide
                // AND the workspace must have at least one Page connected.
                $fbTplConnected = auth()->user() && $__tplWsId
                    && (bool) \App\Models\SystemSetting::get('facebook_enabled', false)
                    && class_exists(\App\Models\FacebookPage::class)
                    && \App\Models\FacebookPage::hasConnected($__tplWsId);
                // Telegram mirrors Facebook: a reusable bot message. Same "no dead
                // channel" gate — enabled system-wide AND a bot connected.
                $tgTplConnected = auth()->user() && $__tplWsId
                    && (bool) \App\Models\SystemSetting::get('telegram_enabled', false)
                    && class_exists(\App\Models\TelegramBot::class)
                    && \App\Models\TelegramBot::hasConnected($__tplWsId);
                // Show the channel-picker step whenever ANY reusable-DM channel is
                // available alongside WhatsApp.
                $multiChannel = $igTplConnected || $fbTplConnected || $tgTplConnected;
            @endphp
            <div class="px-6 py-5 hairline-b border-b border-paper-200 flex items-start justify-between gap-4">
                <div class="mono font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mt-1">
                    {{ __('New template') }}</div>
                <button type="button" onclick="document.getElementById('type-modal').classList.add('hidden')"
                    class="w-8 h-8 rounded-full hairline border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center"
                    title="{{ __('Close') }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                        stroke-width="1.6">
                        <path d="M4 4l8 8M12 4l-8 8" />
                    </svg>
                </button>
            </div>

            @if ($multiChannel)
                {{-- STEP 1 — pick the channel. WhatsApp = Meta-approved templates
                     (Standard/Carousel below). Instagram / Facebook = a local
                     reusable DM message (no Meta approval), authored on the same
                     create form. --}}
                <div id="tpl-step-channel">
                    <div class="px-6 pt-4">
                        <h3 class="serif font-serif font-normal tracking-[-0.01em] text-[22px] leading-tight">
                            {{ __('Pick a') }} <span class="italic text-wa-deep">{{ __('channel') }}</span> {{ __('to begin') }}
                        </h3>
                        <p class="text-[12px] text-ink-600 mt-1">
                            {{ __('WhatsApp uses Meta-approved templates. Instagram & Facebook save a reusable DM message.') }}</p>
                    </div>
                    <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="button"
                            onclick="document.getElementById('tpl-step-channel').classList.add('hidden');document.getElementById('tpl-step-format').classList.remove('hidden')"
                            class="group text-left hairline border border-paper-200 rounded-xl p-4 hover:border-wa-deep hover:bg-wa-bubble/30 transition cursor-pointer">
                            <div class="w-10 h-10 rounded-lg bg-wa-mint text-wa-deep grid place-items-center mb-3">
                                <svg viewBox="0 0 16 16" class="w-5 h-5" fill="currentColor"><path d="M8 1.5A6.5 6.5 0 0 0 2.3 11L1.5 14.5l3.6-.8A6.5 6.5 0 1 0 8 1.5z"/></svg>
                            </div>
                            <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">{{ __('WhatsApp') }}</div>
                            <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                                {{ __('Standard or Carousel template, submitted to Meta for approval.') }}</p>
                            <div class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-wa-deep group-hover:gap-2 transition-all">
                                {{ __('Choose format') }}
                                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4l4 4-4 4" /></svg>
                            </div>
                        </button>
                        @if ($igTplConnected)
                        <a href="{{ url('/templates/create') }}?channel=instagram"
                            class="group block hairline border border-paper-200 rounded-xl p-4 hover:border-[#E1306C] hover:bg-[#FDF2F8] transition cursor-pointer">
                            <div class="w-10 h-10 rounded-lg grid place-items-center mb-3 text-white" style="background:linear-gradient(135deg,#833AB4,#E1306C,#F77737)">
                                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
                            </div>
                            <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">{{ __('Instagram') }}</div>
                            <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                                {{ __('A reusable Instagram DM message — text with optional buttons. No Meta approval needed.') }}</p>
                            <div class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold" style="color:#9D174D">
                                {{ __('Create Instagram template') }}
                                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4l4 4-4 4" /></svg>
                            </div>
                        </a>
                        @endif
                        @if ($fbTplConnected)
                        {{-- Facebook — mirrors the Instagram card: a local reusable
                             Messenger DM (no Meta approval), Facebook-blue #1877F2 with
                             the Messenger glyph. Opens the same create form scoped to
                             ?channel=facebook. --}}
                        <a href="{{ url('/templates/create') }}?channel=facebook"
                            class="group block hairline border border-paper-200 rounded-xl p-4 hover:border-[#1877F2] hover:bg-[#EFF5FF] transition cursor-pointer">
                            <div class="w-10 h-10 rounded-lg grid place-items-center mb-3 text-white" style="background:#1877F2">
                                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M12 2C6.5 2 2 6.14 2 11.25c0 2.91 1.45 5.51 3.72 7.21V22l3.4-1.87c.91.25 1.87.39 2.88.39 5.5 0 10-4.14 10-9.25S17.5 2 12 2zm1 12.44l-2.55-2.72-4.98 2.72 5.48-5.81 2.61 2.72 4.91-2.72-5.47 5.81z"/></svg>
                            </div>
                            <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">{{ __('Facebook') }}</div>
                            <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                                {{ __('A reusable Messenger DM message — text with optional buttons. No Meta approval needed.') }}</p>
                            <div class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold" style="color:#1877F2">
                                {{ __('Create Facebook template') }}
                                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4l4 4-4 4" /></svg>
                            </div>
                        </a>
                        @endif
                        @if ($tgTplConnected)
                        {{-- Telegram — mirrors the Facebook card: a local reusable bot
                             message (no Meta approval), Telegram-blue #229ED9 with the
                             paper-plane glyph. Opens the create form scoped to
                             ?channel=telegram. --}}
                        <a href="{{ url('/templates/create') }}?channel=telegram"
                            class="group block hairline border border-paper-200 rounded-xl p-4 hover:border-[#229ED9] hover:bg-[#EAF6FC] transition cursor-pointer">
                            <div class="w-10 h-10 rounded-lg grid place-items-center mb-3 text-white" style="background:#229ED9">
                                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M21.8 4.3 2.9 11.6c-1 .4-1 .95-.17 1.2l4.8 1.5 1.85 5.9c.24.66.43.9.9.9.35 0 .5-.16.7-.35l2.3-2.24 4.78 3.53c.88.48 1.5.23 1.72-.8l3.1-14.6c.32-1.28-.48-1.86-1.3-1.53z"/></svg>
                            </div>
                            <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">{{ __('Telegram') }}</div>
                            <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                                {{ __('A reusable Telegram bot message — text with optional buttons. No Meta approval needed.') }}</p>
                            <div class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold" style="color:#1B7CB0">
                                {{ __('Create Telegram template') }}
                                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4l4 4-4 4" /></svg>
                            </div>
                        </a>
                        @endif
                    </div>
                </div>
            @endif

            {{-- STEP 2 — WhatsApp format (Standard / Carousel). Shown directly when
                 no reusable-DM channel (Instagram / Facebook) is connected
                 (unchanged behaviour). --}}
            <div id="tpl-step-format" class="{{ $multiChannel ? 'hidden' : '' }}">
                <div class="px-6 pt-4">
                    @if ($multiChannel)
                        <button type="button"
                            onclick="document.getElementById('tpl-step-format').classList.add('hidden');document.getElementById('tpl-step-channel').classList.remove('hidden')"
                            class="inline-flex items-center gap-1 text-[11px] font-semibold text-ink-500 hover:text-wa-deep mb-1.5">
                            <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 4l-4 4 4 4" /></svg>
                            {{ __('Back') }}
                        </button>
                    @endif
                    <h3 class="serif font-serif font-normal tracking-[-0.01em] text-[22px] leading-tight">
                        {{ __('Pick a') }} <span class="italic text-wa-deep">{{ __('format') }}</span> {{ __('to begin') }}
                    </h3>
                    <p class="text-[12px] text-ink-600 mt-1">
                        {{ __('Standard works for most messages. Carousel adds swipeable cards.') }}</p>
                </div>
                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <a href="{{ url('/templates/create') }}?type=standard"
                    class="group block hairline border border-paper-200 rounded-xl p-4 hover:border-wa-deep hover:bg-wa-bubble/30 transition cursor-pointer">
                    <div class="w-10 h-10 rounded-lg bg-wa-mint text-wa-deep grid place-items-center mb-3">
                        <svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor"
                            stroke-width="1.5">
                            <rect x="2" y="3" width="12" height="10" rx="1.5" />
                            <path d="M2 6h12M5 9h6M5 11h4" />
                        </svg>
                    </div>
                    <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">
                        {{ __('Standard') }}</div>
                    <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                        {{ __('Header, body, footer with optional buttons, attachments, and interactive components.') }}
                    </p>
                    <div
                        class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-wa-deep group-hover:gap-2 transition-all">
                        Use this format
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor"
                            stroke-width="1.8">
                            <path d="M5 4l4 4-4 4" />
                        </svg>
                    </div>
                </a>
                <a href="{{ url('/templates/create') }}?type=carousel"
                    class="group block hairline border border-paper-200 rounded-xl p-4 hover:border-wa-deep hover:bg-wa-bubble/30 transition cursor-pointer">
                    <div class="w-10 h-10 rounded-lg bg-wa-mint text-wa-deep grid place-items-center mb-3">
                        <svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor"
                            stroke-width="1.5">
                            <rect x="2" y="4" width="6" height="9" rx="1" />
                            <rect x="9" y="4" width="6" height="9" rx="1" />
                        </svg>
                    </div>
                    <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">
                        {{ __('Carousel') }}</div>
                    <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                        {{ __('Up to 10 swipeable cards with image, title, body, and 2 buttons each.') }}</p>
                    <div
                        class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-wa-deep group-hover:gap-2 transition-all">
                        Use this format
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor"
                            stroke-width="1.8">
                            <path d="M5 4l4 4-4 4" />
                        </svg>
                    </div>
                </a>
                <a href="{{ url('/templates/create') }}?type=catalog"
                    class="group block hairline border border-paper-200 rounded-xl p-4 hover:border-wa-deep hover:bg-wa-bubble/30 transition cursor-pointer">
                    <div class="w-10 h-10 rounded-lg bg-wa-mint text-wa-deep grid place-items-center mb-3">
                        <svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor"
                            stroke-width="1.5">
                            <path d="M3 5h10l-.7 7.2a1 1 0 0 1-1 .8H4.7a1 1 0 0 1-1-.8L3 5Z" />
                            <path d="M5.5 5V4a2.5 2.5 0 0 1 5 0v1" />
                        </svg>
                    </div>
                    <div class="serif font-serif font-normal tracking-[-0.01em] text-[18px] leading-tight">
                        {{ __('Catalog') }}</div>
                    <p class="text-[11.5px] text-ink-600 mt-1.5 leading-relaxed">
                        {{ __('A “View catalog” button that opens your product catalog. WhatsApp official numbers only.') }}
                    </p>
                    <div
                        class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-wa-deep group-hover:gap-2 transition-all">
                        Use this format
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor"
                            stroke-width="1.8">
                            <path d="M5 4l4 4-4 4" />
                        </svg>
                    </div>
                </a>
                </div>
            </div>
        </div>
    </div>

</x-layouts.user>
