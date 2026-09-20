/**
 * Booking Type wizard — 3 steps (Service / Availability / Messaging), styled
 * like /wa-campaigns/create. Handles step nav, repeatable rows (intervals /
 * reminders / questions), a live financial total, and a live slot preview that
 * hits the stateless draft-body endpoint. Everything posts as array-named
 * inputs in one normal form submit — the controller reads those shapes.
 */
export default function init() {
    const form = document.getElementById('bt-wizard-form');
    if (!form) return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ── Step navigation (mirrors the campaigns wizard) ──
    const panes = form.querySelectorAll('.step-pane');
    const nodes = form.querySelectorAll('.step-node');
    const prev = document.getElementById('prevBtn');
    const next = document.getElementById('nextBtn');
    const submit = document.getElementById('submitBtn');
    const total = panes.length || 3;
    let current = 1;

    const add = (el, cls) => el && el.classList.add(...cls);
    const rm = (el, cls) => el && el.classList.remove(...cls);

    const setStep = (n) => {
        current = Math.max(1, Math.min(total, n));
        panes.forEach((p) => p.classList.toggle('hidden', Number(p.dataset.step) !== current));
        nodes.forEach((node) => {
            const number = Number(node.dataset.n);
            const dot = node.querySelector('.dot');
            const lab = node.querySelector('.lab');
            const bar = node.querySelector('.bar');
            rm(dot, ['bg-paper-0', 'bg-wa-deep', 'border-paper-200', 'border-wa-deep', 'text-ink-500', 'text-wa-deep', 'text-paper-0', 'ring-4', 'ring-wa-deep/10']);
            rm(lab, ['text-ink-500', 'text-wa-deep', 'font-medium', 'font-semibold']);
            if (number < current) {
                add(dot, ['bg-wa-deep', 'border-wa-deep', 'text-paper-0']);
                add(lab, ['text-wa-deep', 'font-semibold']);
                if (bar) { bar.classList.remove('bg-paper-200'); bar.classList.add('bg-wa-deep'); }
            } else if (number === current) {
                add(dot, ['bg-paper-0', 'border-wa-deep', 'text-wa-deep', 'ring-4', 'ring-wa-deep/10']);
                add(lab, ['text-wa-deep', 'font-semibold']);
                if (bar) { bar.classList.remove('bg-wa-deep'); bar.classList.add('bg-paper-200'); }
            } else {
                add(dot, ['bg-paper-0', 'border-paper-200', 'text-ink-500']);
                add(lab, ['text-ink-500', 'font-medium']);
                if (bar) { bar.classList.remove('bg-wa-deep'); bar.classList.add('bg-paper-200'); }
            }
        });
        if (prev) prev.disabled = current === 1;
        if (next) next.classList.toggle('hidden', current === total);
        if (submit) submit.classList.toggle('hidden', current !== total);
        // Refresh the slot preview when the Availability pane becomes active
        // (marked with data-refresh-preview) — the preview output itself lives
        // in the persistent right rail, not inside the pane.
        const activePane = form.querySelector(`.step-pane[data-step="${current}"]`);
        if (activePane && activePane.hasAttribute('data-refresh-preview')) refreshPreview();
    };

    const validateStep = (n) => {
        // Step 1 — Service: name is required.
        if (n === 1) {
            const name = form.querySelector('[name="name"]');
            if (name && !name.value.trim()) { name.focus(); flash(name); return false; }
        }
        // Step 2 — Timing: duration must be positive.
        if (n === 2) {
            const dur = form.querySelector('[name="duration_minutes"]');
            if (dur && !(Number(dur.value) > 0)) { dur.focus(); flash(dur); return false; }
        }
        return true;
    };
    const flash = (el) => { el.classList.add('border-accent-coral'); setTimeout(() => el.classList.remove('border-accent-coral'), 1200); };

    if (next) next.addEventListener('click', () => { if (validateStep(current)) setStep(current + 1); });
    if (prev) prev.addEventListener('click', () => setStep(current - 1));
    nodes.forEach((node) => node.addEventListener('click', () => {
        const target = Number(node.dataset.n);
        if (target <= current || validateStep(current)) setStep(target);
    }));

    // ── Repeatable rows from <template> ──
    const tpl = (name) => form.querySelector(`template[data-tpl="${name}"]`);

    // Availability intervals
    form.querySelectorAll('[data-add-interval]').forEach((btn) => {
        btn.addEventListener('click', () => addInterval(Number(btn.dataset.addInterval)));
    });
    function addInterval(wd, from = '09:00', to = '17:00') {
        const holder = form.querySelector(`[data-intervals="${wd}"]`);
        const node = tpl('interval').content.firstElementChild.cloneNode(true);
        const idx = Date.now() + Math.floor(Math.random() * 1000);
        const f = node.querySelector('[data-name-from]');
        const t = node.querySelector('[data-name-to]');
        f.name = `availability[${wd}][${idx}][from]`; f.value = from;
        t.name = `availability[${wd}][${idx}][to]`; t.value = to;
        holder.appendChild(node);
    }

    // Reminders
    const remBtn = form.querySelector('[data-add-reminder]');
    if (remBtn) remBtn.addEventListener('click', () => addReminder());
    function addReminder(min = '', label = '') {
        const holder = form.querySelector('[data-reminders]');
        if (holder.querySelectorAll('[data-reminder]').length >= 8) return;
        const node = tpl('reminder').content.firstElementChild.cloneNode(true);
        const i = holder.querySelectorAll('[data-reminder]').length;
        const m = node.querySelector('[data-name-min]');
        const l = node.querySelector('[data-name-label]');
        m.name = `reminders[${i}][offset_minutes]`; m.value = min;
        l.name = `reminders[${i}][label]`; l.value = label;
        holder.appendChild(node);
    }

    // Questions
    const qBtn = form.querySelector('[data-add-question]');
    if (qBtn) qBtn.addEventListener('click', () => addQuestion());
    function addQuestion() {
        const holder = form.querySelector('[data-questions]');
        const node = tpl('question').content.firstElementChild.cloneNode(true);
        const i = holder.querySelectorAll('[data-question]').length;
        node.querySelector('[data-name-label]').name = `questions[${i}][label]`;
        node.querySelector('[data-name-type]').name = `questions[${i}][type]`;
        node.querySelector('[data-name-map]').name = `questions[${i}][map_to_contact_field]`;
        node.querySelector('[data-name-req]').name = `questions[${i}][required]`;
        holder.appendChild(node);
    }

    // Row removal (delegated)
    form.addEventListener('click', (e) => {
        const rmBtn = e.target.closest('[data-remove-interval],[data-remove-reminder],[data-remove-question]');
        if (rmBtn) {
            const row = rmBtn.closest('[data-interval],[data-reminder],[data-question]');
            if (row) row.remove();
        }
    });

    // ── Live financial total ──
    const money = (minor, cur) => `${cur} ${(Number(minor || 0) / 100).toFixed(2)}`;
    function computeTotal() {
        const fee = Number((form.querySelector('[data-fee]') || {}).value || 0);
        const tax = Number((form.querySelector('[data-tax]') || {}).value || 0);
        const mode = (form.querySelector('[data-deposit-mode]') || {}).value || 'none';
        const dep = Number((form.querySelector('[data-deposit]') || {}).value || 0);
        const cur = (form.querySelector('[name="currency"]') || {}).value || '';
        const taxMinor = Math.round(fee * tax / 100);
        const sum = fee + taxMinor;
        const due = mode === 'full' ? sum : (mode === 'partial' ? dep : 0);
        const set = (sel, v) => { const el = form.querySelector(sel); if (el) el.textContent = v; };
        set('[data-total-fee]', money(fee, cur));
        set('[data-total-tax]', money(taxMinor, cur));
        set('[data-total-sum]', money(sum, cur));
        set('[data-total-due]', money(due, cur));
        // Reflect the chosen currency everywhere it is echoed (Fee label + rail badge).
        form.querySelectorAll('[data-cur-label]').forEach((el) => { el.textContent = cur; });
        const badge = form.querySelector('[data-sum-currency]');
        if (badge) badge.textContent = cur;
        // Show/hide deposit amount field
        const dv = form.querySelector('[data-deposit-value]');
        if (dv) dv.classList.toggle('hidden', mode !== 'partial');
    }
    ['[data-fee]', '[data-tax]', '[data-deposit]', '[data-deposit-mode]', '[name="currency"]'].forEach((sel) => {
        const el = form.querySelector(sel);
        if (el) el.addEventListener('input', computeTotal);
        if (el && el.tagName === 'SELECT') el.addEventListener('change', computeTotal);
    });
    computeTotal();

    // ── Live summary rail (name / duration / location) ──
    const locLabels = { address: 'In person', virtual: 'Virtual', phone: 'Phone call' };
    function updateSummary() {
        const set = (sel, v) => { const el = form.querySelector(sel); if (el) el.textContent = v; };
        const nameEl = form.querySelector('[name="name"]');
        set('[data-sum-name]', (nameEl && nameEl.value.trim()) || 'New service');
        const dur = (form.querySelector('[name="duration_minutes"]') || {}).value || '30';
        set('[data-sum-duration]', dur);
        const loc = (form.querySelector('[name="location_type"]') || {}).value || 'address';
        set('[data-sum-location]', locLabels[loc] || loc);
    }
    ['[name="name"]', '[name="duration_minutes"]', '[name="location_type"]'].forEach((sel) => {
        const el = form.querySelector(sel);
        if (el) { el.addEventListener('input', updateSummary); el.addEventListener('change', updateSummary); }
    });
    updateSummary();

    // ── Live slot preview (stateless draft body) ──
    const previewBtn = form.querySelector('[data-preview-btn]');
    const previewOut = form.querySelector('[data-preview-out]');
    if (previewBtn) previewBtn.addEventListener('click', refreshPreview);

    function collectAvailability() {
        const avail = {};
        form.querySelectorAll('[data-intervals]').forEach((holder) => {
            const wd = holder.dataset.intervals;
            holder.querySelectorAll('[data-interval]').forEach((row) => {
                const ins = row.querySelectorAll('input[type="time"]');
                const from = ins[0] ? ins[0].value : '';
                const to = ins[1] ? ins[1].value : '';
                if (from && to) { (avail[wd] = avail[wd] || []).push({ from, to }); }
            });
        });
        return avail;
    }
    async function refreshPreview() {
        if (!previewOut) return;
        const draft = {
            timezone: (form.querySelector('[name="timezone"]') || {}).value || '',
            duration_minutes: Number((form.querySelector('[name="duration_minutes"]') || {}).value || 30),
            increment_minutes: Number((form.querySelector('[name="increment_minutes"]') || {}).value || 0),
            buffer_before_minutes: Number((form.querySelector('[name="buffer_before_minutes"]') || {}).value || 0),
            buffer_after_minutes: Number((form.querySelector('[name="buffer_after_minutes"]') || {}).value || 0),
            min_notice_minutes: Number((form.querySelector('[name="min_notice_minutes"]') || {}).value || 0),
            max_advance_days: Number((form.querySelector('[name="max_advance_days"]') || {}).value || 7),
            max_per_day: Number((form.querySelector('[name="max_per_day"]') || {}).value || 0),
            availability: collectAvailability(),
        };
        previewOut.textContent = 'Loading…';
        try {
            const res = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ draft, days: 7 }),
            });
            const data = await res.json().catch(() => ({}));
            if (!data.ok || !(data.slots || []).length) {
                previewOut.innerHTML = '<span class="text-ink-400">No bookable times for this configuration in the next 7 days.</span>';
                return;
            }
            const byDay = {};
            data.slots.forEach((s) => {
                const d = new Date(s.start);
                const key = d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
                (byDay[key] = byDay[key] || []).push(d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }));
            });
            previewOut.innerHTML = Object.entries(byDay).slice(0, 5).map(([day, times]) =>
                `<div class="mb-2"><div class="text-[11px] font-semibold text-ink-700">${day}</div>` +
                `<div class="flex flex-wrap gap-1 mt-1">${times.slice(0, 10).map((t) => `<span class="px-2 py-0.5 rounded-full bg-wa-mint text-wa-deep text-[10.5px]">${t}</span>`).join('')}${times.length > 10 ? `<span class="text-[10.5px] text-ink-400">+${times.length - 10}</span>` : ''}</div></div>`
            ).join('') + `<div class="text-[10.5px] text-ink-400 mt-1">${data.count} slots · times in ${data.tz}</div>`;
        } catch (e) {
            previewOut.innerHTML = '<span class="text-accent-coral">Preview failed.</span>';
        }
    }

    setStep(1);
}
