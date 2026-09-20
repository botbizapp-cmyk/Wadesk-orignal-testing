/**
 * /social/calendar — interactive scheduler. Clicking a day "+" (or a day cell)
 * opens the right-side drawer pre-filled with that date; pick an account across
 * channels, compose caption + media, and Schedule (or Publish now). Submits to
 * /social/schedule via AJAX and reloads the grid on success. Mirrors the
 * Instaflow calendar drawer, generalized to Instagram / Facebook / TikTok.
 */
export default function init() {
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // New Post dropdown (toolbar).
    document.querySelectorAll('[data-newpost]').forEach((wrap) => {
        const btn = wrap.querySelector('button');
        const menu = wrap.querySelector('[data-newpost-menu]');
        if (btn && menu) btn.addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('hidden'); });
    });
    document.addEventListener('click', () => document.querySelectorAll('[data-newpost-menu]').forEach((m) => m.classList.add('hidden')));

    const drawer  = document.querySelector('[data-sched-drawer]');
    const overlay = document.querySelector('[data-sched-overlay]');
    if (!drawer) return;
    const form    = drawer.querySelector('[data-sched-form]');
    const dateEl  = drawer.querySelector('[data-sched-date]');
    const timeEl  = drawer.querySelector('[data-sched-time]');
    const nowEl   = drawer.querySelector('[data-sched-now]');
    const chEl    = drawer.querySelector('[data-sched-channel]');
    const acEl    = drawer.querySelector('[data-sched-account]');
    const errEl   = drawer.querySelector('[data-sched-error]');
    const submit  = drawer.querySelector('[data-sched-submit]');

    const open = (date) => {
        if (date && dateEl) dateEl.value = date;
        drawer.classList.remove('hidden');
        overlay.classList.remove('hidden');
        if (errEl) errEl.classList.add('hidden');
    };
    const close = () => { drawer.classList.add('hidden'); overlay.classList.add('hidden'); };

    // Open triggers: the day "+" button, and the empty area of a day cell.
    document.querySelectorAll('[data-schedule-at]').forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); open(b.dataset.scheduleAt); }));
    document.querySelectorAll('[data-day-cell]').forEach((cell) => cell.addEventListener('click', (e) => {
        if (e.target.closest('a') || e.target.closest('[data-schedule-at]')) return; // chips + the + button handle themselves
        open(cell.dataset.date);
    }));
    document.querySelectorAll('[data-sched-close]').forEach((b) => b.addEventListener('click', close));
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

    // Account radio → stash channel + id.
    form.querySelectorAll('input[name="acct"]').forEach((r) => r.addEventListener('change', () => {
        chEl.value = r.dataset.channel; acEl.value = r.dataset.account;
    }));

    // Image preview.
    const prev = drawer.querySelector('[data-sched-preview]');
    const prevImg = drawer.querySelector('[data-sched-preview-img]');
    form.querySelector('input[name="media"]')?.addEventListener('change', (e) => {
        const f = e.target.files && e.target.files[0];
        if (f && prev && prevImg) { prevImg.src = URL.createObjectURL(f); prev.classList.remove('hidden'); }
    });
    // Publish-now disables the date/time.
    nowEl?.addEventListener('change', () => { const on = nowEl.checked; if (dateEl) dateEl.disabled = on; if (timeEl) timeEl.disabled = on; });

    // Submit.
    submit.addEventListener('click', async () => {
        const showErr = (m) => { if (errEl) { errEl.textContent = m; errEl.classList.remove('hidden'); } };
        if (!chEl.value || !acEl.value) return showErr('Pick an account to post to.');
        if (!nowEl.checked && (!dateEl.value)) return showErr('Pick a date, or choose Publish now.');

        const fd = new FormData(form);
        fd.set('channel', chEl.value);
        fd.set('account_id', acEl.value);
        if (nowEl.checked) { fd.set('publish_now', '1'); }
        else { fd.set('scheduled_at', `${dateEl.value} ${timeEl.value || '10:00'}`); }

        submit.disabled = true; const lbl = submit.textContent; submit.textContent = 'Scheduling…';
        try {
            const r = await fetch('/social/schedule', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: fd, credentials: 'same-origin',
            });
            const data = await r.json().catch(() => ({}));
            if (r.ok && data.ok) {
                window.toast && window.toast(nowEl.checked ? 'Publishing…' : ('Scheduled for ' + (data.scheduled_at || '')), 'success');
                close();
                setTimeout(() => window.location.reload(), 600);
            } else {
                showErr(data.error || `Failed (HTTP ${r.status})`);
            }
        } catch (e) {
            showErr('Failed: ' + e.message);
        } finally {
            submit.disabled = false; submit.textContent = lbl;
        }
    });
}
