// AI-CRM Phase 3 — tasks board: new-task modal open/close.
export default function tasksIndex() {
  const modal = document.getElementById('task-modal');
  if (!modal) return;
  const open  = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
  const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };

  document.getElementById('task-open')?.addEventListener('click', open);
  document.getElementById('task-close')?.addEventListener('click', close);
  document.getElementById('task-cancel')?.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
}
