/**
 * Companies index — new-company modal + create submit.
 * Loaded by app.js when <body data-page="user-companies-index">.
 */
export default function initCompaniesIndex() {
    const root = document.querySelector('[data-companies]');
    if (!root) return;

    const modal = root.parentElement.querySelector('[data-company-modal]')
        || document.querySelector('[data-company-modal]');
    const form = modal ? modal.querySelector('[data-company-form]') : null;
    const storeUrl = root.dataset.storeUrl;
    const csrf = root.dataset.csrf;

    function open() {
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        const first = modal.querySelector('input[name="name"]');
        if (first) first.focus();
    }
    function close() {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.querySelectorAll('[data-company-new]').forEach((b) => b.addEventListener('click', open));
    document.querySelectorAll('[data-company-close]').forEach((b) => b.addEventListener('click', close));
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) close();
        });
    }

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const payload = Object.fromEntries(new FormData(form).entries());
            if (!payload.name || !payload.name.trim()) return;
            if (btn) btn.disabled = true;
            try {
                const res = await fetch(storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (res.ok && data.id) {
                    window.location = '/companies/' + data.id;
                } else {
                    if (btn) btn.disabled = false;
                    alert((data && data.message) || 'Could not create the company.');
                }
            } catch (err) {
                if (btn) btn.disabled = false;
                alert('Network error — please try again.');
            }
        });
    }
}
