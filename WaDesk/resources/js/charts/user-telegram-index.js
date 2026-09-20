/**
 * /telegram — live @username availability check for the in-app "Create a bot"
 * form. Telegram is the source of truth (the same check @BotFather runs), so we
 * ask the server (which asks the account's MTProto session) rather than guess.
 * Debounced; purely advisory — the real check still happens on submit.
 */
export default function init() {
    initUsernameCheck();
    initQrLogin();
}

/** Live @username availability for the "Create a bot" form. */
function initUsernameCheck() {
    const form = document.querySelector('form[data-tg-account]');
    if (!form) return;

    const input = form.querySelector('[data-tg-username]');
    const status = form.querySelector('[data-tg-username-status]');
    if (!input || !status) return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const url = '/telegram/account/check-username';

    const paint = (text, cls) => {
        status.textContent = text;
        status.className = 'pr-3 text-[11px] font-mono ' + cls;
    };

    // Telegram's own rules, mirrored so obviously-invalid names never hit the API.
    const localError = (v) => {
        if (v.length < 5) return 'too short';
        if (v.length > 32) return 'too long';
        if (!/^[A-Za-z0-9_]+$/.test(v)) return 'letters, numbers, _';
        if (!/bot$/i.test(v)) return 'must end in “bot”';
        return null;
    };

    let timer = null;
    let seq = 0;

    const run = async () => {
        const v = input.value.trim().replace(/^@/, '');
        if (v === '') { paint('', ''); return; }

        const bad = localError(v);
        if (bad) { paint(bad, 'text-accent-coral'); return; }

        paint('checking…', 'text-ink-400');
        const mine = ++seq;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ username: v }),
            });
            if (mine !== seq) return; // a newer keystroke already fired
            const data = await res.json().catch(() => ({}));
            if (data.ok && data.available) {
                paint('available', 'text-wa-deep');
            } else if (data.ok && data.available === false) {
                paint('taken', 'text-accent-coral');
            } else {
                paint(data.error ? String(data.error).slice(0, 40) : '', 'text-ink-400');
            }
        } catch (e) {
            if (mine === seq) paint('', '');
        }
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(run, 450);
    });
}

/**
 * Telegram QR account login. Mints a login token (rendered as a QR data URI by
 * Node), polls until the phone scans it, re-mints before Telegram expires the
 * code (~30s), and asks for the two-step password when Telegram needs it. On
 * success the page reloads to show the connected account.
 */
function initQrLogin() {
    const root = document.querySelector('[data-tg-qr]');
    if (!root) return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const startUrl = root.dataset.qrStart;
    const pollUrl = root.dataset.qrPoll;

    const q = (sel) => root.querySelector(sel);
    const startBtn = q('[data-tg-qr-start]');
    const img = q('[data-tg-qr-img]');
    const idle = q('[data-tg-qr-idle]');
    const msg = q('[data-tg-qr-msg]');
    const passWrap = q('[data-tg-qr-pass-wrap]');
    const passIn = q('[data-tg-qr-pass]');
    const passGo = q('[data-tg-qr-pass-go]');
    if (!startBtn || !img) return;

    let pollTimer = null;
    let refreshTimer = null;
    let stopped = false;

    const say = (text, bad) => {
        msg.textContent = text || '';
        msg.className = 'text-[11.5px] mt-2 ' + (bad ? 'text-accent-coral' : 'text-ink-600');
    };
    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
        credentials: 'same-origin',
        body: JSON.stringify(body || {}),
    }).then((r) => r.json());
    const halt = () => { stopped = true; clearTimeout(pollTimer); clearTimeout(refreshTimer); };

    const show = (data) => {
        img.src = data.qr;
        img.hidden = false;
        if (idle) idle.hidden = true;
        startBtn.textContent = 'Refresh code';
        say('Waiting for you to scan…');
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(mint, Math.max(5, (data.expiresIn || 30) - 3) * 1000);
    };
    const done = () => { halt(); say('Signed in — reloading…'); setTimeout(() => window.location.reload(), 600); };

    function mint() {
        if (stopped) return;
        post(startUrl).then((d) => {
            if (!d.ok) { say(d.error || 'Could not start QR login.', true); return; }
            if (d.status === 'signed_in') { done(); return; }
            show(d);
            poll();
        }).catch((e) => say('Network error: ' + e.message, true));
    }
    function poll() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(() => {
            if (stopped) return;
            post(pollUrl, { password: passIn ? passIn.value : '' })
                .then((d) => {
                    if (d.status === 'signed_in') { done(); return; }
                    if (d.needPassword) {
                        if (passWrap) passWrap.hidden = false;
                        say(d.error || 'Enter your Telegram password.', true);
                        clearTimeout(refreshTimer);
                        return;
                    }
                    if (!d.ok) { say(d.error || 'Login failed.', true); halt(); return; }
                    poll();
                })
                .catch(() => poll());
        }, 2000);
    }

    startBtn.addEventListener('click', () => { stopped = false; say('Getting a code…'); mint(); });
    if (passGo) passGo.addEventListener('click', () => { say('Checking…'); poll(); });
}
