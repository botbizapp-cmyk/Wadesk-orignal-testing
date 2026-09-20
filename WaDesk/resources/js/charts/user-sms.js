// /sms page — number-lookup tool (Twilio Lookup). Posts a phone number to
// /sms/lookup and shows whether it's mobile / landline / invalid + carrier, so
// operators don't burn money texting numbers that can't receive SMS.
export default function initUserSms() {
    const box = document.querySelector('[data-sms-lookup]');
    if (!box) return;

    const input  = box.querySelector('[data-lookup-input]');
    const btn    = box.querySelector('[data-lookup-btn]');
    const result = box.querySelector('[data-lookup-result]');
    const url    = box.getAttribute('data-lookup-url') || '/sms/lookup';
    if (!input || !btn || !result) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    async function check() {
        const phone = (input.value || '').trim();
        if (phone === '') { result.textContent = ''; return; }

        btn.disabled = true;
        const prev = btn.textContent;
        btn.textContent = 'Checking…';
        result.textContent = '';
        result.style.color = '';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: JSON.stringify({ phone }),
            });
            const data = await res.json();
            result.textContent = data.describe || (data.ok ? 'Checked.' : (data.reason || 'Could not check this number.'));
            // Green when textable, coral when not, neutral otherwise.
            result.style.color = data.textable ? 'var(--color-wa-deep, #0B5A4A)'
                : (data.ok && data.valid === false) ? 'var(--color-accent-coral, #E0523C)' : '';
        } catch (e) {
            result.textContent = 'Could not reach the lookup service.';
            result.style.color = 'var(--color-accent-coral, #E0523C)';
        } finally {
            btn.disabled = false;
            btn.textContent = prev;
        }
    }

    btn.addEventListener('click', check);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); check(); } });
}
