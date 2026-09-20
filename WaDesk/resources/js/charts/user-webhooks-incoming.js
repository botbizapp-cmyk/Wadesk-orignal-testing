/**
 * /webhooks/incoming — page interactions:
 *  1) copy-to-clipboard for each generated webhook URL (data-copy="<input-id>").
 *  2) "Send template" config: render one payload-field input per {{n}} slot of
 *     the picked template, and reveal the header-media field only for
 *     media-header templates. Everything else is plain HTML forms.
 */
export default function init() {
  // 1) Copy buttons.
  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const el = document.getElementById(btn.dataset.copy);
      if (!el) return;
      const value = el.value || el.textContent || '';
      const done = () => {
        const orig = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = orig; }, 1200);
      };
      if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(value).then(done).catch(() => { el.select?.(); });
      } else {
        el.select?.();
        try { document.execCommand('copy'); done(); } catch (_) { /* noop */ }
      }
    });
  });

  // 2) Template-send variable mapper.
  document.querySelectorAll('[data-tpl-form]').forEach((form) => {
    const select = form.querySelector('[data-tpl-select]');
    const varsBox = form.querySelector('[data-tpl-vars]');
    const headerWrap = form.querySelector('[data-tpl-header-wrap]');
    if (!select || !varsBox) return;

    let saved = {};
    try { saved = JSON.parse(varsBox.dataset.saved || '{}') || {}; } catch (_) { saved = {}; }

    const render = () => {
      const opt = select.options[select.selectedIndex];
      const count = Math.max(0, parseInt(opt?.dataset.vars || '0', 10) || 0);
      const hasHeader = (opt?.dataset.header || '0') === '1';

      // Preserve anything already typed before rebuilding the inputs.
      const current = {};
      varsBox.querySelectorAll('input[data-slot]').forEach((inp) => { current[inp.dataset.slot] = inp.value; });

      varsBox.innerHTML = '';
      for (let i = 1; i <= count; i++) {
        const row = document.createElement('div');
        row.innerHTML =
          '<span class="text-[10px] font-mono uppercase tracking-wide text-ink-500">Variable {{' + i + '}} → field</span>' +
          '<input type="text" name="tpl_var[' + i + ']" data-slot="' + i + '" placeholder="e.g. order.id" ' +
          'class="mt-0.5 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[12px] font-mono focus:outline-none focus:border-wa-deep">';
        // Set the value via property (not innerHTML) so a saved value can't break the markup.
        row.querySelector('input').value = current[i] ?? saved[i] ?? '';
        varsBox.appendChild(row);
      }
      if (headerWrap) headerWrap.classList.toggle('hidden', !hasHeader);
    };

    select.addEventListener('change', render);
    render(); // initial paint for the pre-selected template
  });
}
