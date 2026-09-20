/**
 * /wechat/menu — the custom-menu builder. Renders 3 top-button editors (each can
 * be a link / keyword / mini-program leaf, or a submenu with up to 5 sub-items),
 * pre-fills from the account's current menu, and serialises the whole structure
 * into the hidden `menu` JSON field on submit. Pushed to WeChat by the controller.
 */
export default function init() {
    const form = document.getElementById('wechat-menu');
    if (!form) return;
    const wrap = document.getElementById('wc-menu-tops');
    const jsonEl = document.getElementById('wc-menu-json');
    if (!wrap || !jsonEl) return;

    let CURRENT = [];
    try { CURRENT = JSON.parse(form.dataset.current || '[]') || []; } catch (e) { CURRENT = []; }

    const esc = (s) => String(s || '').replace(/"/g, '&quot;');
    const leafFields = (prefix, b) => {
        const type = String(b.type || 'click');
        const val = b.type === 'view' ? (b.url || '') : (b.key || b.value || '');
        return `
            <select data-f="type" class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[11.5px] focus:outline-none focus:border-wa-deep">
                <option value="click" ${type === 'click' ? 'selected' : ''}>Keyword (fires event)</option>
                <option value="view" ${type === 'view' ? 'selected' : ''}>Link (open URL)</option>
                <option value="miniprogram" ${type === 'miniprogram' ? 'selected' : ''}>Mini-program</option>
            </select>
            <input data-f="value" value="${esc(val)}" placeholder="Keyword or https://…" class="mt-1 w-full rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[11.5px] font-mono focus:outline-none focus:border-wa-deep">`;
    };

    const subRow = (s) => `
        <div class="flex gap-1.5" data-mb-sub>
            <input data-f="name" value="${esc(s.name || '')}" placeholder="Sub name" class="w-24 rounded-lg border border-paper-200 bg-paper-0 px-2 py-1 text-[11px] focus:outline-none focus:border-wa-deep">
            <select data-f="type" class="rounded-lg border border-paper-200 bg-paper-0 px-1.5 py-1 text-[11px] focus:outline-none focus:border-wa-deep">
                <option value="click" ${s.type !== 'view' ? 'selected' : ''}>Kw</option>
                <option value="view" ${s.type === 'view' ? 'selected' : ''}>Link</option>
            </select>
            <input data-f="value" value="${esc(s.type === 'view' ? (s.url || '') : (s.key || s.value || ''))}" placeholder="value" class="flex-1 min-w-0 rounded-lg border border-paper-200 bg-paper-0 px-2 py-1 text-[11px] font-mono focus:outline-none focus:border-wa-deep">
        </div>`;

    const topCard = (i, b) => {
        const isParent = Array.isArray(b.sub_button) && b.sub_button.length > 0;
        const subs = isParent ? b.sub_button : [];
        while (subs.length < 5) subs.push({});
        return `
        <div class="rounded-xl border border-paper-200 p-3 bg-paper-50/40" data-mb-top>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-5 h-5 rounded-md bg-wa-deep text-white text-[10px] font-mono grid place-items-center">${i + 1}</span>
                <input data-f="name" value="${esc(b.name || '')}" placeholder="Button ${i + 1}" class="flex-1 min-w-0 rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] font-semibold focus:outline-none focus:border-wa-deep">
            </div>
            <label class="flex items-center gap-1.5 text-[11px] text-ink-600 mb-1.5"><input type="checkbox" data-f="submenu" ${isParent ? 'checked' : ''}> ${'Has sub-items'}</label>
            <div data-mb-leaf class="${isParent ? 'hidden' : ''}">${leafFields('top', b)}</div>
            <div data-mb-subs class="${isParent ? '' : 'hidden'} space-y-1.5 mt-1">${subs.slice(0, 5).map(subRow).join('')}</div>
        </div>`;
    };

    // Render 3 top slots (pre-filled from the current menu).
    const tops = [];
    for (let i = 0; i < 3; i++) tops.push(topCard(i, CURRENT[i] || {}));
    wrap.innerHTML = tops.join('');

    // Toggle leaf vs submenu per card.
    wrap.querySelectorAll('[data-mb-top]').forEach((card) => {
        const cb = card.querySelector('[data-f="submenu"]');
        const leaf = card.querySelector('[data-mb-leaf]');
        const subs = card.querySelector('[data-mb-subs]');
        cb?.addEventListener('change', () => {
            leaf.classList.toggle('hidden', cb.checked);
            subs.classList.toggle('hidden', !cb.checked);
        });
    });

    const readLeaf = (el) => {
        const type = el.querySelector('[data-f="type"]')?.value || 'click';
        const val = (el.querySelector('[data-f="value"]')?.value || '').trim();
        return type === 'view' ? { type, value: val } : { type, value: val };
    };

    form.addEventListener('submit', () => {
        const out = [];
        wrap.querySelectorAll('[data-mb-top]').forEach((card) => {
            const name = (card.querySelector('[data-f="name"]')?.value || '').trim();
            if (!name) return;
            const isSub = card.querySelector('[data-f="submenu"]')?.checked;
            if (isSub) {
                const sub = [];
                card.querySelectorAll('[data-mb-sub]').forEach((sr) => {
                    const sn = (sr.querySelector('[data-f="name"]')?.value || '').trim();
                    if (!sn) return;
                    const leaf = readLeaf(sr);
                    sub.push({ name: sn, type: leaf.type, value: leaf.value });
                });
                out.push({ name, sub });
            } else {
                const leaf = readLeaf(card.querySelector('[data-mb-leaf]'));
                out.push({ name, type: leaf.type, value: leaf.value });
            }
        });
        jsonEl.value = JSON.stringify(out);
    });
}
