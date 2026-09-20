/**
 * Telegram USER-ACCOUNT sessions over MTProto.
 *
 * Distinct from every other Telegram code path in this project, which speaks the
 * Bot API over plain HTTPS. The Bot API has no method to create a bot — bots
 * exist only because someone messaged @BotFather, and @BotFather is itself a bot,
 * so no bot can talk to it. Creating one from the dashboard therefore requires
 * logging in as a real user, which is what this file does.
 *
 * WHAT THIS HOLDS
 * A session string here is equivalent to being logged into the operator's
 * Telegram account. It can read every private chat, not just bots. It is handed
 * to Laravel to store encrypted and is never written to disk by this process, so
 * it does not outlive a restart in plaintext anywhere.
 *
 * TELEGRAM'S VIEW
 * Automating a user account is against the spirit of the ToS and accounts do get
 * limited or banned for it. Everything here is therefore deliberately slow and
 * small: one login at a time, human-scale pauses in the @BotFather conversation,
 * no polling loops. There is nothing to gain from being fast and an account to
 * lose from being fast.
 */

import crypto from "crypto";
import { TelegramClient, Api, sessions, Logger } from "teleproto";

const { StringSession } = sessions;

/**
 * Half-finished logins, keyed by an opaque id we hand back to Laravel.
 *
 * A login is two round trips (send code, then submit it) and MTProto requires
 * the SAME connected client for both — the phone_code_hash is bound to it. So
 * the client has to stay alive between requests, which means in memory.
 *
 * Deliberately not persisted: a pending login is worthless after a restart (the
 * code expires in minutes anyway) and persisting a half-authenticated MTProto
 * client would be storing a credential for no benefit.
 */
const pending = new Map();

/** Fully logged-in clients, keyed by Laravel's telegram_accounts.id. */
const live = new Map();

const PENDING_TTL_MS = 10 * 60 * 1000;   // a login code outlives this by less
const DIAL_TIMEOUT_MS = 30 * 1000;

// Platform-wide MTProto credentials. Laravel (Admin → WaDesk Message) sends
// these on every request so the admin never edits node/.env; the last good pair
// is remembered so a mid-flow call that omits them (or the process env) still
// works. There is one api_id/hash per install — this is not per-tenant state.
let CRED_OVERRIDE = null;

/** Remember api_id/hash sent by Laravel. Ignores a blank/partial pair. */
export function setApiCredentials(apiId, apiHash) {
    const id = parseInt(apiId || "0", 10);
    const hash = (apiHash || "").trim();
    if (id && hash) {
        CRED_OVERRIDE = { apiId: id, apiHash: hash };
    }
}

/** Read API credentials at call time — override first, then process env. */
function apiCredentials() {
    if (CRED_OVERRIDE) {
        return CRED_OVERRIDE;
    }
    const apiId = parseInt(process.env.TELEGRAM_API_ID || "0", 10);
    const apiHash = (process.env.TELEGRAM_API_HASH || "").trim();

    if (!apiId || !apiHash) {
        const err = new Error(
            "TELEGRAM_API_ID / TELEGRAM_API_HASH are not set. Create an app at " +
            "https://my.telegram.org (API development tools) and put both in .env."
        );
        err.code = "no_api_credentials";
        throw err;
    }

    return { apiId, apiHash };
}

function newClient(sessionString = "") {
    const { apiId, apiHash } = apiCredentials();

    return new TelegramClient(new StringSession(sessionString), apiId, apiHash, {
        // One attempt, surfaced as an error. A retry loop inside a web request
        // just turns a clear failure into a timeout the operator cannot read.
        connectionRetries: 1,
        timeout: DIAL_TIMEOUT_MS / 1000,
        // Telegram shows this in the account's active-sessions list. Naming it
        // honestly means the operator can find and revoke it from their phone —
        // which, given what the session can read, they must always be able to do.
        deviceModel: "WaDesk",
        appVersion: "1.0",
        systemVersion: "server",
        baseLogger: quietLogger(),
    });
}

/**
 * teleproto logs connection chatter at info level straight to stdout, which in
 * this process means into the app log on every request. Only warnings up.
 */
function quietLogger() {
    try {
        const logger = new Logger("warn");
        return logger;
    } catch (e) {
        return undefined;
    }
}

