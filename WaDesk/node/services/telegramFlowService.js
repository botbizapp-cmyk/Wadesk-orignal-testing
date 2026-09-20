// services/telegramFlowService.js
// ==============================
// Telegram (Bot API) flow engine — the Node counterpart of facebookFlowService /
// tiktokFlowService, with the send layer swapped to the Telegram Bot API.
//
// WHY NODE (not the source's PHP runner): this codebase runs NO queue worker, so
// a Delay/Wait node cannot be a queued job. Node is long-lived, so a wait is a
// real `await` — the same model FB/IG/TikTok flows use. Laravel hands the flow
// off (TgFlowBridge), Node walks it detached, the customer's next message
// resumes a parked node.
//
// Telegram has no inline-callback in our webhook subscription set, so `buttons`
// render as a REPLY keyboard: the tapped LABEL comes back as an ordinary
// message, which resume matches (by label or 1-based number). PURELY ADDITIVE.
import axios from "axios";

const TG_SESSIONS = new Map(); // `${botId}_${chatId}` → session

const nodeHeaders = () => ({ "X-Node-Token": process.env.NODE_WEBHOOK_TOKEN || "", Accept: "application/json" });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sessionKeyFor = (botId, chatId) => `${botId}_${chatId}`;

// ── Telegram Bot API send layer ─────────────────────────────────────────────
async function tgCall(auth, method, params) {
  const base = String(auth?.base || "https://api.telegram.org").replace(/\/+$/, "");
  const token = String(auth?.token || "");
  if (!token) throw new Error("telegram auth incomplete (token)");
  console.log(`[TG-SEND→] ${method} chat=${params?.chat_id}`);
  const r = await axios.post(`${base}/bot${token}/${method}`, params, { timeout: 30000, validateStatus: () => true });
  const body = r.data;
  if (!body || body.ok !== true) {
    throw new Error(body?.description || `telegram ${method} HTTP ${r.status}`);
  }
  return body.result;
}

const midOf = (result) => (result && (result.message_id || (Array.isArray(result) && result[0]?.message_id))) || null;

const sendText = (auth, chatId, text) => tgCall(auth, "sendMessage", { chat_id: chatId, text: String(text || "") });

const sendChoices = (auth, chatId, text, opts) =>
  tgCall(auth, "sendMessage", {
    chat_id: chatId,
    text: String(text || ""),
    reply_markup: JSON.stringify({
      keyboard: opts.filter((o) => String(o.title ?? o).trim() !== "").map((o) => [{ text: String(o.title ?? o).slice(0, 64) }]),
      one_time_keyboard: true,
      resize_keyboard: true,
    }),
  });

function methodFor(kind) {
  switch (String(kind || "").toLowerCase()) {
    case "video": return ["sendVideo", "video"];
    case "audio": return ["sendAudio", "audio"];
    case "voice": return ["sendVoice", "voice"];
    case "document": return ["sendDocument", "document"];
    default: return ["sendPhoto", "photo"];
  }
}

const sendMedia = (auth, chatId, kind, url, caption) => {
  const [method, field] = methodFor(kind);
  const p = { chat_id: chatId, [field]: url };
  if (caption) p.caption = caption;
  return tgCall(auth, method, p);
};

// Currencies with no minor unit — the amount is already the smallest unit.
const ZERO_DECIMAL = new Set(["JPY", "KRW", "VND", "CLP", "PYG", "UGX", "RWF", "XOF", "XAF", "IDR"]);

/** A human amount ("9.99", "150000") → Telegram's smallest-unit integer. */
function smallestUnit(amountStr, currency) {
  const n = Number(String(amountStr).replace(/[^0-9.]/g, "")) || 0;
  return ZERO_DECIMAL.has(String(currency).toUpperCase()) ? Math.round(n) : Math.round(n * 100);
}

/** sendInvoice — native in-chat payment. Needs auth.paymentToken. */
const sendInvoice = (auth, chatId, p) =>
  tgCall(auth, "sendInvoice", {
    chat_id: chatId,
    title: String(p.title || "").slice(0, 32),
    description: String(p.description || "").slice(0, 255),
    payload: String(p.payload || "").slice(0, 128),
    provider_token: String(p.providerToken || ""),
    currency: String(p.currency || "USD").toUpperCase(),
    prices: JSON.stringify([{ label: String(p.label || p.title || "Item").slice(0, 32), amount: p.amount }]),
  });

/** createInvoiceLink — a shareable pay URL (returned in result). */
const createInvoiceLink = (auth, p) =>
  tgCall(auth, "createInvoiceLink", {
    title: String(p.title || "").slice(0, 32),
    description: String(p.description || "").slice(0, 255),
    payload: String(p.payload || "").slice(0, 128),
    provider_token: String(p.providerToken || ""),
    currency: String(p.currency || "USD").toUpperCase(),
    prices: JSON.stringify([{ label: String(p.label || p.title || "Item").slice(0, 32), amount: p.amount }]),
  });

