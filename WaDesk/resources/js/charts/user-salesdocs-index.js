// AI-CRM Phase 7 — Proposals/Estimates: modal + dynamic line-item rows + live totals.
export default function salesDocsIndex() {
  const modal = document.getElementById('sd-modal');
  const rowsWrap = document.getElementById('sd-rows');
  if (!modal || !rowsWrap) return;

  const sym = rowsWrap.dataset.currency || '$';
  const exp = parseInt(rowsWrap.dataset.exp || '2', 10);

  const open = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
  const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
  document.getElementById('sd-open')?.addEventListener('click', open);
  document.getElementById('sd-close')?.addEventListener('click', close);
  document.getElementById('sd-cancel')?.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

  const money = (n) => sym + (n || 0).toLocaleString(undefined, { minimumFractionDigits: exp, maximumFractionDigits: exp });

  let rowIdx = 0;

  function addRow(desc = '', qty = 1, price = '') {
    const i = rowIdx++;
    const row = document.createElement('div');
    row.className = 'grid grid-cols-12 gap-2 items-center sd-row';
    row.innerHTML = `
      <input name="items[${i}][description]" placeholder="Description" value="${desc}" class="col-span-6 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep" data-f="desc" required>
      <input name="items[${i}][qty]" type="number" min="0" step="1" value="${qty}" class="col-span-2 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] text-right focus:outline-none focus:border-wa-deep" data-f="qty" required>
      <input name="items[${i}][unit_price]" type="number" min="0" step="0.01" placeholder="0.00" value="${price}" class="col-span-3 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] text-right focus:outline-none focus:border-wa-deep" data-f="price" required>
      <button type="button" class="col-span-1 text-ink-400 hover:text-accent-coral sd-del" aria-label="Remove">&times;</button>`;
    rowsWrap.appendChild(row);
    row.querySelectorAll('input').forEach((i) => i.addEventListener('input', recalc));
    row.querySelector('.sd-del').addEventListener('click', () => { row.remove(); recalc(); });
    recalc();
  }

  function recalc() {
    let subtotal = 0;
    rowsWrap.querySelectorAll('.sd-row').forEach((r) => {
      const qty = parseFloat(r.querySelector('[data-f=qty]').value) || 0;
      const price = parseFloat(r.querySelector('[data-f=price]').value) || 0;
      subtotal += qty * price;
    });
    const taxRate = parseFloat(document.getElementById('sd-tax')?.value) || 0;
    const tax = subtotal * taxRate / 100;
    document.getElementById('sd-subtotal').textContent = money(subtotal);
    document.getElementById('sd-tax-amt').textContent = money(tax);
    document.getElementById('sd-total').textContent = money(subtotal + tax);
  }

  document.getElementById('sd-add-row')?.addEventListener('click', () => addRow());
  document.getElementById('sd-tax')?.addEventListener('input', recalc);
  addRow(); // start with one row
}
