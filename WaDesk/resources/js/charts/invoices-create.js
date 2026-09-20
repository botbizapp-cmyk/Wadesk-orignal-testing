// AI-CRM Phase 2.1 — free-form invoice builder: dynamic line-item rows + live totals.
export default function invoicesCreate() {
  const rowsEl = document.getElementById('inv-rows');
  const addBtn = document.getElementById('inv-add-row');
  const form   = document.getElementById('inv-form');
  if (!rowsEl || !form) return;

  let idx = 0;

  const money = (n) => (Number.isFinite(n) ? n : 0).toFixed(2);
  const symEl = document.getElementById('inv-currency');

  function rowHtml(i) {
    return `<tr class="inv-row border-t border-paper-100">
      <td class="py-1.5 pr-2"><input name="items[${i}][description]" required maxlength="500" placeholder="Item description"
        class="w-full rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep"></td>
      <td class="py-1.5 px-1"><input name="items[${i}][qty]" type="number" step="0.001" min="0.001" value="1"
        class="inv-qty w-full text-right rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep"></td>
      <td class="py-1.5 px-1"><input name="items[${i}][unit_price]" type="number" step="0.01" min="0" value="0"
        class="inv-price w-full text-right rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep"></td>
      <td class="py-1.5 px-1"><input name="items[${i}][tax_rate]" type="number" step="0.01" min="0" max="100" value="0"
        class="inv-tax w-full text-right rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep"></td>
      <td class="py-1.5 pl-1 text-right font-mono text-[12px] inv-amount">0.00</td>
      <td class="py-1.5 pl-1 text-right">
        <button type="button" class="inv-del w-6 h-6 rounded-md grid place-items-center text-accent-coral hover:bg-accent-coral/10" aria-label="Remove">
          <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg>
        </button>
      </td>
    </tr>`;
  }

  function addRow() {
    rowsEl.insertAdjacentHTML('beforeend', rowHtml(idx++));
    recompute();
  }

  function recompute() {
    let subtotal = 0, tax = 0;
    rowsEl.querySelectorAll('.inv-row').forEach((tr) => {
      const qty   = parseFloat(tr.querySelector('.inv-qty')?.value)   || 0;
      const price = parseFloat(tr.querySelector('.inv-price')?.value) || 0;
      const rate  = parseFloat(tr.querySelector('.inv-tax')?.value)   || 0;
      const line  = qty * price;
      const ltax  = line * rate / 100;
      subtotal += line;
      tax      += ltax;
      const cell = tr.querySelector('.inv-amount');
      if (cell) cell.textContent = money(line);
    });
    const discount = parseFloat(document.getElementById('inv-discount')?.value) || 0;
    const shipping = parseFloat(document.getElementById('inv-shipping')?.value) || 0;
    const total    = Math.max(0, subtotal - discount + shipping + tax);
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = money(v); };
    set('sum-subtotal', subtotal);
    set('sum-tax', tax);
    set('sum-discount', discount);
    set('sum-shipping', shipping);
    set('sum-total', total);
  }

  addBtn?.addEventListener('click', addRow);
  rowsEl.addEventListener('input', recompute);
  rowsEl.addEventListener('click', (e) => {
    const del = e.target.closest('.inv-del');
    if (!del) return;
    if (rowsEl.querySelectorAll('.inv-row').length > 1) del.closest('.inv-row').remove();
    else { del.closest('.inv-row').querySelectorAll('input').forEach((i) => { i.value = i.classList.contains('inv-qty') ? '1' : (i.classList.contains('inv-tax') || i.classList.contains('inv-price') ? '0' : ''); }); }
    recompute();
  });
  document.getElementById('inv-discount')?.addEventListener('input', recompute);
  document.getElementById('inv-shipping')?.addEventListener('input', recompute);
  symEl?.addEventListener('input', () => { symEl.value = symEl.value.toUpperCase(); });

  // Guard: don't submit with an empty item list.
  form.addEventListener('submit', (e) => {
    if (rowsEl.querySelectorAll('.inv-row').length === 0) { e.preventDefault(); addRow(); }
  });

  addRow(); // one starter row
}
