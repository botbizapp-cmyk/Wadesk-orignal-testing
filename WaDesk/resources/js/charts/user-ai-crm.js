/**
 * AI CRM Copilot — dashboard chat client.
 *
 * Sends the typed message to POST /ai-crm/message, renders the assistant reply,
 * and echoes any executed actions. Confirm-before-act is server-side (the reply
 * asks "reply YES to confirm"); the user just types yes/no like any other turn.
 * Loaded by app.js when <body data-page="user-ai-crm">.
 */
export default function initAiCrm() {
    const root = document.querySelector('[data-ai-crm]');
    if (!root) return;

    const messageUrl = root.dataset.messageUrl;
    const resetUrl = root.dataset.resetUrl;
    const csrf = root.dataset.csrf;

    const list = root.querySelector('[data-ai-messages]');
    const form = root.querySelector('[data-ai-form]');
    const input = root.querySelector('[data-ai-input]');
    const sendBtn = root.querySelector('[data-ai-send]');
    const sendLabel = root.querySelector('[data-ai-send-label]');
    const empty = root.querySelector('[data-ai-empty]');
    let busy = false;

    function scrollDown() {
        list.scrollTop = list.scrollHeight;
    }

    function bubble(role, text) {
        if (empty) empty.remove();
        const wrap = document.createElement('div');
        wrap.className = 'flex ' + (role === 'user' ? 'justify-end' : 'justify-start');
        const b = document.createElement('div');
        b.className =
            'max-w-[80%] rounded-2xl px-4 py-2.5 text-[13.5px] whitespace-pre-line ' +
            (role === 'user' ? 'bg-wa-deep text-white' : 'bg-paper-100 text-ink-800');
        b.textContent = text;
        wrap.appendChild(b);
        list.appendChild(wrap);
        scrollDown();
        return b;
    }

    function typingBubble() {
        if (empty) empty.remove();
        const wrap = document.createElement('div');
        wrap.className = 'flex justify-start';
        wrap.setAttribute('data-ai-typing', '');
        wrap.innerHTML =
            '<div class="rounded-2xl px-4 py-3 bg-paper-100"><span class="inline-flex gap-1">' +
            '<span class="w-1.5 h-1.5 rounded-full bg-ink-400 animate-bounce"></span>' +
            '<span class="w-1.5 h-1.5 rounded-full bg-ink-400 animate-bounce" style="animation-delay:.15s"></span>' +
            '<span class="w-1.5 h-1.5 rounded-full bg-ink-400 animate-bounce" style="animation-delay:.3s"></span>' +
            '</span></div>';
        list.appendChild(wrap);
        scrollDown();
        return wrap;
    }

    function setBusy(state) {
        busy = state;
        sendBtn.disabled = state;
        if (sendLabel) sendLabel.textContent = state ? '…' : sendBtn.dataset.idleLabel || 'Send';
    }
    if (sendLabel) sendBtn.dataset.idleLabel = sendLabel.textContent;

    async function send(text) {
        const msg = (text || '').trim();
        if (!msg || busy) return;
        bubble('user', msg);
        input.value = '';
        autoGrow();
        setBusy(true);
        const typing = typingBubble();

        try {
            const res = await fetch(messageUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ message: msg }),
            });
            typing.remove();
            if (!res.ok) {
                bubble('assistant', res.status === 429 ? 'You are sending too fast — please wait a moment.' : 'Something went wrong. Please try again.');
                return;
            }
            const data = await res.json();
            bubble('assistant', data.reply || 'Done.');
        } catch (e) {
            typing.remove();
            bubble('assistant', 'Network error — please try again.');
        } finally {
            setBusy(false);
            input.focus();
        }
    }

    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(128, input.scrollHeight) + 'px';
    }

    // ---- wiring -------------------------------------------------------------
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        send(input.value);
    });

    input.addEventListener('input', autoGrow);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send(input.value);
        }
    });

    root.querySelectorAll('[data-ai-suggestion]').forEach((btn) => {
        btn.addEventListener('click', () => {
            // Prefer data-prompt (lets a styled label like “…” fill a clean value).
            input.value = (btn.dataset.prompt || btn.textContent || '').trim();
            input.focus();
            autoGrow();
        });
    });

    const resetBtn = root.querySelector('[data-ai-reset]');
    if (resetBtn) {
        resetBtn.addEventListener('click', async () => {
            try {
                await fetch(resetUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
            } catch (e) {
                /* ignore */
            }
            list.querySelectorAll('.flex').forEach((n) => n.remove());
            bubble('assistant', 'New chat started. How can I help with your CRM?');
        });
    }

    // ---- WhatsApp staff-channel toggle -------------------------------------
    const waBox = document.querySelector('[data-wa-settings]');
    const waToggle = waBox ? waBox.querySelector('[data-wa-toggle]') : null;
    if (waBox && waToggle) {
        const waStatus = waBox.querySelector('[data-wa-status]');
        const knob = waToggle.querySelector('span');
        waToggle.addEventListener('click', async () => {
            const next = waToggle.getAttribute('aria-checked') !== 'true';
            waToggle.disabled = true;
            try {
                const res = await fetch(waBox.dataset.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': waBox.dataset.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ wa_copilot_enabled: next }),
                });
                if (!res.ok) throw new Error('failed');
                const data = await res.json();
                const on = !!data.wa_copilot_enabled;
                waToggle.setAttribute('aria-checked', on ? 'true' : 'false');
                waToggle.classList.toggle('bg-wa-deep', on);
                waToggle.classList.toggle('bg-paper-300', !on);
                if (knob) knob.classList.toggle('translate-x-5', on);
                if (waStatus) {
                    waStatus.textContent = on
                        ? 'Enabled — only managers/admins on their own number can command.'
                        : 'Disabled.';
                }
            } catch (e) {
                /* revert visual — leave as-is */
            } finally {
                waToggle.disabled = false;
            }
        });
    }

    scrollDown();
}