// ── Laravel bridges (AI / webhook / logging stay in PHP) ─────────────────────
async function logToLaravel(appDomain, payload) {
  try {
    await axios.post(`${appDomain}/api/telegram/flow-log`, payload, { headers: nodeHeaders(), timeout: 15000 });
  } catch (e) {
    console.warn(`[TG-FLOW-NODE] flow-log failed: ${e?.message}`);
  }
}
async function askLaravel(appDomain, payload) {
  const r = await axios.post(`${appDomain}/api/telegram/flow-node`, payload, { headers: nodeHeaders(), timeout: 60000, validateStatus: () => true });
  if (r.status >= 400) throw new Error(`flow-node HTTP ${r.status}: ${JSON.stringify(r.data)}`);
  return r.data || {};
}

// ── Graph helpers (identical to the FB/TikTok engines) ───────────────────────
const nodesOf = (flow) => (flow?.flowNodes || flow?.nodes || []);
const edgesOf = (flow) => (flow?.flowEdges || flow?.edges || []);
function indexNodes(flow) { const m = new Map(); for (const n of nodesOf(flow)) if (n?.id) m.set(String(n.id), n); return m; }
function nextNode(flow, nodeId, port = "out") {
  let any = null;
  for (const e of edgesOf(flow)) {
    if (String(e?.source) !== String(nodeId)) continue;
    if (any === null) any = String(e?.target || "");
    if (String(e?.sourceHandle || "out") === port) return String(e?.target || "");
  }
  return port === "out" ? any : null;
}
function entryNode(flow) { for (const n of nodesOf(flow)) if (String(n?.type) === "trigger") return n; return null; }
const subst = (s, vars) => String(s ?? "").replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_, k) => String(vars?.[k] ?? ""));
export function delayMsOf(d) {
  const amount = Number(d?.amount ?? d?.delay ?? d?.value ?? 0);
  if (!(amount > 0)) return 0;
  const unit = String(d?.unit || "min").toLowerCase();
  const mult = unit.startsWith("s") ? 1000 : unit.startsWith("h") ? 3_600_000 : unit.startsWith("d") ? 86_400_000 : 60_000;
  return Math.round(amount * mult);
}
const chatOptions = (d) =>
  (d?.options || [])
    .map((o, i) => ({ title: String(typeof o === "object" ? (o.title ?? o.label ?? "") : o).trim(), payload: `OPT_${i}` }))
    .filter((o) => o.title !== "")
    .slice(0, 12);
function evalCondition(d, vars) {
  const left = subst(d?.variable ?? d?.left ?? "{{text}}", vars).toLowerCase().trim();
  const right = subst(d?.value ?? d?.right ?? "", vars).toLowerCase().trim();
  switch (String(d?.operator ?? d?.op ?? "contains")) {
    case "equals": case "=": case "==": return left === right;
    case "not_equals": case "!=": return left !== right;
    case "starts_with": return left.startsWith(right);
    default: return right === "" ? true : left.includes(right);
  }
}

