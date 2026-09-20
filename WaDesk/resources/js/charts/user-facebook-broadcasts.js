/**
 * /facebook/broadcasts — recipient picker (per-page, from data-audience, already
 * filtered to Meta's 24h window server-side) + the browser-driven send loop
 * (POST /batch repeatedly until done — no queue). Progress updates in place.
 * Mirrors the Telegram broadcast driver.
 */
export default function init() {
    const root = document.getElementById('fb-broadcasts');
    if (!root) return;
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ── Recipient picker ──
    let AUD = {};
    try { AUD = JSON.parse(root.dataset.audience || '{}') || {}; } catch (e) { AUD = {}; }
    const pageSel = document.getElementById('fb-bcast-page');
    const list    = document.getElementById('fb-bcast-recipients');
    const countEl = document.getElementById('fb-bcast-count');
    const allBtn  = document.getElementById('fb-bcast-all');

    const recount = () => {
        if (!list || !countEl) return;
        countEl.textContent = String(list.querySelectorAll('input[type=checkbox]:checked').length);
    };
    const renderRecipients = () => {
        if (!list || !pageSel) return;
        const rows = AUD[pageSel.value] || [];
        if (!rows.length) {
            list.innerHTML = `<div class="px-3 py-6 text-center text-[12px] text-ink-500">No one is inside the 24-hour window right now — someone must message this Page first.</div>`;
            recount();
            return;
        }
        list.innerHTML = rows.map((c) => `
            <label class="flex items-center gap-2.5 px-3 py-2 hover:bg-paper-50 cursor-pointer">
                <input type="checkbox" name="psids[]" value="${String(c.psid)}" class="shrink-0">
                <span class="w-1.5 h-1.5 rounded-full bg-wa-green shrink-0"></span>
                <span class="min-w-0 flex-1 text-[12.5px] text-ink-800 truncate">${(c.title || 'Messenger user').replace(/</g, '&lt;')}</span>
            </label>`).join('');
        list.querySelectorAll('input[type=checkbox]').forEach((cb) => cb.addEventListener('change', recount));
        recount();
    };
    if (pageSel) pageSel.addEventListener('change', renderRecipients);
    if (allBtn) allBtn.addEventListener('click', () => {
        const boxes = list ? Array.from(list.querySelectorAll('input[type=checkbox]')) : [];
        const allOn = boxes.length && boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = !allOn; });
        recount();
    });
    renderRecipients();

    // ── Template picker — prefill the body + show a button preview ──
    const tplSel  = document.getElementById('fb-bcast-template');
    const bodyEl  = document.getElementById('fb-bcast-body');
    const btnPrev = document.getElementById('fb-bcast-btn-preview');
    if (tplSel) {
        let TEMPLATES = [];
        try { TEMPLATES = JSON.parse(tplSel.dataset.templates || '[]') || []; } catch (e) { TEMPLATES = []; }
        const byId = {}; TEMPLATES.forEach((t) => { byId[String(t.id)] = t; });
        const renderButtons = (btns) => {
            if (!btnPrev) return;
            btnPrev.innerHTML = (btns || []).map((b) => {
                const label = String(b.text || b.title || '').replace(/</g, '&lt;');
                const isUrl = String(b.type || '').toLowerCase() === 'url';
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] border ${isUrl ? 'border-[#1877F2]/40 text-[#1568D6] bg-[#EAF2FE]' : 'border-paper-300 text-ink-600 bg-paper-50'}">${isUrl ? '🔗' : '↩'} ${label}</span>`;
            }).join('');
        };
        tplSel.addEventListener('change', () => {
            const t = byId[tplSel.value];
            renderButtons(t ? t.buttons : []);
            if (t && bodyEl && bodyEl.value.trim() === '') { bodyEl.value = t.body || ''; }
        });
    }

    // ── Send loop ──
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
        set('[data-bcast-blocked]', d.blocked);
    }

    root.querySelectorAll('form[data-bcast-start]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const card = form.closest('[data-bcast]');
            const id = card?.dataset.bcast;
            const btn = form.querySelector('button');
            if (!id || !btn) return;
            btn.disabled = true;
            btn.textContent = 'Sending…';
            const statusEl = card.querySelector('[data-bcast-status]');
            if (statusEl) statusEl.textContent = 'Sending';

            await post(`/facebook/broadcasts/${id}/start`);
            for (let i = 0; i < 1000; i++) {
                const d = await post(`/facebook/broadcasts/${id}/batch`);
                updateCard(card, d);
                if (!d || d.ok === false || d.done) break;
                await new Promise((r) => setTimeout(r, 400)); // gentle pacing
            }
            btn.textContent = 'Done';
            if (statusEl) statusEl.textContent = 'Done';
        });
    });
}