/** Drop pending logins nobody finished, so an abandoned attempt is not held open. */
function sweepPending() {
    const now = Date.now();
    for (const [id, entry] of pending.entries()) {
        if (now - entry.startedAt > PENDING_TTL_MS) {
            entry.client.disconnect().catch(() => {});
            pending.delete(id);
        }
    }
}

/** Opaque handle for a login in progress. Not a secret, but not guessable. */
function loginId() {
    return "tgl_" + crypto.randomBytes(12).toString("hex");
}

/**
 * Translate MTProto errors into something an operator can act on.
 *
 * The raw messages are written for library authors ("PHONE_CODE_INVALID"), and
 * FLOOD_WAIT in particular needs its number surfaced or the operator retries
 * immediately and makes the block longer.
 */
function explain(e) {
    const raw = String((e && e.errorMessage) || (e && e.message) || e || "");

    if (/PHONE_NUMBER_INVALID/i.test(raw)) {
        return "That phone number is not valid. Include the country code, e.g. +919876543210.";
    }
    if (/PHONE_CODE_INVALID/i.test(raw)) {
        return "That code is wrong. Check the message Telegram sent you.";
    }
    if (/PHONE_CODE_EXPIRED/i.test(raw)) {
        return "That code has expired. Start the login again to get a new one.";
    }
    if (/SESSION_PASSWORD_NEEDED/i.test(raw)) {
        return "This account has two-step verification. Enter your Telegram password.";
    }
    if (/PASSWORD_HASH_INVALID/i.test(raw)) {
        return "That two-step verification password is wrong.";
    }
    if (/PHONE_NUMBER_BANNED/i.test(raw)) {
        return "Telegram has banned this phone number.";
    }
    if (/API_ID_PUBLISHED_FLOOD/i.test(raw)) {
        return "Telegram is rate-limiting these API credentials. Create your own app at my.telegram.org.";
    }
    const flood = raw.match(/FLOOD_WAIT_(\d+)/i);
    if (flood) {
        const seconds = parseInt(flood[1], 10);
        const wait = seconds > 3600
            ? `${Math.ceil(seconds / 3600)} hour(s)`
            : seconds > 60 ? `${Math.ceil(seconds / 60)} minute(s)` : `${seconds} second(s)`;
        return `Telegram is rate-limiting this account. Wait ${wait} before trying again — retrying sooner makes it longer.`;
    }
    if (/AUTH_KEY_UNREGISTERED|SESSION_REVOKED|AUTH_KEY_DUPLICATED/i.test(raw)) {
        return "This login was revoked from your Telegram account. Log in again.";
    }
    // teleproto's own message when a session string will not parse ("Not a valid
    // string"). Reaches an operator only if the stored session was corrupted, and
    // the library's wording gives no hint of what to do about it.
    if (/Not a valid string|Invalid session|Cannot read.*session/i.test(raw)) {
        return "The stored session is unreadable. Disconnect the account and log in again.";
    }

    return raw || "Telegram refused the request.";
}

/**
 * Step 1 — ask Telegram to send a login code.
 *
 * Returns the login id to quote back, and whether the code went to the Telegram
 * app rather than SMS (worth telling the operator, who is often waiting on the
 * wrong device).
 */
export async function sendCode(phone) {
    sweepPending();

    const number = String(phone || "").trim();
    if (!/^\+?\d{6,15}$/.test(number)) {
        return { ok: false, error: "Enter a phone number with country code, e.g. +919876543210." };
    }

    // Inside the try: apiCredentials() throws when .env is unset, and outside it
    // that became an unhandled rejection in the Express route instead of the
    // readable "set TELEGRAM_API_ID" the operator needs to see.
    let client = null;

    try {
        const { apiId, apiHash } = apiCredentials();
        client = newClient("");

        await client.connect();

        const result = await client.invoke(
            new Api.auth.SendCode({
                phoneNumber: number,
                apiId,
                apiHash,
                settings: new Api.CodeSettings({}),
            })
        );

        // Telegram can answer SendCode by saying "this number is already
        // authorised elsewhere, here is a token instead". There is no code to
        // enter in that case and pretending otherwise strands the operator.
        if (!result || !result.phoneCodeHash) {
            await client.disconnect().catch(() => {});
            return { ok: false, error: "Telegram did not send a code. Try again in a minute." };
        }

        const id = loginId();
        pending.set(id, {
            client,
            phone: number,
            phoneCodeHash: result.phoneCodeHash,
            startedAt: Date.now(),
        });

        const via = result.type && result.type.className ? String(result.type.className) : "";

        return {
            ok: true,
            loginId: id,
            // So the UI can say "check your Telegram app" instead of "check your SMS".
            sentTo: /App/i.test(via) ? "telegram_app" : /Sms/i.test(via) ? "sms" : "unknown",
        };
    } catch (e) {
        if (client) await client.disconnect().catch(() => {});
        return { ok: false, error: explain(e) };
    }
}

