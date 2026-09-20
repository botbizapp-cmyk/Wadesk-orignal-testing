/**
 * Create a Telegram bot by holding a conversation with @BotFather.
 *
 * There is no API for this. `auth.*` and the Bot API both lack any "create bot"
 * method, and @BotFather is a bot — so only a logged-in USER account can reach
 * it. Every product that offers in-app bot creation is doing exactly this:
 * typing at @BotFather on the operator's behalf.
 *
 * WHY IT IS SHAPED LIKE A CONVERSATION
 * @BotFather has no structured replies. Each step is prose that has to be read
 * to know whether it worked, and the failure modes are ordinary sentences
 * ("Sorry, this username is already taken."). So each step sends one message,
 * waits for the next reply, and classifies it. That is genuinely fragile — the
 * wording is Telegram's to change — so classification is kept broad and the raw
 * reply is always returned for the operator to read when we cannot parse it.
 *
 * PACE
 * Deliberately unhurried. @BotFather rate-limits, and an account that machine-
 * guns it gets restricted. The gaps here are not politeness, they are what keeps
 * the operator's personal account usable.
 */

import { Api } from "teleproto";
import { clientFor, explain } from "./telegramAccountService.js";

const BOTFATHER = "BotFather";

/** How long to wait for one @BotFather reply before giving up on it. */
const REPLY_TIMEOUT_MS = 25 * 1000;

/** Gap between our messages. Human-scale on purpose — see PACE above. */
const STEP_DELAY_MS = 1200;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Send one message to @BotFather and return its next reply.
 *
 * Polls the conversation rather than subscribing to updates: this runs inside a
 * request, the exchange is a handful of messages, and an update subscription on a
 * user account is a much bigger surface than the job needs.
 */
/**
 * Resolve @BotFather to a real entity ONCE per client. gramjs/teleproto throw
 * "Could not find the input entity for BotFather" when getMessages/sendMessage
 * are called with the bare username on a session that has never opened that chat
 * — which is exactly a fresh operator account, so create-bot died on step 1.
 * getEntity resolves it (contacts.resolveUsername) and caches it, so every later
 * getMessages/sendMessage on this client works.
 */
async function botFatherPeer(client) {
    return client.getEntity(BOTFATHER);
}

async function ask(client, peer, text) {
    // Newest message id BEFORE sending, so we can tell a genuine reply from the
    // last thing @BotFather said in some earlier session.
    const before = await client.getMessages(peer, { limit: 1 });
    const lastId = before && before[0] ? before[0].id : 0;

    if (text !== null) {
        await client.sendMessage(peer, { message: text });
    }

    const deadline = Date.now() + REPLY_TIMEOUT_MS;

    while (Date.now() < deadline) {
        await sleep(900);

        const msgs = await client.getMessages(peer, { limit: 5 });
        // Incoming only, newer than what was there before we spoke. Skipping our
        // own outgoing message matters — getMessages returns it too, and reading
        // it back as the "reply" made every step look instantly successful.
        const reply = (msgs || []).find((m) => m && m.id > lastId && !m.out);

        if (reply && String(reply.message || "").trim()) {
            return String(reply.message);
        }
    }

    return null;
}

/** Pull the bot token out of @BotFather's success message. */
function extractToken(text) {
    const m = String(text || "").match(/(\d{6,}:[A-Za-z0-9_-]{30,})/);
    return m ? m[1] : "";
}

/**
 * Drive /newbot to completion.
 *
 * @param {string|number} accountId    telegram_accounts.id — keys the live client
 * @param {string} sessionString       the account's MTProto session
 * @param {string} displayName         the bot's shown name, freely chosen
 * @param {string} username            must end in "bot" and be globally unique
 */
