/**
 * /social/posts — live status updates + AJAX actions for the unified posts grid.
 *  - Polls /social/posts/data every ~8s and updates each card's badge, time,
 *    error and the status-aware action buttons IN PLACE (a scheduled post the
 *    sweeper published, a TikTok that finished processing, a failed retry).
 *  - Publish / Republish / Stop / Cancel / Delete submit via fetch, then the
 *    grid refreshes from server truth — no full-page reload.
 */
export default function init() {
    const grid = document.querySelector('[data-post-grid]') || document.querySelector('main');
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    if (!document.querySelector('[data-post-card]') && !document.querySelector('[data-newpost]')) return;

    // ── New Post dropdown ──
    document.querySelectorAll('[data-newpost]').forEach((wrap) => {
        const btn = wrap.querySelector('button');
        const menu = wrap.querySelector('[data-newpost-menu]');
        if (!btn || !menu) return;
        btn.addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('hidden'); });
    });
    document.addEventListener('click', () => document.querySelectorAll('[data-newpost-menu]').forEach((m) => m.classList.add('hidden')));

    // ── Show/hide the status-aware buttons on a card for a given status ──
    function applyStatus(card, status) {
        card.dataset.postStatus = status;
        card.querySelectorAll('[data-when]').forEach((el) => {
            const list = (el.dataset.when || '').split(/\s+/).filter(Boolean);
            el.style.display = list.includes(status) ? '' : 'none';
        });
    }
    // Initial pass so server-rendered inline styles are consistent.
    document.querySelectorAll('[data-post-card]').forEach((c) => applyStatus(c, c.dataset.postStatus || 'draft'));

    // ── AJAX action submit (publish / republish / stop / cancel / delete) ──
    grid.addEventListener('submit', async (e) => {
        const form = e.target.closest('form[data-ajax-action]');
        if (!form) return;
        e.preventDefault();
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
        const card = form.closest('[data-post-card]');
        const btn = form.querySelector('button');
        if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = '…'; }
        try {
            await fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'manual',
            });
            const isDelete = form.querySelector('input[name="_method"][value="DELETE"]');
            if (isDelete && card) {
                // Optimistic remove for cancel/stop/delete; poll reconciles anyway.
                card.style.transition = 'opacity .2s'; card.style.opacity = '0';
                setTimeout(() => card.remove(), 200);
            }
            window.toast && window.toast(isDelete ? 'Removed' : 'Publishing…', 'success');
        } catch (err) {
            window.toast && window.toast('Action failed: ' + err.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'Retry'; }
        }
        setTimeout(poll, 1200); // reconcile from server truth
    });

    // ── Live poll ──
    const params = new URLSearchParams(window.location.search);
    const dataUrl = () => {
        const q = new URLSearchParams();
        if (params.get('status')) q.set('status', params.get('status'));
        if (params.get('platform')) q.set('platform', params.get('platform'));
        return '/social/posts/data' + (q.toString() ? '?' + q.toString() : '');
    };
    async function poll() {
        try {
            const r = await fetch(dataUrl(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) return;
            const data = await r.json();
            const map = {};
            (data.posts || []).forEach((p) => { map[p.uid] = p; });
            document.querySelectorAll('[data-post-card]').forEach((card) => {
                const p = map[card.dataset.postUid];
                if (!p) { return; } // filtered out / deleted — leave (a filter view); a hard remove is handled on action
                if (card.dataset.postStatus !== p.status) applyStatus(card, p.status);
                const badge = card.querySelector('[data-post-badge]');
                if (badge) { badge.className = 'inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-medium ' + p.status_class; badge.textContent = p.status_label; }
                const when = card.querySelector('[data-post-when]');
                if (when && p.when_label) when.textContent = p.when_label;
                const errEl = card.querySelector('[data-post-error]');
                if (errEl) { errEl.textContent = p.error || ''; errEl.style.display = p.error ? '' : 'none'; }
            });
            // Live filter counts.
            if (data.counts) {
                Object.entries(data.counts).forEach(([k, v]) => {
                    const el = document.querySelector(`[data-count="${k}"]`);
                    if (el) el.textContent = v;
                });
            }
        } catch (e) { /* transient — try again next tick */ }
    }
    setInterval(poll, 8000);
}
