// AI-CRM Phase 6 — projects board: new-project modal + live progress update (PATCH).
export default function projectsIndex() {
  const modal = document.getElementById('pj-modal');
  const csrf = document.getElementById('pj-csrf')?.value || '';

  if (modal) {
    const open = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
    const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
    document.getElementById('pj-open')?.addEventListener('click', open);
    document.getElementById('pj-close')?.addEventListener('click', close);
    document.getElementById('pj-cancel')?.addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
  }

  // Live progress slider → PATCH; reloads when it hits 100 (moves to Completed).
  document.querySelectorAll('.pj-range').forEach((range) => {
    range.addEventListener('change', async () => {
      const id = range.dataset.id;
      const val = parseInt(range.value, 10) || 0;
      const card = range.closest('div').parentElement;
      const pct = card?.querySelector('.pj-pct');
      const bar = card?.querySelector('.h-full');
      if (pct) pct.textContent = val + '%';
      if (bar) bar.style.width = val + '%';
      try {
        await fetch(`/projects/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ progress: val }),
        });
        if (val === 100) window.location.reload();
      } catch (e) { /* best-effort */ }
    });
  });
}
