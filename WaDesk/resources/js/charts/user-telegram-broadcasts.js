/**
 * /telegram/broadcasts — recipient picker (per-bot, from data-chats) + the
 * browser-driven send loop (POST /batch repeatedly until done — no queue, the
 * same model the app uses everywhere). Progress updates in place.
 */
export default function init() {
    const root = document.getElementById('tg-broadcasts');
    if (!root) return;
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ── Recipient picker ──
    let CHATS = {};
    try { CHATS = JSON.parse(root.dataset.chats || '{}') || {}; } catch (e) { CHATS = {}; }
    const botSel = document.getElementById('tg-bcast-bot');
    const list   = document.getElementById('tg-bcast-recipients');
    const countEl = document.getElementById('tg-bcast-count');
    const allBtn = document.getElementById('tg-bcast-all');

    const kindDot = (t) => t === 'private' ? 'bg-wa-green' : 'bg-accent-amber';
    const recount = () => {
        if (!list || !countEl) return;
        countEl.textContent = String(list.querySelectorAll('input[type=checkbox]:checked').length);
    };
    const renderRecipients = () => {
        if (!list || !botSel) return;
        const rows = CHATS[botSel.value] || [];
        if (!rows.length) {
            list.innerHTML = `<div class="px-3 py-6 text-center text-[12px] text-ink-500">No reachable chats yet — someone must message this bot first.</div>`;
            recount();
            return;
        }
        list.innerHTML = rows.map((c) => `
            <label class="flex items-center gap-2.5 px-3 py-2 hover:bg-paper-50 cursor-pointer">
                <input type="checkbox" name="chat_ids[]" value="${String(c.chat_id)}" class="shrink-0">
                <span class="w-1.5 h-1.5 rounded-full ${kindDot(c.type)} shrink-0"></span>
                <span class="min-w-0 flex-1 text-[12.5px] text-ink-800 truncate">${(c.title || 'Telegram chat').replace(/</g, '&lt;')}</span>
                <span class="text-[10px] text-ink-400 font-mono">${c.type}</span>
            </label>`).join('');
        list.querySelectorAll('input[type=checkbox]').forEach((cb) => cb.addEventListener('change', recount));
        recount();
    };
    if (botSel) botSel.addEventListener('change', renderRecipients);
    if (allBtn) allBtn.addEventListener('click', () => {
        const boxes = list ? Array.from(list.querySelectorAll('input[type=checkbox]')) : [];
        const allOn = boxes.length && boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = !allOn; });
        recount();
    });
    renderRecipients();

    // ── Template picker — prefill the body + show a button preview ──
    const tplSel  = document.getElementById('tg-bcast-template');
    const bodyEl  = document.getElementById('tg-bcast-body');
    const btnPrev = document.getElementById('tg-bcast-btn-preview');
    if (tplSel) {
        let TEMPLATES = [];
        try { TEMPLATES = JSON.parse(tplSel.dataset.templates || '[]') || []; } catch (e) { TEMPLATES = []; }
        const byId = {}; TEMPLATES.forEach((t) => { byId[String(t.id)] = t; });
        const renderButtons = (btns) => {
            if (!btnPrev) return;
            btnPrev.innerHTML = (btns || []).map((b) => {
                const label = String(b.text || b.title || '').replace(/</g, '&lt;');
                const isUrl = String(b.type || '').toLowerCase() === 'url';
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] border ${isUrl ? 'border-[#229ED9]/40 text-[#1B7CB0] bg-[#EAF6FC]' : 'border-paper-300 text-ink-600 bg-paper-50'}">${isUrl ? '🔗' : '↩'} ${label}</span>`;
            }).join('');
        };
        tplSel.addEventListener('change', () => {
            const t = byId[tplSel.value];
            renderButtons(t ? t.buttons : []);
            if (t && bodyEl && bodyEl.value.trim() === '') { bodyEl.value = t.body || ''; }
        });
    }

    // ── Live preview (create page) — mirror the body + buttons into a chat bubble ──
    const prevBody = document.getElementById('tg-prev-body');
    const prevBtns = document.getElementById('tg-prev-buttons');
    const prevMedia = document.getElementById('tg-prev-media');
    if (prevBody && bodyEl) {
        const nameEl = document.getElementById('tg-bcast-name');
        const paint = () => {
            const txt = (bodyEl.value || '').replace(/\{name\}/g, 'there').trim();
            prevBody.textContent = txt || 'Your message preview…';
            prevBody.classList.toggle('text-ink-400', !txt);
        };
        bodyEl.addEventListener('input', paint);
        // Reflect template button preview into the bubble too.
        if (prevBtns && btnPrev) {
            const sync = () => { prevBtns.innerHTML = btnPrev.innerHTML; };
            const mo = new MutationObserver(sync);
            mo.observe(btnPrev, { childList: true, subtree: true });
            sync();
        }
        // Media file → show a small chip in the bubble.
        const mediaInput = document.querySelector('input[name="media"]');
        if (mediaInput && prevMedia) {
            mediaInput.addEventListener('change', () => {
                const f = mediaInput.files && mediaInput.files[0];
                prevMedia.textContent = f ? `📎 ${f.name}` : '';
                prevMedia.classList.toggle('hidden', !f);
            });
        }
        if (nameEl) nameEl.addEventListener('input', () => {});
        paint();
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

            await post(`/telegram/broadcasts/${id}/start`);
            // Loop batches until Telegram has nothing pending.
            for (let i = 0; i < 1000; i++) {
                const d = await post(`/telegram/broadcasts/${id}/batch`);
                updateCard(card, d);
                if (!d || d.ok === false || d.done) break;
                await new Promise((r) => setTimeout(r, 400)); // gentle pacing
            }
            btn.textContent = 'Done';
            if (statusEl) statusEl.textContent = 'Done';
        });
    });
}
