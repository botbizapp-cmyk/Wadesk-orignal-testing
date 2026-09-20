/**
 * /line/rich-menus/create — render the per-cell action inputs and a proportional
 * grid preview for the selected layout preset. Cells map 1:1 to the LINE areas[]
 * the controller builds from actions[i]. Also mirrors the chat-bar text.
 */
export default function init() {
    const form = document.getElementById('line-rich-menu');
    if (!form) return;

    let LAYOUTS = {};
    try { LAYOUTS = JSON.parse(form.dataset.layouts || '{}') || {}; } catch (e) { LAYOUTS = {}; }

    const layoutSel = document.getElementById('line-rm-layout');
    const cellsWrap = document.getElementById('line-rm-cells');
    const preview   = document.getElementById('line-rm-preview');
    const sizeHint  = document.getElementById('line-rm-size-hint');
    const barEl     = document.getElementById('line-rm-bar');
    const chatBar   = form.querySelector('input[name="chat_bar_text"]');

    const esc = (s) => String(s || '').replace(/"/g, '&quot;');

    const cellInput = (i) => `
        <div class="rounded-xl border border-paper-200 p-3 bg-paper-50/40">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-5 h-5 rounded-md bg-wa-deep text-white text-[10px] font-mono grid place-items-center">${i + 1}</span>
                <span class="text-[11.5px] font-semibold text-ink-700">Cell ${i + 1}</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-[110px_1fr] gap-2">
                <select name="actions[${i}][type]" class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                    <option value="message">Send text</option>
                    <option value="uri">Open link</option>
                    <option value="postback">Postback</option>
                </select>
                <input name="actions[${i}][value]" type="text" placeholder="Text to send / https://… / postback data" class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            </div>
            <input name="actions[${i}][label]" type="text" maxlength="20" placeholder="Label (optional, max 20)" class="mt-2 w-full rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
        </div>`;

    // Build a proportional CSS-grid preview from the cell bounds.
    const renderPreview = (layout) => {
        if (!preview) return;
        const W = layout.w, H = layout.h;
        preview.style.aspectRatio = `${W}/${H}`;
        preview.style.position = 'relative';
        preview.style.display = 'block';
        preview.innerHTML = (layout.cells || []).map((c, i) => `
            <div style="position:absolute;left:${(c.x / W) * 100}%;top:${(c.y / H) * 100}%;width:${(c.width / W) * 100}%;height:${(c.height / H) * 100}%;"
                 class="border border-[#06C755]/50 bg-[#06C755]/10 grid place-items-center">
                <span class="text-[13px] font-mono text-[#0B8043] font-semibold">${i + 1}</span>
            </div>`).join('');
    };

    const rebuild = () => {
        const key = layoutSel ? layoutSel.value : Object.keys(LAYOUTS)[0];
        const layout = LAYOUTS[key];
        if (!layout) return;
        if (cellsWrap) cellsWrap.innerHTML = layout.cells.map((_, i) => cellInput(i)).join('');
        renderPreview(layout);
        if (sizeHint) sizeHint.textContent = `PNG or JPEG, max 1MB. Must be exactly ${layout.w}×${layout.h}px.`;
    };

    if (layoutSel) layoutSel.addEventListener('change', rebuild);
    if (chatBar && barEl) {
        const sync = () => { barEl.textContent = chatBar.value.trim() || 'Menu'; };
        chatBar.addEventListener('input', sync);
        sync();
    }
    rebuild();
}
