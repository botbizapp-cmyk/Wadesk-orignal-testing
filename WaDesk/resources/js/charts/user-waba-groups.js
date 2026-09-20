/**
 * /waba-groups — copy invite link + handle join requests.
 *
 * Everything destructive (create, delete, reset link) is a plain form POST in
 * the blade, so it works without JS. Only the two things that genuinely need to
 * stay on the page live here.
 */
export default function initWabaGroups() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ── Copy invite link ───────────────────────────────────────────────────
    document.querySelectorAll('[data-copy-link]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const link = btn.dataset.copyLink || '';
            if (!link) return;
            try {
                await navigator.clipboard.writeText(link);
            } catch (e) {
                // Clipboard API needs a secure context; on plain HTTP fall back
                // to a hidden input so the button still does something useful.
                const t = document.createElement('textarea');
                t.value = link;
                document.body.appendChild(t);
                t.select();
                document.execCommand('copy');
                t.remove();
            }
            const was = btn.textContent;
            btn.textContent = btn.dataset.copiedLabel || 'Copied';
            setTimeout(() => { btn.textContent = was; }, 1500);
        });
    });

    // ── Approve / reject join requests ─────────────────────────────────────
    document.querySelectorAll('[data-jr-action]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('[data-group-row]');
            const groupId = row?.dataset.groupId;
            const jrId = btn.dataset.jrId;
            if (!groupId || !jrId) return;

            btn.disabled = true;
            const was = btn.textContent;
            btn.textContent = '…';

            try {
                const res = await fetch(`/waba-groups/${groupId}/join-requests`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ action: btn.dataset.jrAction, ids: [jrId] }),
                });
                const json = await res.json().catch(() => ({}));

                if (res.ok && json.ok) {
                    // Drop the whole row — the decision is made either way, and
                    // the participant webhook corrects the member count.
                    btn.closest('.flex.items-center.justify-between')?.remove();
                    return;
                }
                // Surface Meta's own wording; it names the actual cause (expired
                // request, group suspended, rate limit).
                alert(json.message || 'That did not work. Try again.');
            } catch (e) {
                alert('Could not reach the server. Check your connection and try again.');
            } finally {
                btn.disabled = false;
                btn.textContent = was;
            }
        });
    });
}