// ── The walker ───────────────────────────────────────────────────────────────
async function walk(ctx, startId) {
  const { auth, flow, chatId, appDomain, botId, flowId, workspaceId } = ctx;
  const nodes = indexNodes(flow);
  let current = startId;
  let guard = 0;
  console.log(`[TG-WALK] start flow=${flowId} chat=${chatId} from=${startId} nodes=${nodes.size}`);

  while (current && guard++ < 100) {
    const node = nodes.get(String(current));
    if (!node) { console.warn(`[TG-WALK] node id="${current}" NOT FOUND — ending flow=${flowId}`); break; }
    const type = String(node.type || "");
    const d = node.data || {};
    let port = "out";
    console.log(`[TG-NODE] → type=${type} id=${node.id} flow=${flowId}`);

    try {
      switch (type) {
        case "message": {
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, chatId, body);
            await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body, source: "flow", mid: midOf(r) });
          }
          break;
        }
        case "media": {
          let url = subst(d.url ?? d.mediaUrl, ctx.vars).trim();
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith("data:")) {
            url = `${String(appDomain).replace(/\/+$/, "")}${url.startsWith("/") ? "" : "/"}${url}`;
          }
          let kind = String(d.kind ?? d.mediaType ?? "image").toLowerCase();
          if (kind === "image") kind = "photo";
          const cap = subst(d.caption, ctx.vars).trim();
          if (url) {
            const r = await sendMedia(auth, chatId, kind, url, cap || undefined);
            await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body: cap || `[${kind}]`, source: "flow", mid: midOf(r) });
          } else if (cap) {
            const r = await sendText(auth, chatId, cap);
            await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body: cap, source: "flow", mid: midOf(r) });
          }
          break;
        }
        case "buttons": {
          const body = subst(d.prompt ?? d.text, ctx.vars);
          const opts = chatOptions(d);
          const r = await sendChoices(auth, chatId, body, opts);
          await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body, source: "flow", mid: midOf(r), buttons: opts.map((o) => ({ title: String(o.title ?? o) })) });
          park(ctx, node.id);
          return; // wait for the tap
        }
        case "ask": {
          const q = subst(d.prompt ?? d.question ?? d.text, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, chatId, q);
            await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body: q, source: "flow", mid: midOf(r) });
          }
          park(ctx, node.id);
          return; // wait for the answer
        }
        case "delay": {
          const ms = delayMsOf(d);
          if (ms > 0) { console.log(`[TG-FLOW-NODE] delay node=${node.id} ${ms}ms`); await sleep(ms); }
          break;
        }
        case "condition":
          port = evalCondition(d, ctx.vars) ? "yes" : "no";
          break;
        case "webhook": {
          const out = await askLaravel(appDomain, { action: "webhook", node: d, vars: ctx.vars, workspaceId });
          if (out?.vars) Object.assign(ctx.vars, out.vars);
          break;
        }
        case "ai":
        case "tg_ai": {
          const out = await askLaravel(appDomain, { action: "ai", node: d, vars: ctx.vars, workspaceId, botId, chatId });
          const reply = String(out?.reply || "");
          if (reply) {
            const r = await sendText(auth, chatId, reply);
            await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body: reply, source: "ai", mid: midOf(r) });
          }
          const saveKey = String(d.save || "").trim();
          if (saveKey) ctx.vars[saveKey] = reply;
          break;
        }
        case "payment":
        case "tg_payment":
        case "invoice": {
          const providerToken = String(auth?.paymentToken || "");
          const currency = String(d.currency || "USD").toUpperCase();
          const amount = smallestUnit(subst(d.amount, ctx.vars), currency);
          const payload = (subst(d.payload, ctx.vars) || `flow_${flowId}_${chatId}_${Date.now()}`).slice(0, 128);
          const params = {
            title: subst(d.title, ctx.vars) || "Payment",
            description: subst(d.description, ctx.vars) || subst(d.title, ctx.vars) || "Payment",
            label: subst(d.label, ctx.vars) || subst(d.title, ctx.vars) || "Item",
            currency, amount, payload, providerToken,
          };
          if (!providerToken) {
            const warn = "This bot has no payment provider token set — add it under Telegram → the bot's Payments.";
            console.warn(`[TG-FLOW-NODE] payment node=${node.id} skipped: no provider token`);
            await sendText(auth, chatId, warn).catch(() => {});
          } else if (amount <= 0) {
            console.warn(`[TG-FLOW-NODE] payment node=${node.id} skipped: amount<=0`);
          } else if (String(d.mode || "invoice").toLowerCase() === "paylink") {
            const r = await createInvoiceLink(auth, params);
            const link = String(r?.result || "");
            if (link) {
              const intro = subst(d.linkText, ctx.vars) || "Tap to pay:";
              const msg = `${intro}\n${link}`;
              const sent = await sendText(auth, chatId, msg);
              await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body: msg, source: "flow", mid: midOf(sent) });
            }
          } else {
            const r = await sendInvoice(auth, chatId, params);
            await logToLaravel(appDomain, { botId, chatId, workspaceId, direction: "out", body: `[invoice] ${params.title} — ${currency} ${d.amount}`, source: "flow", mid: midOf(r) });
          }
          // Block-until-paid: park here and wait for Laravel's successful_payment
          // webhook to resume us (only that signal advances — see resumeFlow).
          if (providerToken && amount > 0 && (d.wait === true || d.wait === "1" || d.wait === 1)) {
            ctx.vars.invoice_payload = payload;
            park(ctx, node.id);
            return;
          }
          break; // fire-and-forget: the payment lands on Laravel's webhook
        }
        case "end":
          clearSession(botId, chatId);
          console.log(`[TG-FLOW-NODE] end flow=${flowId} chat=${chatId}`);
          return;
        default:
          console.warn(`[TG-FLOW-NODE] node type "${type}" has no executor — skipped (flow=${flowId} node=${node.id})`);
      }
    } catch (e) {
      console.error(`[TG-FLOW-NODE] node ${node.id} (${type}) failed: ${e?.message}`);
    }

    current = nextNode(flow, node.id, port);
  }

  if (guard >= 100) console.warn(`[TG-FLOW-NODE] walk hit the 100-node guard (flow=${flowId})`);
  clearSession(botId, chatId);
}