/**
 * Step 2 — submit the code, and the 2FA password if the account has one.
 *
 * On success the session string is RETURNED, not stored here. Laravel encrypts
 * it; this process keeps only the connected client for reuse.
 */
export async function signIn(id, code, password) {
    sweepPending();

    const entry = pending.get(String(id || ""));
    if (!entry) {
        return { ok: false, error: "That login expired. Start again." };
    }

    const { client, phone, phoneCodeHash } = entry;

    try {
        try {
            await client.invoke(
                new Api.auth.SignIn({
                    phoneNumber: phone,
                    phoneCodeHash,
                    phoneCode: String(code || "").trim(),
                })
            );
        } catch (e) {
            const raw = String((e && e.errorMessage) || (e && e.message) || "");

            if (!/SESSION_PASSWORD_NEEDED/i.test(raw)) {
                throw e;
            }

            // Two-step verification. The code was accepted; the password is a
            // second factor. Ask for it rather than failing the whole login.
            if (!String(password || "").trim()) {
                return { ok: false, needPassword: true, error: "This account has two-step verification. Enter your Telegram password." };
            }

            // signInWithPassword does the SRP exchange — the password itself is
            // never sent to Telegram, and must never be logged here either.
            await client.signInWithPassword(apiCredentials(), {
                password: async () => String(password),
                onError: (err) => { throw err; },
            });
        }

        const me = await client.getMe();
        const sessionString = String(client.session.save());

        pending.delete(String(id));

        return {
            ok: true,
            session: sessionString,
            user: {
                id: me && me.id ? String(me.id) : "",
                username: (me && me.username) || "",
                firstName: (me && me.firstName) || "",
                lastName: (me && me.lastName) || "",
                phone: (me && me.phone) ? "+" + String(me.phone).replace(/^\+/, "") : phone,
            },
        };
    } catch (e) {
        return { ok: false, error: explain(e) };
    }
}

/**
 * Step 1 of QR login — mint a token and render it as a scannable image.
 *
 * The Telegram equivalent of scanning a WhatsApp QR: the operator opens
 * Telegram → Settings → Devices → Link Desktop Device and points it at this.
 * No phone number typed, no SMS code.
 *
 * The token EXPIRES, usually in about 30 seconds, so the UI polls and this
 * hands back a fresh one when the old lapses. The pending client is kept alive
 * between calls for the same reason the phone flow does: the token is bound to
 * the connection that issued it.
 */
export async function startQrLogin(existingLoginId = '') {
    sweepPending();

    let entry = pending.get(String(existingLoginId || ''));
    let id = String(existingLoginId || '');

    try {
        const { apiId, apiHash } = apiCredentials();

        if (!entry) {
            const client = newClient('');
            await client.connect();
            id = loginId();
            entry = { client, phone: '', phoneCodeHash: '', startedAt: Date.now(), qr: true };
            pending.set(id, entry);
        }

        const res = await entry.client.invoke(
            new Api.auth.ExportLoginToken({ apiId, apiHash, exceptIds: [] })
        );

        if (res.className === 'auth.LoginTokenSuccess') {
            // Scanned between our poll and this call.
            return finishQr(entry, id);
        }

        const token = Buffer.from(res.token).toString('base64')
            // base64URL — Telegram rejects the standard alphabet here.
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

        const url = 'tg://login?token=' + token;
        const qrcode = (await import('qrcode')).default;

        return {
            ok: true,
            loginId: id,
            url,
            // A data URI, so the page needs no external request — the CSP on
            // some installs would block a CDN-hosted renderer anyway.
            qr: await qrcode.toDataURL(url, { margin: 1, width: 260 }),
            expiresIn: Math.max(5, Number(res.expires) - Math.floor(Date.now() / 1000)),
        };
    } catch (e) {
        if (entry && !existingLoginId) {
            await entry.client.disconnect().catch(() => {});
            pending.delete(id);
        }
        return { ok: false, error: explain(e) };
    }
}

