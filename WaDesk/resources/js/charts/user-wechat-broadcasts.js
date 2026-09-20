/**
 * /wechat/broadcasts/create — audience toggle (all / tag / pick-from-inbox) +
 * the recipient picker (from data-chats) + template body prefill. One-shot send;
 * no drain loop (WeChat mass-send is a single API call).
 */
export default function init() {
    const form = document.getElementById('wechat-broadcast');
    if (!form) return;

    let CHATS = {};
    try { CHATS = JSON.parse(form.dataset.chats || '{}') || {}; } catch (e) { CHATS = {}; }

    const chSel   = form.querySelector('select[name="wechat_channel_id"]');
    const tagEl   = document.getElementById('wc-tag');
    const listEl  = document.getElementById('wc-recipients');
    const countEl = document.getElementById('wc-recip-count');

    const recount = () => {
        const n = listEl ? listEl.querySelectorAll('input[type=checkbox]:checked').length : 0;
        if (countEl) countEl.querySelector('span').textContent = String(n);
    };
    const renderRecipients = () => {
        if (!listEl || !chSel) return;
        const rows = CHATS[chSel.value] || [];
        listEl.innerHTML = rows.length
            ? rows.map((c) => `
                <label class="flex items-center gap-2.5 px-3 py-2 hover:bg-paper-50 cursor-pointer">
                    <input type="checkbox" name="openids[]" value="${String(c.openid)}" class="shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#07C160] shrink-0"></span>
                    <span class="min-w-0 flex-1 text-[12.5px] text-ink-800 truncate">${(c.title || 'WeChat user').replace(/</g, '&lt;')}</span>
                </label>`).join('')
            : `<div class="px-3 py-6 text-center text-[12px] text-ink-500">No reachable users yet — someone must message this account first.</div>`;
        listEl.querySelectorAll('input[type=checkbox]').forEach((cb) => cb.addEventListener('change', recount));
        recount();
    };

    const applyAudience = () => {
        const v = (form.querySelector('input[name="audience"]:checked') || {}).value || 'all';
        if (tagEl) tagEl.classList.toggle('hidden', v !== 'tag');
        if (listEl) listEl.classList.toggle('hidden', v !== 'list');
        if (countEl) countEl.classList.toggle('hidden', v !== 'list');
        if (v === 'list') renderRecipients();
    };
    form.querySelectorAll('input[name="audience"]').forEach((r) => r.addEventListener('change', applyAudience));
    if (chSel) chSel.addEventListener('change', () => { if (!listEl.classList.contains('hidden')) renderRecipients(); });
    applyAudience();

    // Template → prefill body.
    const tplSel = document.getElementById('wc-template');
    const bodyEl = document.getElementById('wc-body');
    if (tplSel && bodyEl) {
        let TEMPLATES = [];
        try { TEMPLATES = JSON.parse(tplSel.dataset.templates || '[]') || []; } catch (e) { TEMPLATES = []; }
        const byId = {}; TEMPLATES.forEach((t) => { byId[String(t.id)] = t; });
        tplSel.addEventListener('change', () => {
            const t = byId[tplSel.value];
            if (t && bodyEl.value.trim() === '') bodyEl.value = t.body || '';
        });
    }
}
