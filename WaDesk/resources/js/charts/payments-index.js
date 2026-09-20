// AI-CRM Phase 2.2 — payment ledger: record-payment modal + prefill from an outstanding invoice.
export default function paymentsIndex() {
  const modal = document.getElementById('pay-modal');
  if (!modal) return;

  const open = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
  const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };

  const invField = document.getElementById('pay-invoice');
  const amount   = document.getElementById('pay-amount');
  const currency = document.getElementById('pay-currency');
  const note     = document.getElementById('pay-inv-note');

  document.getElementById('pay-open')?.addEventListener('click', () => {
    if (invField) invField.value = '';
    if (note) { note.classList.add('hidden'); note.textContent = ''; }
    open();
  });
  document.getElementById('pay-close')?.addEventListener('click', close);
  document.getElementById('pay-cancel')?.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

  document.querySelectorAll('.pay-quick').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (invField) invField.value = btn.dataset.invoice || '';
      if (amount) amount.value = btn.dataset.outstanding || '';
      if (currency && btn.dataset.currency) currency.value = btn.dataset.currency;
      if (note) { note.textContent = 'For invoice ' + (btn.dataset.number || ''); note.classList.remove('hidden'); }
      open();
    });
  });
}