// ── Session state ────────────────────────────────────────────────────────────
function park(ctx, nodeId) {
  const key = sessionKeyFor(ctx.botId, ctx.chatId);
  TG_SESSIONS.set(key, {
    botId: ctx.botId, chatId: ctx.chatId, workspaceId: ctx.workspaceId,
    flowId: ctx.flowId, flow: ctx.flow, auth: ctx.auth, appDomain: ctx.appDomain,
    nodeId: String(nodeId), vars: ctx.vars, parkedAt: Date.now(),
  });
  console.log(`[TG-FLOW-NODE] parked at node=${nodeId} key=${key}`);
}
function clearSession(botId, chatId) { TG_SESSIONS.delete(sessionKeyFor(botId, chatId)); }
export const hasSession = (botId, chatId) => TG_SESSIONS.has(sessionKeyFor(botId, chatId));
export function pruneSessions(maxAgeMs = 86_400_000) {
  const cutoff = Date.now() - maxAgeMs; let n = 0;
  for (const [k, s] of TG_SESSIONS) if (s.parkedAt < cutoff) { TG_SESSIONS.delete(k); n++; }
  return n;
}

// ── Public API ───────────────────────────────────────────────────────────────
export async function runFlow({ auth, flow, chatId, text, flowId, botId, workspaceId, appDomain, vars }) {
  clearSession(botId, chatId);
  const start = entryNode(flow);
  if (!start) { console.warn(`[TG-FLOW-NODE] flow ${flowId} has no trigger node`); return false; }
  const ctx = {
    auth, flow, chatId, flowId, botId, workspaceId, appDomain,
    vars: { text: String(text || ""), chat_id: String(chatId), ...(vars || {}) },
  };
  console.log(`[TG-FLOW-NODE] START flow=${flowId} bot=${botId} chat=${chatId}`);
  await walk(ctx, nextNode(flow, start.id, "out"));
  return true;
}

export async function resumeFlow({ botId, chatId, text, vars }) {
  const key = sessionKeyFor(botId, chatId);
  const sess = TG_SESSIONS.get(key);
  if (!sess) { console.log(`[TG-RESUME] no session key=${key}`); return false; }
  // Carry any resume-time vars (e.g. payment amount from the webhook) into the flow.
  if (vars && typeof vars === "object") Object.assign(sess.vars, vars);
  const nodes = indexNodes(sess.flow);
  const parked = nodes.get(String(sess.nodeId));
  if (!parked) { console.warn(`[TG-RESUME] parked node "${sess.nodeId}" missing — dropping`); TG_SESSIONS.delete(key); return false; }

  const d = parked.data || {};
  const type = String(parked.type || "");
  const t = String(text || "").toLowerCase().trim();
  let port = "out";
  console.log(`[TG-RESUME] key=${key} parkedNode=${sess.nodeId} type=${type} reply="${t.slice(0, 40)}"`);

  if (type === "ask") {
    const saveKey = String(d.var || d.save || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
    const expected = (d.options || []).map((o) => String(o).trim()).filter(Boolean);
    if (expected.length) {
      port = "else";
      for (let i = 0; i < expected.length; i++) if (t === expected[i].toLowerCase()) { port = `p${i}`; break; }
    }
  }

  if (type === "buttons") {
    const opts = chatOptions(d);
    let idx = null;
    const asNum = parseInt(t, 10);
    if (Number.isInteger(asNum) && asNum >= 1 && asNum <= opts.length) idx = asNum - 1;
    else for (let i = 0; i < opts.length; i++) if (t === String(opts[i].title).toLowerCase().trim()) { idx = i; break; }
    console.log(`[TG-RESUME] button match reply="${t}" → idx=${idx}`);
    if (idx === null) return false; // not a valid pick — let normal handling take it
    port = `p${idx}`;
    const saveKey = String(d.var || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  if (type === "payment" || type === "tg_payment" || type === "invoice") {
    // ONLY the successful-payment signal advances a block-until-paid node. Any
    // other message leaves the flow parked (still waiting for the customer to
    // pay), so it isn't consumed here.
    if (!t.startsWith("__tg_paid__")) { console.log(`[TG-RESUME] payment node — non-payment reply ignored, still waiting`); return false; }
    port = "out";
  }

  TG_SESSIONS.delete(key);
  sess.vars.text = String(text || "");
  console.log(`[TG-FLOW-NODE] RESUME flow=${sess.flowId} from=${sess.nodeId} port=${port}`);
  await walk({ ...sess, vars: sess.vars }, nextNode(sess.flow, sess.nodeId, port));
  return true;
}

export default { runFlow, resumeFlow, hasSession, pruneSessions, delayMsOf };