/**
 * Step 2 — has it been scanned yet?
 *
 * Returns `waiting` until the operator confirms on their phone, then the
 * session. A scanned account with two-step verification still needs the
 * password: scanning proves possession of the phone, not knowledge of the
 * password, and Telegram treats them separately.
 */
export async function pollQrLogin(id, password = '') {
    const entry = pending.get(String(id || ''));
    if (!entry) {
        return { ok: false, error: 'That login expired. Start again.' };
    }

    try {
        const { apiId, apiHash } = apiCredentials();
        const res = await entry.client.invoke(
            new Api.auth.ExportLoginToken({ apiId, apiHash, exceptIds: [] })
        );

        if (res.className === 'auth.LoginTokenSuccess') {
            return finishQr(entry, id);
        }

        // The account lives on a different data centre than the one we happened
        // to connect to. Without following this the QR is scanned successfully
        // and the login never completes.
        if (res.className === 'auth.LoginTokenMigrateTo') {
            await entry.client._switchDC(res.dcId);
            const migrated = await entry.client.invoke(
                new Api.auth.ImportLoginToken({ token: res.token })
            );
            if (migrated.className === 'auth.LoginTokenSuccess') {
                return finishQr(entry, id);
            }
        }

        return { ok: true, status: 'waiting' };
    } catch (e) {
        const raw = String((e && e.errorMessage) || (e && e.message) || '');

        if (/SESSION_PASSWORD_NEEDED/i.test(raw)) {
            if (!String(password || '').trim()) {
                return { ok: false, needPassword: true, error: 'Scanned. This account has two-step verification — enter your Telegram password.' };
            }
            try {
                await entry.client.signInWithPassword(apiCredentials(), {
                    password: async () => String(password),
                    onError: (err) => { throw err; },
                });
                return finishQr(entry, id);
            } catch (inner) {
                return { ok: false, needPassword: true, error: explain(inner) };
            }
        }

        return { ok: false, error: explain(e) };
    }
}

/** Shared tail for both QR paths: read the identity, hand back the session. */
async function finishQr(entry, id) {
    const me = await entry.client.getMe();
    const session = String(entry.client.session.save());

    pending.delete(String(id));

    return {
        ok: true,
        status: 'signed_in',
        session,
        user: {
            id: me && me.id ? String(me.id) : '',
            username: (me && me.username) || '',
            firstName: (me && me.firstName) || '',
            lastName: (me && me.lastName) || '',
            phone: (me && me.phone) ? '+' + String(me.phone).replace(/^\+/, '') : '',
        },
    };
}

/** A connected client for a stored session, reused across calls. */
export async function clientFor(accountId, sessionString) {
    const key = String(accountId);

    const existing = live.get(key);
    if (existing) {
        try {
            if (existing.connected !== false) return existing;
        } catch (e) {
            // fall through and rebuild
        }
        live.delete(key);
    }

    const client = newClient(sessionString);
    await client.connect();

    const authorised = await client.isUserAuthorized();
    if (!authorised) {
        await client.disconnect().catch(() => {});
        const err = new Error("SESSION_REVOKED");
        err.errorMessage = "SESSION_REVOKED";
        throw err;
    }

    live.set(key, client);
    return client;
}

/** Confirm a stored session still works, without changing anything. */
export async function status(accountId, sessionString) {
    try {
        const client = await clientFor(accountId, sessionString);
        const me = await client.getMe();

        return {
            ok: true,
            user: {
                id: me && me.id ? String(me.id) : "",
                username: (me && me.username) || "",
                firstName: (me && me.firstName) || "",
            },
        };
    } catch (e) {
        return { ok: false, error: explain(e) };
    }
}

/**
 * Log the session out AT TELEGRAM, not just locally.
 *
 * Dropping our copy would leave the session listed and usable on the account.
 * Given what it can read, disconnecting alone would be a false promise.
 */
export async function logOut(accountId, sessionString) {
    try {
        const client = await clientFor(accountId, sessionString);
        await client.invoke(new Api.auth.LogOut());
        live.delete(String(accountId));
        await client.disconnect().catch(() => {});
        return { ok: true };
    } catch (e) {
        live.delete(String(accountId));
        return { ok: false, error: explain(e) };
    }
}

// Test seams — the maps are module state, exposed for assertions only.
export const _pending = pending;
export const _live = live;
export { explain };
