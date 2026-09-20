<x-layouts.user :title="__('Flow Analytics')" nav-key="flow-analytics" page="user-flows-analytics">

    {{-- window.t() map for the strings this page's JS paints (table cells, status
         badges, confirm copy). English locales get no map and fall back to the
         English key, so nothing here is ever an untranslated placeholder. --}}
    @include('partials.js-i18n')

    @php
        /* Everything below reads from $stats — the SAME payload
           /flows/analytics/data returns (FlowsController::buildAnalytics), so the
           first paint carries real numbers before any fetch resolves. This mirrors
           $chartData on the campaign detail page. Nothing is estimated: a metric
           the controller could not derive comes back null and its tile is hidden
           rather than shown as a zero. */
        $totals = $stats['totals'] ?? [];
        $trend = $stats['series'] ?? [
            'granularity' => 'day',
            'categories' => [],
            'enrolled' => [],
            'completed' => [],
            'failed' => [],
        ];
        $board = $stats['flows'] ?? [];
        $topReasons = $stats['failure_reasons'] ?? [];
        $maxBatch = (int) ($stats['retry_max_batch'] ?? 50);
        $cooldown = (int) ($stats['retry_cooldown_seconds'] ?? 30);

        $flowsTotal = (int) ($totals['flows_total'] ?? 0);
        $runsTotal = (int) ($totals['runs'] ?? 0);
        $completionRate = $totals['completion_rate'] ?? null;
        $avgSeconds = $totals['avg_complete_seconds'] ?? null;

        $rangeChips = ['7' => __('7 days'), '30' => __('30 days'), '90' => __('90 days'), 'all' => __('All time')];
        $currentRange = in_array((string) $range, array_keys($rangeChips), true) ? (string) $range : '30';

        /* Flow lifecycle pill — same treatment as the flow cards on /flows so a
           flow reads identically wherever it appears. */
        $stateStyle = [
            'live' => 'bg-wa-green/10 text-wa-deep border border-wa-green/30',
            'paused' => 'bg-[#EFE5F5] text-[#5B3D8A] border border-[#D9C7E8]',
            'draft' => 'bg-paper-50 text-ink-500 border border-paper-200',
        ];
        $stateLabel = ['live' => __('Live'), 'paused' => __('Paused'), 'draft' => __('Draft')];

        /* Seconds → "2 h 14 m" / "45 s". Returns null for null, so a missing
           average renders no tile instead of a fabricated zero. */
        $fmtDuration = function ($seconds) {
            if ($seconds === null) {
                return null;
            }
            $s = max(0, (int) $seconds);
            if ($s < 60) {
                return __(':n s', ['n' => $s]);
            }
            if ($s < 3600) {
                return __(':n m', ['n' => (int) floor($s / 60)]);
            }
            if ($s < 86400) {
                $h = (int) floor($s / 3600);
                $m = (int) floor(($s % 3600) / 60);
                return $m > 0 ? __(':h h :m m', ['h' => $h, 'm' => $m]) : __(':n h', ['n' => $h]);
            }
            $d = (int) floor($s / 86400);
            $h = (int) floor(($s % 86400) / 3600);
            return $h > 0 ? __(':d d :h h', ['d' => $d, 'h' => $h]) : __(':n d', ['n' => $d]);
        };

        $flowName = fn($name, $id) => $name ?: __('Untitled flow') . ' #' . $id;
    @endphp

    {{-- ========== HEADER ========== --}}
    <div class="border-b border-paper-200 bg-paper-0 sticky top-0 z-20">
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-3 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('user.flows.index') }}"
                    class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center"
                    title="{{ __('Back to flows') }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M10 4l-4 4 4 4" />
                    </svg>
                </a>
                <div class="min-w-0">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                        {{ __('Flows') }} / {{ __('Analytics') }}@if ($flow)
                            / #{{ $flow->id }}
                        @endif
                    </div>
                    <div class="font-serif text-[20px] leading-tight truncate">
                        {{ $flow ? $flowName($flow->flow_name, $flow->id) : __('All flows') }}
                    </div>
                </div>
                @if ($flow)
                    @php $flState = $flow->is_published ? ($flow->is_active ? 'live' : 'paused') : 'draft'; @endphp
                    <span
                        class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold {{ $stateStyle[$flState] }}">{{ $stateLabel[$flState] }}</span>
                @endif
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Real generation timestamp from the payload, localised in JS. --}}
                <span class="text-[11px] text-ink-500 hidden sm:inline" data-fa-generated
                    data-iso="{{ $stats['generated_at'] ?? '' }}"></span>
                @if ($flow)
                    <a href="{{ route('user.flows.analytics') }}"
                        class="px-3.5 py-1.5 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">
                        {{ __('All flows') }}
                    </a>
                    <a href="{{ route('user.flows.builder.edit', $flow->id) }}"
                        class="px-3.5 py-1.5 border border-paper-200 rounded-full bg-paper-0 hover:bg-wa-bubble text-wa-deep text-[12px] font-semibold flex items-center gap-2">
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M9.5 3.5 12.5 6.5 6 13H3v-3z" />
                        </svg>
                        {{ __('Open in builder') }}
                    </a>
                @endif
                <button type="button" data-fa-refresh
                    class="px-3.5 py-1.5 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M13 8a5 5 0 1 1-1.5-3.5" />
                        <path d="M13 2.5V5h-2.5" />
                    </svg>
                    {{ __('Refresh') }}
                </button>
            </div>
        </div>
    </div>

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-6 space-y-5" data-fa-root
        data-flow-id="{{ $flowId ?? '' }}" data-range="{{ $currentRange }}" data-max-batch="{{ $maxBatch }}"
        data-cooldown="{{ $cooldown }}" data-url-base="{{ route('user.flows.analytics') }}"
        data-url-data="{{ route('user.flows.analytics.data') }}"
        data-url-runs="{{ route('user.flows.analytics.runs') }}"
        data-url-errors="{{ route('user.flows.analytics.errors') }}"
        data-url-retries="{{ route('user.flows.analytics.retries') }}"
        data-url-retry-bulk="{{ route('user.flows.analytics.retry-bulk') }}">

        @if ($flowsTotal < 1)
            {{-- Nothing to measure: no flow exists in this workspace yet. One
                 honest empty state — no charts, no zeroed tiles. --}}
            @include('user.partials.empty-state', [
                'title' => __('No flows to analyse yet'),
                'message' => __(
                    'Flow analytics fills in as soon as a flow runs — every enrolment, completion, failure and retry is recorded here.'),
                'actionHref' => route('user.flows.index'),
                'actionLabel' => __('Go to flows'),
            ])
        @else
            {{-- ========== FILTER BAR ========== --}}
            <section
                class="bg-paper-0 border border-paper-200 rounded-2xl px-4 sm:px-5 py-3.5 shadow-card flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <label for="fa-flow"
                        class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('Flow') }}</label>
                    <select id="fa-flow" data-fa-flow
                        class="hairline border border-paper-200 rounded-full px-3 py-1.5 text-[12px] bg-paper-0 focus:outline-none focus:border-wa-deep max-w-[280px]">
                        <option value="">{{ __('All flows') }}</option>
                        @foreach ($flows as $f)
                            <option value="{{ $f['id'] }}" @selected((int) ($flowId ?? 0) === (int) $f['id'])>
                                {{ $flowName($f['name'], $f['id']) }} — {{ $stateLabel[$f['state']] ?? $f['state'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-1.5 flex-wrap">
                    <span
                        class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500 mr-1">{{ __('Period') }}</span>
                    @foreach ($rangeChips as $key => $label)
                        <button type="button" data-fa-range="{{ $key }}"
                            class="fa-range-chip px-3 py-1.5 rounded-full text-[12px] font-semibold transition {{ $currentRange === $key ? 'bg-wa-deep text-paper-0' : 'text-ink-600 border border-paper-200 bg-paper-0 hover:bg-paper-50' }}">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="flex-1"></div>
                {{-- Window boundaries straight from the payload (from/to), so the
                     operator always knows exactly what the numbers cover. --}}
                <div class="text-[11px] text-ink-500" data-fa-window></div>
            </section>

            {{-- ========== KPI ROW ========== --}}
            {{-- Every tile maps to a field the controller actually returns. The two
                 that can be undefined — completion rate needs at least one run,
                 average duration needs at least one completed run — carry
                 data-fa-tile and are hidden when null, never shown as a fake 0. --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                    <div class="text-[12px] text-ink-600">{{ __('Total runs') }}</div>
                    <div class="font-serif text-[42px] leading-none mt-2" data-fa-kpi="runs">
                        {{ number_format($runsTotal) }}</div>
                    <div class="text-[11px] text-ink-500 mt-2">{{ __('Enrolments in this period') }}</div>
                </div>
                <div class="bg-paper-0 border border-wa-green/40 rounded-2xl p-5 shadow-card">
                    <div class="text-[12px] text-ink-600">{{ __('Completed') }}</div>
                    <div class="font-serif text-[42px] leading-none mt-2" data-fa-kpi="completed">
                        {{ number_format((int) ($totals['completed'] ?? 0)) }}</div>
                    <div class="text-[11px] text-wa-deep mt-2">{{ __('Reached the end of the flow') }}</div>
                </div>
                <div class="bg-paper-0 border border-accent-coral/40 rounded-2xl p-5 shadow-card">
                    <div class="text-[12px] text-ink-600">{{ __('Failed') }}</div>
                    <div class="font-serif text-[42px] leading-none mt-2" data-fa-kpi="failed">
                        {{ number_format((int) ($totals['failed'] ?? 0)) }}</div>
                    <div class="text-[11px] text-accent-coral mt-2">{{ __('Stopped with an error') }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                    <div class="text-[12px] text-ink-600">{{ __('Active') }}</div>
                    <div class="font-serif text-[42px] leading-none mt-2" data-fa-kpi="active">
                        {{ number_format((int) ($totals['active'] ?? 0)) }}</div>
                    <div class="text-[11px] text-ink-500 mt-2">{{ __('Still running now') }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card {{ $completionRate === null ? 'hidden' : '' }}"
                    data-fa-tile="completion_rate">
                    <div class="text-[12px] text-ink-600">{{ __('Completion rate') }}</div>
                    <div class="font-serif text-[42px] leading-none mt-2"><span
                            data-fa-kpi="completion_rate">{{ $completionRate === null ? '' : number_format((float) $completionRate, 1) }}</span>%
                    </div>
                    <div class="text-[11px] text-ink-500 mt-2">{{ __('Of all runs in this period') }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card {{ $avgSeconds === null ? 'hidden' : '' }}"
                    data-fa-tile="avg_complete_seconds">
                    <div class="text-[12px] text-ink-600">{{ __('Avg time to complete') }}</div>
                    <div class="font-serif text-[34px] leading-none mt-2 truncate" data-fa-kpi="avg_complete_seconds">
                        {{ $fmtDuration($avgSeconds) }}</div>
                    <div class="text-[11px] text-ink-500 mt-2">{{ __('Enrolment to completion') }}</div>
                </div>
            </div>

            {{-- Secondary counters — plain integers from the same payload. --}}
            <div class="flex items-center gap-2 flex-wrap text-[11.5px]">
                @foreach ([['contacts_reached', __('Contacts reached')], ['paused', __('Paused runs')], ['retried_runs', __('Runs retried')], ['retry_attempts', __('Retry attempts')], ['flows_live', __('Live flows')], ['flows_with_runs', __('Flows with runs')], ['flows_total', __('Flows total')]] as [$k, $lbl])
                    <span
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 text-ink-600 shadow-card">
                        {{ $lbl }}
                        <b class="font-mono text-ink-900"
                            data-fa-kpi="{{ $k }}">{{ number_format((int) ($totals[$k] ?? 0)) }}</b>
                    </span>
                @endforeach
            </div>

            {{-- ========== TABS ========== --}}
            <div
                class="bg-paper-0 border border-paper-200 rounded-2xl px-4 sm:px-5 py-3 shadow-card flex items-center gap-1 overflow-x-auto">
                <button type="button" data-tab="overview"
                    class="tab-btn shrink-0 whitespace-nowrap px-4 py-2 rounded-full text-[13px] font-semibold transition bg-wa-deep text-paper-0">{{ __('Overview') }}</button>
                <button type="button" data-tab="history"
                    class="tab-btn shrink-0 whitespace-nowrap px-4 py-2 rounded-full text-[13px] font-semibold text-ink-600 hover:bg-paper-50 transition">{{ __('Execution history') }}</button>
                <button type="button" data-tab="errors"
                    class="tab-btn shrink-0 whitespace-nowrap px-4 py-2 rounded-full text-[13px] font-semibold text-ink-600 hover:bg-paper-50 transition">{{ __('Error logs') }}</button>
                <button type="button" data-tab="retries"
                    class="tab-btn shrink-0 whitespace-nowrap px-4 py-2 rounded-full text-[13px] font-semibold text-ink-600 hover:bg-paper-50 transition">{{ __('Retry records') }}</button>
            </div>

            {{-- ========== OVERVIEW ========== --}}
            <section data-panel="overview" class="tab-panel space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                    <div class="lg:col-span-8 bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                        <div class="flex items-start justify-between gap-4 mb-3">
                            <div>
                                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                                    {{ __('Run trend') }}</div>
                                <h2 class="font-serif text-[26px] leading-tight mt-1">
                                    {{ __('Enrolments, completions, failures') }}</h2>
                            </div>
                            <div class="flex items-center gap-3 text-[11px] text-ink-500 flex-wrap justify-end">
                                <span class="flex items-center gap-1.5"><span
                                        class="w-2.5 h-2.5 rounded-full bg-wa-deep"></span>{{ __('Enrolled') }}</span>
                                <span class="flex items-center gap-1.5"><span
                                        class="w-2.5 h-2.5 rounded-full bg-wa-green"></span>{{ __('Completed') }}</span>
                                <span class="flex items-center gap-1.5"><span
                                        class="w-2.5 h-2.5 rounded-full bg-accent-coral"></span>{{ __('Failed') }}</span>
                            </div>
                        </div>
                        {{-- Chart node stays hidden until the payload actually has
                             buckets — an empty window shows the app's empty state,
                             never a flat invented line. --}}
                        <div id="chart-fa-trend" class="{{ empty($trend['categories']) ? 'hidden' : '' }}"></div>
                        <div data-fa-trend-empty class="{{ empty($trend['categories']) ? '' : 'hidden' }}">
                            @include('user.partials.empty-state', [
                                'title' => __('No runs in this period'),
                                'message' => __('Widen the period, or pick a different flow, to see activity here.'),
                                'class' => 'border-0 shadow-none',
                            ])
                        </div>
                    </div>
                    <div class="lg:col-span-4 bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Outcome mix') }}</div>
                        <h2 class="font-serif text-[26px] leading-tight mt-1">{{ __('Run status') }}</h2>
                        <div id="chart-fa-status" class="mt-2 {{ $runsTotal < 1 ? 'hidden' : '' }}"></div>
                        <div data-fa-status-empty class="{{ $runsTotal < 1 ? '' : 'hidden' }}">
                            @include('user.partials.empty-state', [
                                'title' => __('Nothing to split yet'),
                                'message' => __('The status mix appears once at least one run exists in this period.'),
                                'class' => 'border-0 shadow-none',
                            ])
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                    {{-- ---------- PER-FLOW LEADERBOARD ---------- --}}
                    <div
                        class="lg:col-span-8 bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                        <div class="px-5 py-4 border-b border-paper-200">
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                                {{ __('Per-flow performance') }}</div>
                            <h2 class="font-serif text-[24px] leading-tight mt-1">{{ __('Flow leaderboard') }}</h2>
                            <p class="text-[11.5px] text-ink-500 mt-1">
                                {{ __('Select a row to scope this whole page to that flow.') }}</p>
                        </div>
                        <div class="overflow-x-auto {{ empty($board) ? 'hidden' : '' }}" data-fa-board-wrap>
                            <table class="w-full text-[12.5px]">
                                <thead class="bg-paper-50 text-ink-500 border-b border-paper-200">
                                    <tr>
                                        <th
                                            class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                            {{ __('Flow') }}</th>
                                        <th
                                            class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                            {{ __('Runs') }}</th>
                                        <th
                                            class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                            {{ __('Completed') }}</th>
                                        <th
                                            class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                            {{ __('Failed') }}</th>
                                        <th
                                            class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                            {{ __('Completion rate') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-paper-200" data-fa-board>
                                    @foreach ($board as $row)
                                        <tr data-fa-board-row="{{ $row['flow_id'] }}"
                                            class="cursor-pointer hover:bg-paper-50 {{ (int) ($flowId ?? 0) === (int) $row['flow_id'] ? 'bg-wa-bubble/40' : '' }}">
                                            <td class="px-4 py-3">
                                                <div class="font-semibold truncate">
                                                    {{ $flowName($row['name'], $row['flow_id']) }}</div>
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 mt-1 rounded-full text-[10px] font-semibold {{ $stateStyle[$row['state']] ?? $stateStyle['draft'] }}">{{ $stateLabel[$row['state']] ?? $row['state'] }}</span>
                                            </td>
                                            <td class="px-3 py-3 text-right font-mono">
                                                {{ number_format((int) $row['runs']) }}</td>
                                            <td class="px-3 py-3 text-right font-mono text-wa-deep">
                                                {{ number_format((int) $row['completed']) }}</td>
                                            <td
                                                class="px-3 py-3 text-right font-mono {{ (int) $row['failed'] > 0 ? 'text-accent-coral' : 'text-ink-500' }}">
                                                {{ number_format((int) $row['failed']) }}</td>
                                            <td class="px-4 py-3 text-right font-mono">
                                                {{ $row['completion_rate'] === null ? '—' : number_format((float) $row['completion_rate'], 1) . '%' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div data-fa-board-empty class="px-5 py-6 {{ empty($board) ? '' : 'hidden' }}">
                            @include('user.partials.empty-state', [
                                'title' => __('No flow has run in this period'),
                                'message' => __('Once a contact enters a flow, its per-flow totals appear here.'),
                                'class' => 'border-0 shadow-none',
                            ])
                        </div>
                    </div>

                    {{-- ---------- TOP FAILURE REASONS ---------- --}}
                    <div class="lg:col-span-4 bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Why runs fail') }}</div>
                        <h2 class="font-serif text-[24px] leading-tight mt-1 mb-2">{{ __('Top failure reasons') }}
                        </h2>
                        <div id="chart-fa-failures" class="{{ empty($topReasons) ? 'hidden' : '' }}"></div>
                        <div data-fa-failures-empty class="{{ empty($topReasons) ? '' : 'hidden' }}">
                            @include('user.partials.empty-state', [
                                'title' => __('No failures recorded'),
                                'message' => __('Nothing in this period stopped with an error.'),
                                'class' => 'border-0 shadow-none',
                            ])
                        </div>
                        <button type="button" data-fa-goto-errors
                            class="mt-3 w-full px-3 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-semibold {{ empty($topReasons) ? 'hidden' : '' }}">
                            {{ __('Open error logs') }}
                        </button>
                    </div>
                </div>
            </section>

            {{-- ========== EXECUTION HISTORY ========== --}}
            <section data-panel="history" class="tab-panel hidden space-y-5">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Flow execution history') }}</div>
                        <h2 class="font-serif text-[24px] leading-tight mt-1">{{ __('Every run, newest first') }}</h2>
                    </div>

                    <div class="px-5 py-3 border-b border-paper-200 flex items-center gap-2 flex-wrap">
                        @foreach ([['', __('All')], ['active', __('Active')], ['paused', __('Paused')], ['completed', __('Completed')], ['failed', __('Failed')]] as [$key, $label])
                            <button type="button" data-fa-runs-status="{{ $key }}"
                                class="fa-runs-chip px-3 py-1.5 rounded-full text-[12px] font-semibold transition {{ $key === '' ? 'bg-wa-deep text-paper-0' : 'text-ink-600 border border-paper-200 bg-paper-0 hover:bg-paper-50' }}">
                                {{ $label }}
                                <span class="ml-1 font-mono text-[11px]"
                                    data-fa-runs-count="{{ $key ?: 'all' }}">—</span>
                            </button>
                        @endforeach
                        <div class="flex-1"></div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <label class="flex items-center gap-1.5 text-[11px] text-ink-500">
                                {{ __('From') }}
                                <input type="date" data-fa-runs-from
                                    class="hairline border border-paper-200 rounded-full px-2.5 py-1 text-[12px] bg-paper-0 focus:outline-none focus:border-wa-deep" />
                            </label>
                            <label class="flex items-center gap-1.5 text-[11px] text-ink-500">
                                {{ __('To') }}
                                <input type="date" data-fa-runs-to
                                    class="hairline border border-paper-200 rounded-full px-2.5 py-1 text-[12px] bg-paper-0 focus:outline-none focus:border-wa-deep" />
                            </label>
                            <div class="relative">
                                <svg viewBox="0 0 16 16"
                                    class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-ink-500"
                                    fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="7" cy="7" r="5" />
                                    <path d="m11 11 3 3" />
                                </svg>
                                <input type="search" data-fa-runs-search
                                    placeholder="{{ __('Contact, flow or reason...') }}"
                                    class="hairline border border-paper-200 rounded-full pl-9 pr-3 py-1.5 text-[12px] bg-paper-0 w-full sm:w-64 focus:outline-none focus:border-wa-deep" />
                            </div>
                        </div>
                    </div>
                    <div class="px-5 pt-2 pb-3 text-[11px] text-ink-500">
                        {{ __('Custom dates apply to this table only and override the period above.') }}
                    </div>

                    {{-- Bulk retry bar — only failed runs are selectable, and the
                         batch is capped at what the server accepts. --}}
                    <div data-fa-bulk-bar
                        class="hidden px-5 py-3 border-b border-paper-200 bg-wa-bubble/40 items-center gap-3 flex-wrap">
                        <span class="text-[12.5px] text-ink-700"><b data-fa-bulk-count>0</b>
                            {{ __('failed runs selected') }}</span>
                        {{-- Both numbers are the server's own limits
                             (FlowRetryService::MAX_BATCH / COOLDOWN_SECONDS), so the
                             UI can never promise more than the backend allows. --}}
                        <span
                            class="text-[11px] text-ink-500">{{ __('Up to :n per batch, and one retry per run every :s s', ['n' => $maxBatch, 's' => $cooldown]) }}</span>
                        <div class="flex-1"></div>
                        <button type="button" data-fa-bulk-clear
                            class="px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Clear') }}</button>
                        <button type="button" data-fa-bulk-retry
                            class="px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Retry selected') }}</button>
                    </div>

                    <div data-fa-runs-loading class="px-5 py-6">
                        <x-skeleton kind="row" :rows="4" />
                    </div>

                    <div data-fa-runs-error class="hidden px-5 py-6">
                        <div class="rounded-2xl border border-accent-coral/30 bg-accent-coral/5 px-4 py-4 text-center">
                            <div class="text-[13px] text-ink-700">{{ __('Could not load the execution history.') }}
                            </div>
                            <button type="button" data-fa-retry-fetch="runs"
                                class="mt-3 px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Try again') }}</button>
                        </div>
                    </div>

                    <div data-fa-runs-empty class="hidden px-5 py-6">
                        @include('user.partials.empty-state', [
                            'title' => __('No runs match these filters'),
                            'message' => __('Clear the search, widen the period, or pick another status.'),
                            'resetButtonAttrs' => 'data-fa-runs-reset',
                            'resetLabel' => __('Reset filters'),
                            'class' => 'border-0 shadow-none',
                        ])
                    </div>

                    <div class="overflow-x-auto hidden" data-fa-runs-wrap>
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 text-ink-500 border-b border-paper-200">
                                <tr>
                                    <th class="px-4 py-3 w-9"><input type="checkbox" data-fa-runs-all
                                            class="align-middle"
                                            title="{{ __('Select every failed run on this page') }}" /></th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Contact') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Flow') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Status') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Enrolled') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Finished') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Duration') }}</th>
                                    <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Retries') }}</th>
                                    <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                        {{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-paper-200" data-fa-runs-body></tbody>
                        </table>
                    </div>

                    <div data-fa-runs-pager
                        class="hidden items-center justify-between gap-3 px-4 py-3 border-t border-paper-200 text-[12px]">
                        <div class="text-ink-500" data-fa-runs-showing></div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" data-fa-runs-prev
                                class="px-3 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Prev') }}</button>
                            <span class="font-mono text-[11px] text-ink-600" data-fa-runs-page></span>
                            <button type="button" data-fa-runs-next
                                class="px-3 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Next') }}</button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ========== ERROR LOGS ========== --}}
            <section data-panel="errors" class="tab-panel hidden space-y-5">
                <div class="flex items-center gap-2 flex-wrap text-[11.5px]">
                    @foreach ([['failed', __('Failed runs')], ['distinct_reasons', __('Distinct reasons')], ['affected_flows', __('Flows affected')], ['affected_contacts', __('Contacts affected')], ['retried', __('Already retried')]] as [$k, $lbl])
                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 text-ink-600 shadow-card">
                            {{ $lbl }}
                            <b class="font-mono text-ink-900" data-fa-errors-total="{{ $k }}">—</b>
                        </span>
                    @endforeach
                </div>

                <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Error logs') }}</div>
                        <h2 class="font-serif text-[24px] leading-tight mt-1">{{ __('Grouped failure reasons') }}</h2>
                        <p class="text-[11.5px] text-ink-500 mt-1">
                            {{ __('Expand a reason to see which flows it hit, then re-run the affected contacts.') }}
                        </p>
                    </div>

                    <div data-fa-errors-loading class="px-5 py-6">
                        <x-skeleton kind="card" :rows="3" />
                    </div>

                    <div data-fa-errors-error class="hidden px-5 py-6">
                        <div class="rounded-2xl border border-accent-coral/30 bg-accent-coral/5 px-4 py-4 text-center">
                            <div class="text-[13px] text-ink-700">{{ __('Could not load the error logs.') }}</div>
                            <button type="button" data-fa-retry-fetch="errors"
                                class="mt-3 px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Try again') }}</button>
                        </div>
                    </div>

                    <div data-fa-errors-empty class="hidden px-5 py-6">
                        @include('user.partials.empty-state', [
                            'title' => __('No failures in this period'),
                            'message' => __('Nothing stopped with an error. Widen the period to look further back.'),
                            'class' => 'border-0 shadow-none',
                        ])
                    </div>

                    <div class="divide-y divide-paper-200 hidden" data-fa-errors-groups></div>
                </div>

                <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Raw failures') }}</div>
                        <h2 class="font-serif text-[24px] leading-tight mt-1">{{ __('Failed runs') }}</h2>
                    </div>

                    <div class="overflow-x-auto hidden" data-fa-errors-rows-wrap>
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 text-ink-500 border-b border-paper-200">
                                <tr>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                        {{ __('Contact') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Flow') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Failed at') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Reason') }}</th>
                                    <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Retries') }}</th>
                                    <th class="text-right font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                        {{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-paper-200" data-fa-errors-rows></tbody>
                        </table>
                    </div>

                    <div data-fa-errors-rows-empty class="hidden px-5 py-6">
                        @include('user.partials.empty-state', [
                            'title' => __('No failed runs to list'),
                            'message' => __('This period recorded no failures for the selected flow.'),
                            'class' => 'border-0 shadow-none',
                        ])
                    </div>

                    <div data-fa-errors-pager
                        class="hidden items-center justify-between gap-3 px-4 py-3 border-t border-paper-200 text-[12px]">
                        <div class="text-ink-500" data-fa-errors-showing></div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" data-fa-errors-prev
                                class="px-3 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Prev') }}</button>
                            <span class="font-mono text-[11px] text-ink-600" data-fa-errors-page></span>
                            <button type="button" data-fa-errors-next
                                class="px-3 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Next') }}</button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ========== RETRY RECORDS ========== --}}
            <section data-panel="retries" class="tab-panel hidden space-y-5">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl overflow-hidden shadow-card">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                            {{ __('Retry records') }}</div>
                        <h2 class="font-serif text-[24px] leading-tight mt-1">{{ __('Every retry, newest first') }}
                        </h2>
                        <p class="text-[11.5px] text-ink-500 mt-1">
                            {{ __('A retry puts the contact back into the flow from the start; the result is recorded here.') }}
                        </p>
                    </div>

                    <div class="px-5 py-3 border-b border-paper-200 flex items-center gap-2 flex-wrap">
                        @foreach ([['', __('All')], ['queued', __('Re-started')], ['succeeded', __('Completed')], ['failed', __('Failed again')]] as [$key, $label])
                            <button type="button" data-fa-retries-outcome="{{ $key }}"
                                class="fa-retries-chip px-3 py-1.5 rounded-full text-[12px] font-semibold transition {{ $key === '' ? 'bg-wa-deep text-paper-0' : 'text-ink-600 border border-paper-200 bg-paper-0 hover:bg-paper-50' }}">
                                {{ $label }}
                                <span class="ml-1 font-mono text-[11px]"
                                    data-fa-retries-count="{{ $key ?: 'all' }}">—</span>
                            </button>
                        @endforeach
                    </div>

                    <div data-fa-retries-loading class="px-5 py-6">
                        <x-skeleton kind="row" :rows="3" />
                    </div>

                    <div data-fa-retries-error class="hidden px-5 py-6">
                        <div class="rounded-2xl border border-accent-coral/30 bg-accent-coral/5 px-4 py-4 text-center">
                            <div class="text-[13px] text-ink-700">{{ __('Could not load the retry records.') }}</div>
                            <button type="button" data-fa-retry-fetch="retries"
                                class="mt-3 px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Try again') }}</button>
                        </div>
                    </div>

                    <div data-fa-retries-empty class="hidden px-5 py-6">
                        @include('user.partials.empty-state', [
                            'title' => __('No retries recorded'),
                            'message' => __(
                                'Retry a failed run from the execution history or the error logs and it will be listed here.'),
                            'class' => 'border-0 shadow-none',
                        ])
                    </div>

                    <div class="overflow-x-auto hidden" data-fa-retries-wrap>
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 text-ink-500 border-b border-paper-200">
                                <tr>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                        {{ __('When') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Run') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Flow') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Contact') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Triggered by') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-3 py-3">
                                        {{ __('Previous failure') }}</th>
                                    <th class="text-left font-mono text-[10px] uppercase tracking-[0.14em] px-4 py-3">
                                        {{ __('Outcome') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-paper-200" data-fa-retries-body></tbody>
                        </table>
                    </div>

                    <div data-fa-retries-pager
                        class="hidden items-center justify-between gap-3 px-4 py-3 border-t border-paper-200 text-[12px]">
                        <div class="text-ink-500" data-fa-retries-showing></div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" data-fa-retries-prev
                                class="px-3 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Prev') }}</button>
                            <span class="font-mono text-[11px] text-ink-600" data-fa-retries-page></span>
                            <button type="button" data-fa-retries-next
                                class="px-3 py-1 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Next') }}</button>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    </main>

    @if ($flowsTotal > 0)
        @push('scripts')
            <script>
                // Server-rendered first paint — the exact payload
                // /flows/analytics/data returns (FlowsController::buildAnalytics),
                // so the KPI band and charts are real before any fetch resolves.
                window.FLOW_ANALYTICS_DATA = @json($stats);
            </script>
        @endpush
    @endif
</x-layouts.user>
