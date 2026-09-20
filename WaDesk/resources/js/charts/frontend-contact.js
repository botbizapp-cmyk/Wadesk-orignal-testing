import initRecaptcha from './auth-recaptcha.js';

/*
 * Public contact page. Only job: wire reCAPTCHA v3's on-submit token fetch
 * (the same generic helper the auth forms use). v2 (checkbox) needs no JS —
 * Google's api.js auto-renders the box and injects the token. Both are no-ops
 * when reCAPTCHA is disabled, so this stays inert until an admin turns it on.
 */
export default function init() {
    try { initRecaptcha(); } catch (e) { /* reCAPTCHA is optional */ }
}