export async function createBot(accountId, sessionString, displayName, username) {
    const name = String(displayName || "").trim();
    const handle = String(username || "").trim().replace(/^@/, "");

    if (!name) {
        return { ok: false, error: "Give the bot a name." };
    }
    // Checked here as well as in Laravel because @BotFather answers a bad
    // username with prose we would then have to guess at.
    if (!/^[A-Za-z0-9_]{5,32}$/.test(handle)) {
        return { ok: false, error: "The username must be 5–32 characters, letters, numbers and underscores only." };
    }
    if (!/bot$/i.test(handle)) {
        return { ok: false, error: "Telegram requires the username to end in \"bot\", e.g. my_orders_bot." };
    }

    let client;
    try {
        client = await clientFor(accountId, sessionString);
    } catch (e) {
        return { ok: false, error: explain(e) };
    }

    // Resolve @BotFather up front — the bare-username ask() calls below throw
    // "Could not find the input entity for BotFather" on a fresh account.
    let bf;
    try {
        bf = await botFatherPeer(client);
    } catch (e) {
        return { ok: false, error: "Could not reach @BotFather — is the account fully logged in? (" + explain(e) + ")" };
    }

    try {
        // Cancel anything half-finished from a previous attempt. Without this a
        // @BotFather left mid-/setdescription reads "/newbot" as the description.
        await ask(client, bf, "/cancel");
        await sleep(STEP_DELAY_MS);

        const started = await ask(client, bf, "/newbot");
        if (!started) {
            return { ok: false, error: "@BotFather did not answer. Try again in a minute." };
        }
        if (/too many attempts|try again later|20 bots/i.test(started)) {
            // The 20-bot ceiling and the rate limit both land here, and both are
            // things only the operator can resolve.
            return { ok: false, error: "@BotFather refused: " + started.split("\n")[0] };
        }

        await sleep(STEP_DELAY_MS);
        const afterName = await ask(client, bf, name);
        if (!afterName) {
            return { ok: false, error: "@BotFather stopped replying after the name." };
        }

        await sleep(STEP_DELAY_MS);
        const afterHandle = await ask(client, bf, handle);
        if (!afterHandle) {
            return { ok: false, error: "@BotFather stopped replying after the username." };
        }

        const token = extractToken(afterHandle);

        if (!token) {
            // Almost always a taken username. Returning @BotFather's own sentence
            // is more use than a guess at what it meant.
            const firstLine = afterHandle.split("\n").filter((l) => l.trim())[0] || afterHandle;

            if (/already taken|invalid|Sorry/i.test(afterHandle)) {
                return { ok: false, error: firstLine.trim(), retryUsername: true };
            }

            return { ok: false, error: "Could not read a token from @BotFather's reply: " + firstLine.trim() };
        }

        return { ok: true, token, username: handle, name };
    } catch (e) {
        return { ok: false, error: explain(e) };
    }
}

/**
 * Is this username free?
 *
 * @BotFather is the only authority — the Bot API cannot answer it. Asked with
 * /newbot then immediately cancelled, because there is no read-only check.
 */
export async function checkUsername(accountId, sessionString, username) {
    const handle = String(username || "").trim().replace(/^@/, "");

    if (!/^[A-Za-z0-9_]{5,32}$/.test(handle) || !/bot$/i.test(handle)) {
        return { ok: false, error: "Username must be 5–32 characters and end in \"bot\"." };
    }

    let client;
    try {
        client = await clientFor(accountId, sessionString);
    } catch (e) {
        return { ok: false, error: explain(e) };
    }

    try {
        // Resolving the handle is enough to know it is occupied, and costs
        // @BotFather nothing. Only a "not found" needs the conversation at all.
        await client.invoke(new Api.contacts.ResolveUsername({ username: handle }));
        return { ok: true, available: false };
    } catch (e) {
        const raw = String((e && e.errorMessage) || (e && e.message) || "");
        if (/USERNAME_NOT_OCCUPIED|USERNAME_INVALID/i.test(raw)) {
            return { ok: true, available: /NOT_OCCUPIED/i.test(raw) };
        }
        return { ok: false, error: explain(e) };
    }
}

/**
 * How many bots this account already owns, and their handles.
 *
 * Telegram caps an account at 20 bots. Reaching it mid-create is a confusing
 * failure, so the UI can warn beforehand.
 */
export async function listBots(accountId, sessionString) {
    let client;
    try {
        client = await clientFor(accountId, sessionString);
    } catch (e) {
        return { ok: false, error: explain(e) };
    }

    let bf;
    try {
        bf = await botFatherPeer(client);
    } catch (e) {
        return { ok: false, error: explain(e) };
    }

    try {
        await ask(client, bf, "/cancel");
        await sleep(STEP_DELAY_MS);

        const reply = await ask(client, bf, "/mybots");
        if (!reply) {
            return { ok: false, error: "@BotFather did not answer /mybots." };
        }

        // The handles live in the inline keyboard, not the text, so scraping the
        // body is best-effort. Reported as such rather than as a precise count.
        const handles = Array.from(new Set(
            (reply.match(/@([A-Za-z0-9_]{5,32})/g) || []).map((h) => h.slice(1))
        ));

        return { ok: true, bots: handles, count: handles.length, raw: reply.split("\n")[0] };
    } catch (e) {
        return { ok: false, error: explain(e) };
    }
}

export { extractToken as _extractToken };
