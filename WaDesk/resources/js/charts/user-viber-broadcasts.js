/**
 * /viber/broadcasts — recipient picker (per-channel, from data-chats) + the
 * browser-driven send loop (POST /batch until done — each batch is one Viber
 * broadcast_message call for ≤300 ids). Mirrors user-line-broadcasts.js.
 */
export default function init() {
    const root = document.getElementById('viber-broadcasts');
    if (!root) return;
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ── Recipient picker (create page) ──
    let CHATS = {};
    try { CHATS = JSON.parse(root.dataset.chats || '{}') || {}; } catch (e) { CHATS = {}; }
    const chSel = document.getElementById('viber-bcast-channel');
    const list = document.getElementById('viber-bcast-recipients');
    const countEl = document.getElementById('viber-bcast-count');
    const allBtn = document.getElementById('viber-bcast-all');

    const recount = () => { if (list && countEl) countEl.textContent = String(list.querySelectorAll('input[type=checkbox]:checked').length); };
    const render = () => {
        if (!list || !chSel) return;
        const rows = CHATS[chSel.value] || [];
        list.innerHTML = rows.length
            ? rows.map((c) => `
                <label class="flex items-center gap-2.5 px-3 py-2 hover:bg-paper-50 cursor-pointer">
                    <input type="checkbox" name="user_ids[]" value="${String(c.user_id)}" class="shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#7360F2] shrink-0"></span>
                    <span class="min-w-0 flex-1 text-[12.5px] text-ink-800 truncate">${(c.title || 'Viber user').replace(/</g, '&lt;')}</span>
                </label>`).join('')
            : `<div class="px-3 py-6 text-center text-[12px] text-ink-500">No reachable users yet — someone must open a chat with this account first.</div>`;
        list.querySelectorAll('input[type=checkbox]').forEach((cb) => cb.addEventListener('change', recount));
        recount();
    };
    if (chSel) chSel.addEventListener('change', render);
    if (allBtn) allBtn.addEventListener('click', () => {
        const boxes = list ? Array.from(list.querySelectorAll('input[type=checkbox]')) : [];
        const allOn = boxes.length && boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = !allOn; });
        recount();
    });
    render();

    // ── Template prefill ──
    const tplSel = document.getElementById('viber-bcast-template');
    const bodyEl = document.getElementById('viber-bcast-body');
    if (tplSel && bodyEl) {
        let TEMPLATES = [];
        try { TEMPLATES = JSON.parse(tplSel.dataset.templates || '[]') || []; } catch (e) { TEMPLATES = []; }
        const byId = {}; TEMPLATES.forEach((t) => { byId[String(t.id)] = t; });
        tplSel.addEventListener('change', () => { const t = byId[tplSel.value]; if (t && bodyEl.value.trim() === '') bodyEl.value = t.body || ''; });
    }

    // ── Send loop (list page) ──
    async function post(url) {
        const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        return r.ok ? r.json().catch(() => ({})) : {};
    }
    function updateCard(card, d) {
        const set = (sel, v) => { const el = card.querySelector(sel); if (el && v != null) el.textContent = String(v); };
        const bar = card.querySelector('[data-bcast-bar]');
        if (bar && d.progress != null) bar.style.width = `${d.progress}%`;
        set('[data-bcast-sent]', d.sent);
        set('[data-bcast-failed]', d.failed);
    }
    root.querySelectorAll('form[data-bcast-start]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const card = form.closest('[data-bcast]');
            const id = card?.dataset.bcast;
            const btn = form.querySelector('button');
            if (!id || !btn) return;
            btn.disabled = true; btn.textContent = 'Sending…';
            const statusEl = card.querySelector('[data-bcast-status]');
            if (statusEl) statusEl.textContent = 'Sending';
            await post(`/viber/broadcasts/${id}/start`);
            for (let i = 0; i < 1000; i++) {
                const d = await post(`/viber/broadcasts/${id}/batch`);
                updateCard(card, d);
                if (!d || d.ok === false || d.done) break;
                await new Promise((r) => setTimeout(r, 500));
            }
            btn.textContent = 'Done';
            if (statusEl) statusEl.textContent = 'Done';
        });
    });
}
