/**
 * Messenger Setup screen (user.facebook.setup) — client behaviour for the
 * Messenger-profile editor:
 *   • Greeting live character counter (Meta caps it at 160).
 *   • Persistent-menu repeatable rows — add / remove, and per-row toggle
 *     between "send payload" (postback) and "open URL" (web_url) fields.
 *   • Ice-breaker repeatable rows — add / remove (Meta allows up to 4).
 *
 * Pure DOM wiring, no inline <script> in the blade. Delete/confirm dialogs are
 * handled globally by lib/ui-modal (data-confirm). Loaded via PAGE_INITIALIZERS
 * when <body data-page="user-facebook-setup">.
 */
export default function () {
    initTabs();
    initGreetingCounter();
    initRepeatable({
        list: '[data-fb-menu-list]',
        row: '[data-fb-menu-row]',
        add: '[data-fb-menu-add]',
        remove: '[data-fb-menu-remove]',
        max: 3,
        onClone: syncMenuRow,
    });
    initRepeatable({
        list: '[data-fb-ib-list]',
        row: '[data-fb-ib-row]',
        add: '[data-fb-ib-add]',
        remove: '[data-fb-ib-remove]',
        max: 4,
    });
    // Wire type-toggle on every existing (and future) menu row.
    document.querySelectorAll('[data-fb-menu-row]').forEach(syncMenuRow);
    document.addEventListener('change', (e) => {
        if (e.target && e.target.matches('[data-fb-menu-type]')) {
            const row = e.target.closest('[data-fb-menu-row]');
            if (row) toggleMenuFields(row);
        }
    });
}

/**
 * Feature tabs — one panel per Messenger-profile feature. The left-rail buttons
 * ([data-fb-tab]) toggle the matching [data-fb-panel]; the wrapper's
 * data-initial-tab picks which one opens first (the first configured feature,
 * else Get Started). Active-tab styling mirrors the flows-index left rail.
 */
function initTabs() {
    const root = document.querySelector('[data-fb-setup]');
    if (!root) return;
    const tabs = Array.from(root.querySelectorAll('[data-fb-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-fb-panel]'));
    if (!tabs.length || !panels.length) return;

    const activeCls = ['bg-wa-deep', 'text-paper-0', 'font-medium'];
    const idleCls = ['text-ink-700', 'hover:bg-paper-50'];

    const show = (key) => {
        panels.forEach((p) => { p.classList.toggle('hidden', p.getAttribute('data-fb-panel') !== key); });
        tabs.forEach((t) => {
            const on = t.getAttribute('data-fb-tab') === key;
            activeCls.forEach((c) => t.classList.toggle(c, on));
            idleCls.forEach((c) => t.classList.toggle(c, !on));
        });
    };

    tabs.forEach((t) => t.addEventListener('click', () => show(t.getAttribute('data-fb-tab'))));
    show(root.getAttribute('data-initial-tab') || tabs[0].getAttribute('data-fb-tab'));
}

/** Live "n/160" readout under the greeting textarea + preview-bubble sync. */
function initGreetingCounter() {
    const ta = document.querySelector('[data-fb-greeting]');
    const out = document.querySelector('[data-fb-greeting-count]');
    if (!ta) return;
    const preview = document.querySelector('[data-fb-greeting-preview]');
    const fallback = preview ? preview.textContent : '';
    const update = () => {
        const v = ta.value || '';
        if (out) out.textContent = String(v.length);
        if (preview) preview.textContent = v.trim() !== '' ? v : fallback;
    };
    ta.addEventListener('input', update);
    update();
}

/** Show the payload field for postback rows, the URL field for web_url rows. */
function toggleMenuFields(row) {
    const type = row.querySelector('[data-fb-menu-type]');
    const isUrl = type && type.value === 'web_url';
    const payload = row.querySelector('[data-fb-menu-payload]');
    const url = row.querySelector('[data-fb-menu-url]');
    if (payload) payload.hidden = isUrl;
    if (url) url.hidden = !isUrl;
}

/** Reset a freshly cloned menu row's fields and re-apply the type toggle. */
function syncMenuRow(row) {
    toggleMenuFields(row);
}

/**
 * Generic repeatable-row helper. Clones the first row as a template on "add",
 * clears its inputs, and enforces a max. "Remove" deletes the row, but the last
 * remaining row is cleared instead of removed so the group never collapses to
 * an empty (invisible) state.
 */
function initRepeatable({ list, row, add, remove, max, onClone }) {
    const listEl = document.querySelector(list);
    const addBtn = document.querySelector(add);
    if (!listEl || !addBtn) return;

    const clearInputs = (el) => {
        el.querySelectorAll('input, textarea').forEach((f) => { f.value = ''; });
        el.querySelectorAll('select').forEach((s) => { s.selectedIndex = 0; });
    };

    addBtn.addEventListener('click', () => {
        const rows = listEl.querySelectorAll(row);
        if (rows.length >= max) return;
        const clone = rows[0].cloneNode(true);
        clearInputs(clone);
        listEl.appendChild(clone);
        if (typeof onClone === 'function') onClone(clone);
        updateAddState();
    });

    listEl.addEventListener('click', (e) => {
        const btn = e.target.closest(remove);
        if (!btn) return;
        const rows = listEl.querySelectorAll(row);
        const target = btn.closest(row);
        if (!target) return;
        if (rows.length <= 1) {
            clearInputs(target);
            if (typeof onClone === 'function') onClone(target);
        } else {
            target.remove();
        }
        updateAddState();
    });

    function updateAddState() {
        const atMax = listEl.querySelectorAll(row).length >= max;
        addBtn.disabled = atMax;
        addBtn.classList.toggle('opacity-40', atMax);
        addBtn.classList.toggle('pointer-events-none', atMax);
    }

    updateAddState();
}
