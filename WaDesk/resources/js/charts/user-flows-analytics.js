import ApexCharts from 'apexcharts';
import { themeColor } from '../theme-colors.js';

/**
 * /flows/analytics — flow execution history, error logs and retry records.
 *
 * Data model (all of it real, none of it synthesised):
 *   - window.FLOW_ANALYTICS_DATA is the server-rendered first paint, byte-for-byte
 *     the payload GET /flows/analytics/data returns (FlowsController::buildAnalytics).
 *     Same trick as window.WA_CAMPAIGN_DATA on the campaign detail page.
 *   - The period chips re-query /flows/analytics/data and repaint the KPI band,
 *     the charts and the per-flow leaderboard.
 *   - The three tables lazily fetch /flows/analytics/{runs,errors,retries} the
 *     first time their tab is opened, and again whenever a filter changes.
 *
 * A metric the controller could not derive comes back null; the matching tile is
 * hidden instead of being shown as a zero. An empty series shows the app's
 * empty-state block instead of an axis with no data.
 *
 * Changing the SCOPE (flow) navigates, because the page header, the builder link
 * and the server-seeded payload all belong to that scope. Changing the PERIOD
 * re-queries in place.
 */
export default function init() {
    const root = document.querySelector('[data-fa-root]');
    if (!root) return;

    // ---------------------------------------------------------------- helpers
    const T = (s) => (typeof window.t === 'function' ? window.t(s) : s);
    // Longest key first so ':total' is never eaten by ':to'.
    const fill = (s, vars) =>
        Object.keys(vars)
            .sort((a, b) => b.length - a.length)
            .reduce((acc, k) => acc.split(':' + k).join(String(vars[k])), s);

    const nf = new Intl.NumberFormat();
    const dtf = new Intl.DateTimeFormat(undefined, {
        year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
    });
    const df = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' });

    const int = (v) => nf.format(Number(v) || 0);
    const dt = (iso) => {
        if (!iso) return null;
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? null : dtf.format(d);
    };
    const day = (iso) => {
        if (!iso) return null;
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? null : df.format(d);
    };

    /** Seconds → "2 h 14 m". Mirrors $fmtDuration in the blade exactly. */
    function duration(seconds) {
        if (seconds === null || seconds === undefined) return null;
        const s = Math.max(0, Math.round(Number(seconds) || 0));
        if (s < 60) return fill(T(':n s'), { n: s });
        if (s < 3600) return fill(T(':n m'), { n: Math.floor(s / 60) });
        if (s < 86400) {
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            return m > 0 ? fill(T(':h h :m m'), { h, m }) : fill(T(':n h'), { n: h });
        }
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        return h > 0 ? fill(T(':d d :h h'), { d, h }) : fill(T(':n d'), { n: d });
    }

    const el = (tag, cls, text) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text; // never innerHTML for row data
        return n;
    };
    const $ = (sel) => root.querySelector(sel);
    const $$ = (sel) => Array.from(root.querySelectorAll(sel));
    const show = (node, on) => node && node.classList.toggle('hidden', !on);

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Same badge vocabulary the blade uses, so a status reads identically whether
    // it was server-rendered or painted here.
    const RUN_BADGE = {
        active: 'bg-wa-green/15 text-wa-deep border border-wa-green/30',
        paused: 'bg-[#EFE5F5] text-[#5B3D8A] border border-[#D9C7E8]',
        completed: 'bg-ink-900 text-paper-0',
        failed: 'bg-accent-coral/15 text-accent-coral border border-accent-coral/30',
    };
    const RUN_LABEL = { active: 'Active', paused: 'Paused', completed: 'Completed', failed: 'Failed' };

    const OUTCOME_BADGE = {
        queued: 'bg-paper-100 text-ink-700 border border-paper-200',
        succeeded: 'bg-wa-green/15 text-wa-deep border border-wa-green/30',
        failed: 'bg-accent-coral/15 text-accent-coral border border-accent-coral/30',
    };
    const OUTCOME_LABEL = { queued: 'Re-started', succeeded: 'Completed', failed: 'Failed again' };

    function badge(value, map, labels) {
        const key = String(value || '');
        const span = el(
            'span',
            'inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold ' +
                (map[key] || 'bg-paper-50 text-ink-700 border border-paper-200'),
            T(labels[key] || key || '—')
        );
        return span;
    }

    const flowLabel = (name, id) => (name ? name : T('Untitled flow') + ' #' + id);
    const noReason = () => T('No reason recorded');
    // Failure reasons are not always our own copy — a flow can store up to
    // 150 bytes of a bridge's raw HTTP response body. Table cells go through
    // el(), which is textContent-only, but an ApexCharts axis category is
    // written to the tooltip with innerHTML, so escape before charting.
    const ESC = { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' };
    const escapeLabel = (v) => String(v).replace(/[<>&"]/g, (ch) => ESC[ch]);

    // ------------------------------------------------------------------ state
    const urls = {
        base: root.dataset.urlBase,
        data: root.dataset.urlData,
        runs: root.dataset.urlRuns,
        errors: root.dataset.urlErrors,
        retries: root.dataset.urlRetries,
        retryBulk: root.dataset.urlRetryBulk,
    };
    const maxBatch = Math.max(1, parseInt(root.dataset.maxBatch || '50', 10));

    const state = {
        flowId: root.dataset.flowId ? parseInt(root.dataset.flowId, 10) : null,
        range: root.dataset.range || '30',
        tab: 'overview',
        runs: { status: '', q: '', from: '', to: '', page: 1 },
        errors: { page: 1 },
        retries: { outcome: '', page: 1 },
    };
    const loaded = { runs: false, errors: false, retries: false };
    const selected = new Set();

    function baseParams() {
        const p = new URLSearchParams();
        if (state.flowId) p.set('flow_id', String(state.flowId));
        p.set('range', state.range);
        return p;
    }

    /** Keep the URL shareable: scope + period only, on the canonical route path. */
    function syncUrl() {
        try {
            const path = new URL(urls.base, window.location.origin).pathname;
            const p = new URLSearchParams();
            if (state.flowId) p.set('flow_id', String(state.flowId));
            if (state.range !== '30') p.set('range', state.range);
            const qs = p.toString();
            history.replaceState(null, '', path + (qs ? '?' + qs : ''));
        } catch (e) {
            /* replaceState is cosmetic — never let it break the page. */
        }
    }

    /** Scope changes reload the page: header, builder link and seed all follow it. */
    function gotoFlow(flowId) {
        const p = new URLSearchParams();
        if (flowId) p.set('flow_id', String(flowId));
        if (state.range !== '30') p.set('range', state.range);
        const qs = p.toString();
        window.location = urls.base + (qs ? '?' + qs : '');
    }

    // ----------------------------------------------------------------- charts
    const baseFont = { fontFamily: 'Plus Jakarta Sans, system-ui, sans-serif' };
    const grid = { borderColor: themeColor('paper-100'), strokeDashArray: 4 };
    const axisLabel = { colors: themeColor('ink-500'), fontSize: '11px' };

    let trendChart = null;
    let statusChart = null;
    let failuresChart = null;

    function paintTrend(series) {
        const node = document.querySelector('#chart-fa-trend');
        const empty = $('[data-fa-trend-empty]');
        const cats = Array.isArray(series?.categories) ? series.categories : [];
        const has = cats.length > 0;
        show(node, has);
        show(empty, !has);
        if (!has) {
            if (trendChart) { trendChart.destroy(); trendChart = null; }
            return;
        }
        const opts = {
            chart: { type: 'area', height: 320, toolbar: { show: false }, ...baseFont },
            series: [
                { name: T('Enrolled'), data: series.enrolled || [] },
                { name: T('Completed'), data: series.completed || [] },
                { name: T('Failed'), data: series.failed || [] },
            ],
            colors: [themeColor('wa-deep'), themeColor('wa-green'), themeColor('accent-coral')],
            stroke: { curve: 'smooth', width: 3 },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.24, opacityTo: 0.02 } },
            dataLabels: { enabled: false },
            grid,
            xaxis: { categories: cats, labels: { style: axisLabel }, tickAmount: Math.min(10, cats.length) },
            yaxis: { labels: { style: axisLabel } },
            legend: { show: false },
            tooltip: { y: { formatter: (v) => int(v) } },
        };
        if (trendChart) trendChart.updateOptions(opts, false, true);
        else { trendChart = new ApexCharts(node, opts); trendChart.render(); }
    }

    function paintStatusMix(mix, runs) {
        const node = document.querySelector('#chart-fa-status');
        const empty = $('[data-fa-status-empty]');
        const has = Number(runs) > 0 && Array.isArray(mix?.series) && mix.series.some((v) => Number(v) > 0);
        show(node, has);
        show(empty, !has);
        if (!has) {
            if (statusChart) { statusChart.destroy(); statusChart = null; }
            return;
        }
        const labels = (mix.labels || []).map((k) => T(RUN_LABEL[k] || k));
        const opts = {
            chart: { type: 'donut', height: 300, ...baseFont },
            series: mix.series.map((v) => Number(v) || 0),
            labels,
            // Order is active / paused / completed / failed — "failed" keeps the
            // semantic coral, the rest follow the admin's theme tokens.
            colors: [themeColor('wa-green'), themeColor('accent-plum'), themeColor('wa-deep'), themeColor('accent-coral')],
            dataLabels: { enabled: false },
            legend: { position: 'bottom', labels: { colors: themeColor('ink-600') } },
            plotOptions: {
                pie: {
                    donut: {
                        size: '66%',
                        labels: {
                            show: true,
                            total: { show: true, label: T('Runs'), formatter: () => int(runs) },
                        },
                    },
                },
            },
        };
        if (statusChart) statusChart.updateOptions(opts, false, true);
        else { statusChart = new ApexCharts(node, opts); statusChart.render(); }
    }

    function paintFailures(failures) {
        const node = document.querySelector('#chart-fa-failures');
        const empty = $('[data-fa-failures-empty]');
        const btn = $('[data-fa-goto-errors]');
        const series = Array.isArray(failures?.series) ? failures.series : [];
        const has = series.length > 0;
        show(node, has);
        show(empty, !has);
        show(btn, has);
        if (!has) {
            if (failuresChart) { failuresChart.destroy(); failuresChart = null; }
            return;
        }
        // A null label means the run failed without a stored reason — say so
        // rather than printing an empty axis entry.
        const labels = (failures.labels || []).map((r) => (r ? escapeLabel(r) : noReason()));
        const opts = {
            chart: { type: 'bar', height: 300, toolbar: { show: false }, ...baseFont },
            series: [{ name: T('Failures'), data: series.map((v) => Number(v) || 0) }],
            colors: [themeColor('accent-coral')],
            plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '62%' } },
            dataLabels: { enabled: false },
            grid,
            xaxis: { categories: labels, labels: { style: axisLabel } },
            yaxis: { labels: { style: axisLabel, maxWidth: 190 } },
            tooltip: { y: { formatter: (v) => int(v) } },
        };
        if (failuresChart) failuresChart.updateOptions(opts, false, true);
        else { failuresChart = new ApexCharts(node, opts); failuresChart.render(); }
    }

    // ------------------------------------------------------------- KPI + board
    function paintStats(stats) {
        if (!stats) return;
        const totals = stats.totals || {};

        Object.entries(totals).forEach(([key, value]) => {
            const node = $(`[data-fa-kpi="${key}"]`);
            const tile = $(`[data-fa-tile="${key}"]`);
            if (!node) return;
            if (value === null || value === undefined) {
                // Not derivable from real rows → the tile disappears; it is never
                // padded out with a zero.
                if (tile) show(tile, false);
                return;
            }
            if (tile) show(tile, true);
            if (key === 'avg_complete_seconds') node.textContent = duration(value) || '';
            else if (key.endsWith('_rate')) node.textContent = Number(value).toFixed(1);
            else node.textContent = int(value);
        });

        // Window label — the real from/to the controller used.
        const win = $('[data-fa-window]');
        if (win) {
            const to = day(stats.to);
            const from = day(stats.from);
            win.textContent = from && to
                ? fill(T('Runs started :from – :to'), { from, to })
                : (to ? fill(T('All time up to :to'), { to }) : '');
        }

        const gen = $('[data-fa-generated]');
        if (gen && stats.generated_at) {
            gen.dataset.iso = stats.generated_at;
            gen.textContent = fill(T('Updated :time'), { time: dt(stats.generated_at) || '' });
        }

        paintTrend(stats.series);
        paintStatusMix(stats.status, totals.runs);
        paintFailures(stats.failures);
        paintBoard(stats.flows || []);
    }

    const STATE_PILL = {
        live: 'bg-wa-green/10 text-wa-deep border border-wa-green/30',
        paused: 'bg-[#EFE5F5] text-[#5B3D8A] border border-[#D9C7E8]',
        draft: 'bg-paper-50 text-ink-500 border border-paper-200',
    };
    const STATE_LABEL = { live: 'Live', paused: 'Paused', draft: 'Draft' };

    function paintBoard(rows) {
        const body = $('[data-fa-board]');
        const wrap = $('[data-fa-board-wrap]');
        const empty = $('[data-fa-board-empty]');
        if (!body) return;
        show(wrap, rows.length > 0);
        show(empty, rows.length < 1);
        body.replaceChildren();

        rows.forEach((r) => {
            const tr = el('tr', 'cursor-pointer hover:bg-paper-50' + (state.flowId === Number(r.flow_id) ? ' bg-wa-bubble/40' : ''));
            tr.dataset.faBoardRow = String(r.flow_id);

            const name = el('td', 'px-4 py-3');
            name.appendChild(el('div', 'font-semibold truncate', flowLabel(r.name, r.flow_id)));
            const pill = el(
                'span',
                'inline-flex items-center px-2 py-0.5 mt-1 rounded-full text-[10px] font-semibold ' +
                    (STATE_PILL[r.state] || STATE_PILL.draft),
                T(STATE_LABEL[r.state] || r.state || '')
            );
            name.appendChild(pill);
            tr.appendChild(name);

            tr.appendChild(el('td', 'px-3 py-3 text-right font-mono', int(r.runs)));
            tr.appendChild(el('td', 'px-3 py-3 text-right font-mono text-wa-deep', int(r.completed)));
            tr.appendChild(el(
                'td',
                'px-3 py-3 text-right font-mono ' + (Number(r.failed) > 0 ? 'text-accent-coral' : 'text-ink-500'),
                int(r.failed)
            ));
            tr.appendChild(el(
                'td',
                'px-4 py-3 text-right font-mono',
                r.completion_rate === null || r.completion_rate === undefined
                    ? '—'
                    : Number(r.completion_rate).toFixed(1) + '%'
            ));
            body.appendChild(tr);
        });
    }

    // ------------------------------------------------------------ fetch plumbing
    const controllers = {};
    async function getJson(key, url) {
        controllers[key]?.abort();
        controllers[key] = new AbortController();
        const res = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controllers[key].signal,
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json || json.ok !== true) throw new Error('bad payload');
        return json;
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body || {}),
        });
        let json = null;
        try { json = await res.json(); } catch (e) { /* non-JSON error page */ }
        return { ok: res.ok, json };
    }

    function paintPager(prefix, pg) {
        const pager = $(`[data-fa-${prefix}-pager]`);
        if (!pager || !pg) return;
        const many = Number(pg.last_page) > 1;
        pager.classList.toggle('hidden', !many);
        pager.classList.toggle('flex', many);
        const showing = $(`[data-fa-${prefix}-showing]`);
        if (showing) {
            showing.textContent = fill(T('Showing :from–:to of :total'), {
                from: int(pg.from || 0), to: int(pg.to || 0), total: int(pg.total || 0),
            });
        }
        const page = $(`[data-fa-${prefix}-page]`);
        if (page) page.textContent = `${pg.page} / ${pg.last_page}`;
        const prev = $(`[data-fa-${prefix}-prev]`);
        const next = $(`[data-fa-${prefix}-next]`);
        if (prev) prev.disabled = Number(pg.page) <= 1;
        if (next) next.disabled = !pg.has_more;
    }

    function setSectionState(prefix, which) {
        // which: 'loading' | 'error' | 'empty' | 'data'
        show($(`[data-fa-${prefix}-loading]`), which === 'loading');
        show($(`[data-fa-${prefix}-error]`), which === 'error');
        if (prefix === 'runs' || prefix === 'retries') {
            show($(`[data-fa-${prefix}-empty]`), which === 'empty');
            show($(`[data-fa-${prefix}-wrap]`), which === 'data');
            if (which !== 'data') {
                // Drop BOTH display utilities — leaving `flex` behind next to
                // `hidden` makes the winner depend on stylesheet order.
                $(`[data-fa-${prefix}-pager]`)?.classList.remove('flex');
                $(`[data-fa-${prefix}-pager]`)?.classList.add('hidden');
            }
        }
    }

    // ------------------------------------------------------- contact / flow cells
    function contactCell(row, cls) {
        const td = el('td', cls);
        const name = row.contact_name
            || (row.contact_id ? T('Contact') + ' #' + row.contact_id : T('No contact'));
        td.appendChild(el('div', 'font-semibold truncate', name));
        const sub = el('div', 'text-[10.5px] text-ink-500 font-mono');
        sub.textContent = (row.contact_phone ? row.contact_phone + ' · ' : '') + '#' + row.id;
        td.appendChild(sub);
        return td;
    }

    function flowCell(row, cls) {
        const td = el('td', cls);
        td.appendChild(el('div', 'truncate', flowLabel(row.flow_name, row.flow_id)));
        return td;
    }

    function retryButton(row) {
        const btn = el('button', 'px-3 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold', T('Retry'));
        btn.type = 'button';
        btn.dataset.faRetryOne = String(row.id);
        return btn;
    }

    function actionCell(row, cls) {
        const td = el('td', cls);
        if (row.can_retry) {
            td.appendChild(retryButton(row));
        } else if (row.status === 'failed') {
            // Real reason, straight from can_retry's definition (failed + flow active).
            const hint = el('span', 'text-[11px] text-ink-500', T('Flow inactive'));
            hint.title = T('Publish and activate the flow to retry this run.');
            td.appendChild(hint);
        } else {
            td.appendChild(el('span', 'text-ink-500', '—'));
        }
        return td;
    }

    // -------------------------------------------------------- execution history
    function runRow(row) {
        const tr = el('tr', 'align-top');

        const pick = el('td', 'px-4 py-3');
        if (row.can_retry) {
            const cb = el('input');
            cb.type = 'checkbox';
            cb.dataset.faPick = String(row.id);
            cb.checked = selected.has(row.id);
            pick.appendChild(cb);
        }
        tr.appendChild(pick);

        tr.appendChild(contactCell(row, 'px-3 py-3'));
        tr.appendChild(flowCell(row, 'px-3 py-3'));

        const st = el('td', 'px-3 py-3');
        st.appendChild(badge(row.status, RUN_BADGE, RUN_LABEL));
        if (row.status === 'failed') {
            const why = el('div', 'text-[11px] text-accent-coral mt-1 leading-snug', row.failure_reason || noReason());
            st.appendChild(why);
        }
        tr.appendChild(st);

        tr.appendChild(el('td', 'px-3 py-3 font-mono text-[11.5px]', dt(row.enrolled_at) || '—'));
        tr.appendChild(el(
            'td',
            'px-3 py-3 font-mono text-[11.5px] ' + (row.failed_at ? 'text-accent-coral' : ''),
            dt(row.completed_at) || dt(row.failed_at) || '—'
        ));
        tr.appendChild(el('td', 'px-3 py-3 font-mono text-[11.5px]', duration(row.duration_seconds) || '—'));

        const retries = el('td', 'px-3 py-3 text-right font-mono');
        retries.textContent = int(row.retry_count);
        if (row.last_retried_at) retries.title = fill(T('Last retried :time'), { time: dt(row.last_retried_at) });
        tr.appendChild(retries);

        tr.appendChild(actionCell(row, 'px-4 py-3 text-right'));
        return tr;
    }

    async function loadRuns() {
        setSectionState('runs', 'loading');
        const p = baseParams();
        if (state.runs.status) p.set('status', state.runs.status);
        if (state.runs.q) p.set('q', state.runs.q);
        if (state.runs.from) p.set('date_from', state.runs.from);
        if (state.runs.to) p.set('date_to', state.runs.to);
        if (state.runs.page > 1) p.set('page', String(state.runs.page));

        try {
            const json = await getJson('runs', urls.runs + '?' + p.toString());
            loaded.runs = true;

            const counts = json.counts || {};
            ['all', 'active', 'paused', 'completed', 'failed'].forEach((k) => {
                const node = $(`[data-fa-runs-count="${k}"]`);
                if (node) node.textContent = int(counts[k]);
            });

            const rows = json.runs || [];
            const body = $('[data-fa-runs-body]');
            if (!body) return;
            body.replaceChildren();
            rows.forEach((r) => body.appendChild(runRow(r)));

            setSectionState('runs', rows.length ? 'data' : 'empty');
            if (rows.length) paintPager('runs', json.pagination);
            syncSelection();
        } catch (e) {
            if (e.name === 'AbortError') return;
            setSectionState('runs', 'error');
        }
    }

    // ------------------------------------------------------------- error logs
    function errorRow(row) {
        const tr = el('tr', 'align-top');
        tr.appendChild(contactCell(row, 'px-4 py-3'));
        tr.appendChild(flowCell(row, 'px-3 py-3'));
        tr.appendChild(el('td', 'px-3 py-3 font-mono text-[11.5px]', dt(row.failed_at) || '—'));
        tr.appendChild(el('td', 'px-3 py-3 text-accent-coral leading-snug', row.failure_reason || noReason()));
        tr.appendChild(el('td', 'px-3 py-3 text-right font-mono', int(row.retry_count)));
        tr.appendChild(actionCell(row, 'px-4 py-3 text-right'));
        return tr;
    }

    function errorGroup(group, index) {
        const wrap = el('div', 'px-5 py-4');

        const head = el('button', 'w-full flex items-start gap-3 text-left');
        head.type = 'button';
        head.dataset.faGroupToggle = String(index);

        head.appendChild(el(
            'span',
            'shrink-0 mono font-mono text-[11px] px-2 py-0.5 rounded-full bg-accent-coral/15 text-accent-coral',
            int(group.count)
        ));

        const mid = el('div', 'min-w-0 flex-1');
        mid.appendChild(el('div', 'text-[13px] text-ink-900 font-semibold leading-snug', group.reason || noReason()));
        const seen = el('div', 'text-[11px] text-ink-500 mt-0.5');
        const first = dt(group.first_seen);
        const last = dt(group.last_seen);
        seen.textContent = first && last
            ? fill(T('First seen :first · last seen :last'), { first, last })
            : (last ? fill(T('Last seen :last'), { last }) : '');
        mid.appendChild(seen);
        head.appendChild(mid);

        const chev = el('span', 'shrink-0 text-ink-500 text-[11px] font-mono', '+');
        head.appendChild(chev);
        wrap.appendChild(head);

        const body = el('div', 'hidden mt-3 pl-9 space-y-3');
        body.dataset.faGroupBody = String(index);

        const flowsWrap = el('div', 'flex items-center gap-1.5 flex-wrap');
        (group.flows || []).forEach((f) => {
            const chip = el(
                'span',
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border border-paper-200 bg-paper-0 text-[11.5px] text-ink-600'
            );
            chip.appendChild(el('span', '', flowLabel(f.flow_name, f.flow_id)));
            chip.appendChild(el('b', 'font-mono text-ink-900', int(f.count)));
            flowsWrap.appendChild(chip);
        });
        body.appendChild(flowsWrap);

        const actions = el('div', 'flex items-center gap-2 flex-wrap');
        const ids = Array.isArray(group.subscriber_ids) ? group.subscriber_ids : [];
        if (ids.length) {
            const btn = el(
                'button',
                'px-3.5 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold',
                fill(T('Retry :n runs'), { n: ids.length })
            );
            btn.type = 'button';
            btn._faIds = ids; // kept off the DOM: an id list is data, not markup
            btn.dataset.faGroupRetry = String(index);
            actions.appendChild(btn);
        }
        if (group.reason) {
            const view = el(
                'button',
                'px-3.5 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-semibold',
                T('Show these runs')
            );
            view.type = 'button';
            view._faReason = group.reason;
            view.dataset.faGroupView = String(index);
            actions.appendChild(view);
        }
        body.appendChild(actions);
        wrap.appendChild(body);
        return wrap;
    }

    async function loadErrors() {
        setSectionState('errors', 'loading');
        show($('[data-fa-errors-groups]'), false);
        show($('[data-fa-errors-rows-wrap]'), false);
        show($('[data-fa-errors-rows-empty]'), false);
        show($('[data-fa-errors-empty]'), false);
        $('[data-fa-errors-pager]')?.classList.add('hidden');

        const p = baseParams();
        if (state.errors.page > 1) p.set('page', String(state.errors.page));

        try {
            const json = await getJson('errors', urls.errors + '?' + p.toString());
            loaded.errors = true;

            const totals = json.totals || {};
            Object.entries(totals).forEach(([k, v]) => {
                const node = $(`[data-fa-errors-total="${k}"]`);
                if (node) node.textContent = int(v);
            });

            const groups = json.groups || [];
            const groupsWrap = $('[data-fa-errors-groups]');
            if (!groupsWrap) return;
            groupsWrap.replaceChildren();
            groups.forEach((g, i) => groupsWrap.appendChild(errorGroup(g, i)));

            show($('[data-fa-errors-loading]'), false);
            show($('[data-fa-errors-error]'), false);
            show(groupsWrap, groups.length > 0);
            show($('[data-fa-errors-empty]'), groups.length < 1);

            const rows = json.rows || [];
            const body = $('[data-fa-errors-rows]');
            if (!body) return;
            body.replaceChildren();
            rows.forEach((r) => body.appendChild(errorRow(r)));
            show($('[data-fa-errors-rows-wrap]'), rows.length > 0);
            show($('[data-fa-errors-rows-empty]'), rows.length < 1);
            if (rows.length) paintPager('errors', json.pagination);
        } catch (e) {
            if (e.name === 'AbortError') return;
            show($('[data-fa-errors-loading]'), false);
            show($('[data-fa-errors-error]'), true);
        }
    }

    // ---------------------------------------------------------- retry records
    function retryRow(row) {
        const tr = el('tr', 'align-top');
        tr.appendChild(el('td', 'px-4 py-3 font-mono text-[11.5px]', dt(row.created_at) || '—'));
        tr.appendChild(el('td', 'px-3 py-3 font-mono text-[11.5px]', '#' + row.flow_subscriber_id));
        tr.appendChild(el('td', 'px-3 py-3 truncate', flowLabel(row.flow_name, row.flow_id)));

        const contact = el('td', 'px-3 py-3');
        contact.appendChild(el(
            'div',
            'truncate',
            row.contact_name || (row.contact_id ? T('Contact') + ' #' + row.contact_id : T('No contact'))
        ));
        if (row.contact_phone) contact.appendChild(el('div', 'text-[10.5px] text-ink-500 font-mono', row.contact_phone));
        tr.appendChild(contact);

        tr.appendChild(el('td', 'px-3 py-3', row.retried_by || (row.source === 'system' ? T('System') : T('Unknown'))));
        tr.appendChild(el('td', 'px-3 py-3 text-ink-600 leading-snug', row.previous_failure_reason || noReason()));

        const outcome = el('td', 'px-4 py-3');
        outcome.appendChild(badge(row.outcome, OUTCOME_BADGE, OUTCOME_LABEL));
        if (row.outcome_reason) outcome.appendChild(el('div', 'text-[11px] text-ink-500 mt-1 leading-snug', row.outcome_reason));
        tr.appendChild(outcome);
        return tr;
    }

    async function loadRetries() {
        setSectionState('retries', 'loading');
        const p = baseParams();
        if (state.retries.outcome) p.set('outcome', state.retries.outcome);
        if (state.retries.page > 1) p.set('page', String(state.retries.page));

        try {
            const json = await getJson('retries', urls.retries + '?' + p.toString());
            loaded.retries = true;

            const totals = json.totals || {};
            ['all', 'queued', 'succeeded', 'failed'].forEach((k) => {
                const node = $(`[data-fa-retries-count="${k}"]`);
                if (node) node.textContent = int(totals[k]);
            });

            const rows = json.retries || [];
            const body = $('[data-fa-retries-body]');
            if (!body) return;
            body.replaceChildren();
            rows.forEach((r) => body.appendChild(retryRow(r)));

            setSectionState('retries', rows.length ? 'data' : 'empty');
            if (rows.length) paintPager('retries', json.pagination);
        } catch (e) {
            if (e.name === 'AbortError') return;
            setSectionState('retries', 'error');
        }
    }

    // -------------------------------------------------------------- KPI reload
    async function loadStats() {
        try {
            const json = await getJson('data', urls.data + '?' + baseParams().toString());
            paintStats(json);
        } catch (e) {
            if (e.name === 'AbortError') return;
            window.toast?.(T('Could not refresh the figures.'), 'error');
        }
    }

    // ------------------------------------------------------------------- tabs
    function showTab(name) {
        state.tab = name;
        $$('.tab-panel').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.panel !== name));
        $$('.tab-btn').forEach((btn) => {
            const active = btn.dataset.tab === name;
            btn.classList.toggle('bg-wa-deep', active);
            btn.classList.toggle('text-paper-0', active);
            btn.classList.toggle('text-ink-600', !active);
            btn.classList.toggle('hover:bg-paper-50', !active);
        });
        window.dispatchEvent(new Event('resize')); // let Apex re-measure a revealed chart
        ensureTab(name);
    }

    function ensureTab(name) {
        if (name === 'history' && !loaded.runs) loadRuns();
        if (name === 'errors' && !loaded.errors) loadErrors();
        if (name === 'retries' && !loaded.retries) loadRetries();
    }

    /** A filter changed: every table is stale, so drop the cache and reload the
     *  one on screen. The others refetch when their tab is next opened. */
    function invalidateTables() {
        loaded.runs = loaded.errors = loaded.retries = false;
        selected.clear();
        syncSelection();
        ensureTab(state.tab);
    }

    // -------------------------------------------------------------- selection
    function syncSelection() {
        const bar = $('[data-fa-bulk-bar]');
        const count = $('[data-fa-bulk-count]');
        if (count) count.textContent = String(selected.size);
        if (bar) {
            bar.classList.toggle('hidden', selected.size < 1);
            bar.classList.toggle('flex', selected.size > 0);
        }
        const all = $('[data-fa-runs-all]');
        if (all) {
            const boxes = $$('[data-fa-pick]');
            all.checked = boxes.length > 0 && boxes.every((cb) => cb.checked);
            all.indeterminate = selected.size > 0 && !all.checked;
        }
    }

    // ----------------------------------------------------------------- retries
    async function afterRetry() {
        // Retrying rewrites run status, retry counters and the retry log, so the
        // KPI band and every table are refetched rather than patched.
        await loadStats();
        invalidateTables();
    }

    async function retryOne(id, btn) {
        const ok = await (window.uiConfirm
            ? window.uiConfirm({
                title: T('Retry this run?'),
                message: T('The contact re-enters the flow from the start and receives its messages again.'),
                confirmText: T('Retry'),
            })
            : Promise.resolve(true));
        if (!ok) return;

        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        const { ok: httpOk, json } = await postJson(`${urls.runs}/${id}/retry`, {});
        if (btn) { btn.disabled = false; btn.classList.remove('opacity-50'); }

        if (!json) {
            window.toast?.(T('Network error.'), 'error');
            return;
        }
        window.toast?.(json.message || (httpOk ? T('Retry started.') : T('Retry failed.')), json.ok ? 'success' : 'error');
        if (json.ok) afterRetry();
    }

    async function retryBulk(ids, btn) {
        const list = Array.from(new Set(ids.map((n) => Number(n)).filter(Boolean)));
        if (!list.length) {
            window.toast?.(T('Select at least one failed run first.'), 'error');
            return;
        }
        let batch = list;
        if (batch.length > maxBatch) {
            batch = batch.slice(0, maxBatch);
            window.toast?.(fill(T('Only the first :n runs will be retried.'), { n: maxBatch }), 'info');
        }

        const ok = await (window.uiConfirm
            ? window.uiConfirm({
                title: fill(T('Retry :n failed runs?'), { n: batch.length }),
                message: fill(
                    T(':n contacts re-enter their flow from the start and receive its messages again. This cannot be undone.'),
                    { n: batch.length }
                ),
                confirmText: T('Retry'),
            })
            : Promise.resolve(true));
        if (!ok) return;

        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        const { json } = await postJson(urls.retryBulk, { ids: batch });
        if (btn) { btn.disabled = false; btn.classList.remove('opacity-50'); }

        if (!json) {
            window.toast?.(T('Network error.'), 'error');
            return;
        }
        window.toast?.(json.message || T('Retry finished.'), json.ok ? 'success' : 'error');
        if (json.ok) afterRetry();
    }

    // ------------------------------------------------------------- event wiring
    $$('.tab-btn').forEach((btn) => btn.addEventListener('click', () => showTab(btn.dataset.tab)));

    $$('[data-fa-range]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const value = btn.dataset.faRange;
            if (value === state.range) return;
            state.range = value;
            $$('[data-fa-range]').forEach((b) => {
                const on = b.dataset.faRange === value;
                b.classList.toggle('bg-wa-deep', on);
                b.classList.toggle('text-paper-0', on);
                b.classList.toggle('text-ink-600', !on);
                b.classList.toggle('border', !on);
                b.classList.toggle('border-paper-200', !on);
                b.classList.toggle('bg-paper-0', !on);
                b.classList.toggle('hover:bg-paper-50', !on);
            });
            state.runs.page = 1;
            state.errors.page = 1;
            state.retries.page = 1;
            syncUrl();
            loadStats();
            invalidateTables();
        });
    });

    $('[data-fa-flow]')?.addEventListener('change', (e) => {
        const value = e.target.value ? parseInt(e.target.value, 10) : null;
        gotoFlow(value);
    });

    $('[data-fa-board]')?.addEventListener('click', (e) => {
        const tr = e.target.closest('[data-fa-board-row]');
        if (!tr) return;
        const id = parseInt(tr.dataset.faBoardRow, 10);
        gotoFlow(state.flowId === id ? null : id); // clicking the active row clears the scope
    });

    $('[data-fa-refresh]')?.addEventListener('click', () => {
        loadStats();
        invalidateTables();
    });

    $('[data-fa-goto-errors]')?.addEventListener('click', () => showTab('errors'));

    // ---- execution-history filters
    $$('[data-fa-runs-status]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const value = btn.dataset.faRunsStatus;
            if (value === state.runs.status) return;
            state.runs.status = value;
            state.runs.page = 1;
            $$('[data-fa-runs-status]').forEach((b) => {
                const on = b.dataset.faRunsStatus === value;
                b.classList.toggle('bg-wa-deep', on);
                b.classList.toggle('text-paper-0', on);
                b.classList.toggle('text-ink-600', !on);
                b.classList.toggle('border', !on);
                b.classList.toggle('border-paper-200', !on);
                b.classList.toggle('bg-paper-0', !on);
                b.classList.toggle('hover:bg-paper-50', !on);
            });
            selected.clear();
            loadRuns();
        });
    });

    let searchTimer = null;
    $('[data-fa-runs-search]')?.addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        const value = e.target.value.trim();
        searchTimer = setTimeout(() => {
            state.runs.q = value;
            state.runs.page = 1;
            selected.clear();
            loadRuns();
        }, 350);
    });

    ['from', 'to'].forEach((key) => {
        $(`[data-fa-runs-${key}]`)?.addEventListener('change', (e) => {
            state.runs[key] = e.target.value || '';
            state.runs.page = 1;
            selected.clear();
            loadRuns();
        });
    });

    $('[data-fa-runs-reset]')?.addEventListener('click', () => {
        state.runs = { status: '', q: '', from: '', to: '', page: 1 };
        const search = $('[data-fa-runs-search]');
        if (search) search.value = '';
        ['from', 'to'].forEach((k) => {
            const node = $(`[data-fa-runs-${k}]`);
            if (node) node.value = '';
        });
        $$('[data-fa-runs-status]').forEach((b) => {
            const on = b.dataset.faRunsStatus === '';
            b.classList.toggle('bg-wa-deep', on);
            b.classList.toggle('text-paper-0', on);
            b.classList.toggle('text-ink-600', !on);
            b.classList.toggle('border', !on);
            b.classList.toggle('border-paper-200', !on);
            b.classList.toggle('bg-paper-0', !on);
            b.classList.toggle('hover:bg-paper-50', !on);
        });
        selected.clear();
        loadRuns();
    });

    $('[data-fa-runs-prev]')?.addEventListener('click', () => {
        if (state.runs.page <= 1) return;
        state.runs.page -= 1;
        loadRuns();
    });
    $('[data-fa-runs-next]')?.addEventListener('click', () => {
        state.runs.page += 1;
        loadRuns();
    });

    // ---- selection + row actions inside the runs table
    $('[data-fa-runs-body]')?.addEventListener('change', (e) => {
        const cb = e.target.closest('[data-fa-pick]');
        if (!cb) return;
        const id = Number(cb.dataset.faPick);
        if (cb.checked) {
            if (selected.size >= maxBatch) {
                cb.checked = false;
                window.toast?.(fill(T('Up to :n runs can be retried at once.'), { n: maxBatch }), 'error');
                return;
            }
            selected.add(id);
        } else {
            selected.delete(id);
        }
        syncSelection();
    });

    $('[data-fa-runs-all]')?.addEventListener('change', (e) => {
        const boxes = $$('[data-fa-pick]');
        if (e.target.checked) {
            let truncated = false;
            boxes.forEach((cb) => {
                const id = Number(cb.dataset.faPick);
                if (selected.size >= maxBatch && !selected.has(id)) { cb.checked = false; truncated = true; return; }
                cb.checked = true;
                selected.add(id);
            });
            if (truncated) window.toast?.(fill(T('Up to :n runs can be retried at once.'), { n: maxBatch }), 'info');
        } else {
            boxes.forEach((cb) => { cb.checked = false; selected.delete(Number(cb.dataset.faPick)); });
        }
        syncSelection();
    });

    $('[data-fa-bulk-clear]')?.addEventListener('click', () => {
        selected.clear();
        $$('[data-fa-pick]').forEach((cb) => { cb.checked = false; });
        syncSelection();
    });

    $('[data-fa-bulk-retry]')?.addEventListener('click', (e) => retryBulk(Array.from(selected), e.currentTarget));

    // Single-run retry — same handler for the history table and the raw failures
    // table in the error logs.
    root.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-fa-retry-one]');
        if (!btn) return;
        retryOne(Number(btn.dataset.faRetryOne), btn);
    });

    // ---- error-log groups
    $('[data-fa-errors-groups]')?.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-fa-group-toggle]');
        if (toggle) {
            const body = $(`[data-fa-group-body="${toggle.dataset.faGroupToggle}"]`);
            if (body) {
                const collapsed = body.classList.toggle('hidden'); // true → just closed
                const chev = toggle.lastElementChild;
                if (chev) chev.textContent = collapsed ? '+' : '−';
            }
            return;
        }
        const retryBtn = e.target.closest('[data-fa-group-retry]');
        if (retryBtn) {
            retryBulk(retryBtn._faIds || [], retryBtn);
            return;
        }
        const viewBtn = e.target.closest('[data-fa-group-view]');
        if (viewBtn) {
            // Reuse the execution-history table rather than duplicating a list:
            // the reason is matched server-side against failure_reason.
            state.runs = { status: 'failed', q: viewBtn._faReason || '', from: '', to: '', page: 1 };
            const search = $('[data-fa-runs-search]');
            if (search) search.value = state.runs.q;
            $$('[data-fa-runs-status]').forEach((b) => {
                const on = b.dataset.faRunsStatus === 'failed';
                b.classList.toggle('bg-wa-deep', on);
                b.classList.toggle('text-paper-0', on);
                b.classList.toggle('text-ink-600', !on);
                b.classList.toggle('border', !on);
                b.classList.toggle('border-paper-200', !on);
                b.classList.toggle('bg-paper-0', !on);
                b.classList.toggle('hover:bg-paper-50', !on);
            });
            loaded.runs = false;
            showTab('history');
        }
    });

    $('[data-fa-errors-prev]')?.addEventListener('click', () => {
        if (state.errors.page <= 1) return;
        state.errors.page -= 1;
        loadErrors();
    });
    $('[data-fa-errors-next]')?.addEventListener('click', () => {
        state.errors.page += 1;
        loadErrors();
    });

    // ---- retry records
    $$('[data-fa-retries-outcome]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const value = btn.dataset.faRetriesOutcome;
            if (value === state.retries.outcome) return;
            state.retries.outcome = value;
            state.retries.page = 1;
            $$('[data-fa-retries-outcome]').forEach((b) => {
                const on = b.dataset.faRetriesOutcome === value;
                b.classList.toggle('bg-wa-deep', on);
                b.classList.toggle('text-paper-0', on);
                b.classList.toggle('text-ink-600', !on);
                b.classList.toggle('border', !on);
                b.classList.toggle('border-paper-200', !on);
                b.classList.toggle('bg-paper-0', !on);
                b.classList.toggle('hover:bg-paper-50', !on);
            });
            loadRetries();
        });
    });

    $('[data-fa-retries-prev]')?.addEventListener('click', () => {
        if (state.retries.page <= 1) return;
        state.retries.page -= 1;
        loadRetries();
    });
    $('[data-fa-retries-next]')?.addEventListener('click', () => {
        state.retries.page += 1;
        loadRetries();
    });

    // ---- failed-fetch recovery: every section offers a real retry affordance
    $$('[data-fa-retry-fetch]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const which = btn.dataset.faRetryFetch;
            if (which === 'runs') loadRuns();
            if (which === 'errors') loadErrors();
            if (which === 'retries') loadRetries();
        });
    });

    // -------------------------------------------------------------- first paint
    paintStats(window.FLOW_ANALYTICS_DATA || null);
    showTab('overview');
}
