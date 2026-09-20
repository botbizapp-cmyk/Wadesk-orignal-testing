/**
 * /tiktok/posts/create — caption preview + counter, Video/Photo toggle, and
 * selected-filename display for the upload inputs.
 */
export default function init() {
    // Caption → preview + counter
    const cap = document.getElementById('tt-caption');
    const out = document.getElementById('tt-prev-caption');
    const cnt = document.getElementById('tt-cap-count');
    if (cap) {
        const empty = (out && out.textContent) || '';
        const sync = () => {
            const v = (cap.value || '').trim();
            if (out) out.textContent = v || empty;
            if (cnt) cnt.textContent = String((cap.value || '').length);
        };
        cap.addEventListener('input', sync);
        sync();
    }

    // Video / Photo toggle → set hidden post_type + show the right media block
    const typeInput = document.getElementById('tt-post-type');
    const tabs = Array.from(document.querySelectorAll('.tt-type-btn'));
    const blocks = {
        video: document.querySelector('[data-tt-media="video"]'),
        photo: document.querySelector('[data-tt-media="photo"]'),
    };
    const activeCls = ['border-ink-900', 'bg-ink-900/5', 'text-ink-900'];
    const idleCls = ['border-paper-200', 'text-ink-600'];
    const setType = (type) => {
        if (typeInput) typeInput.value = type;
        Object.entries(blocks).forEach(([k, el]) => { if (el) el.classList.toggle('hidden', k !== type); });
        tabs.forEach((b) => {
            const on = b.dataset.ttType === type;
            b.classList.remove(...activeCls, ...idleCls);
            b.classList.add(...(on ? activeCls : idleCls));
        });
    };
    tabs.forEach((b) => b.addEventListener('click', () => setType(b.dataset.ttType)));

    // Selected filename display
    const wire = (inputId, labelId, single) => {
        const input = document.getElementById(inputId);
        const label = document.getElementById(labelId);
        if (!input || !label) return;
        const base = label.textContent;
        input.addEventListener('change', () => {
            const n = input.files ? input.files.length : 0;
            if (!n) { label.textContent = base; return; }
            label.textContent = single
                ? input.files[0].name
                : (n === 1 ? input.files[0].name : `${n} ${'photos selected'}`);
        });
    };
    wire('tt-video', 'tt-video-label', true);
    wire('tt-photos', 'tt-photo-label', false);
}
