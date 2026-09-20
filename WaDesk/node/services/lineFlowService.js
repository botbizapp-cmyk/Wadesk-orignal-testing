// services/lineFlowService.js
// ===========================
// LINE (Messaging API) flow engine — the Node counterpart of
// telegramFlowService / facebookFlowService, with the send layer swapped to the
// LINE Messaging API.
//
// WHY NODE (not a PHP runner): this codebase runs NO queue worker, so a
// Delay/Wait node cannot be a queued job. Node is long-lived, so a wait is a
// real `await` — the same model the FB/IG/TikTok/Telegram flows use. Laravel
// hands the flow off (LineFlowBridge), Node walks it detached, the customer's
// next message resumes a parked node.
//
// LINE `buttons` render as QUICK REPLIES (a "message" action): the tapped LABEL
// comes back as an ordinary text message, which resume matches (by label or
// 1-based number) — exactly like Telegram's reply keyboard. Flow sends use PUSH
// (billable): a flow is multi-step and often delayed, so the ~1-min free
// replyToken cannot cover it. PURELY ADDITIVE.
import axios from "axios";

const LINE_SESSIONS = new Map(); // `${channelId}_${userId}` → session

const nodeHeaders = () => ({ "X-Node-Token": process.env.NODE_WEBHOOK_TOKEN || "", Accept: "application/json" });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sessionKeyFor = (channelId, userId) => `${channelId}_${userId}`;
const TEXT_LIMIT = 5000;
const LABEL_LIMIT = 20;

// ── LINE Messaging API send layer ────────────────────────────────────────────
async function lineCall(auth, path, body) {
  const base = String(auth?.base || "https://api.line.me").replace(/\/+$/, "");
  const token = String(auth?.token || "");
  if (!token) throw new Error("line auth incomplete (token)");
  const r = await axios.post(`${base}${path}`, body, {
    timeout: 30000,
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
    validateStatus: () => true,
  });
  if (r.status < 200 || r.status >= 300) {
    throw new Error(r.data?.message || `line ${path} HTTP ${r.status}`);
  }
  return r.data || {};
}

// LINE push returns { sentMessages: [{ id }] } — the first id identifies the send.
const midOf = (result) => (result && Array.isArray(result.sentMessages) && result.sentMessages[0]?.id) || null;

const pushMessages = (auth, userId, messages) =>
  lineCall(auth, "/v2/bot/message/push", { to: userId, messages: messages.slice(0, 5) });

const textMsg = (text) => ({ type: "text", text: String(text || "").slice(0, TEXT_LIMIT) });

const sendText = (auth, userId, text) => pushMessages(auth, userId, [textMsg(text)]);

const sendChoices = (auth, userId, text, opts) => {
  const items = opts
    .filter((o) => String(o.title ?? o).trim() !== "")
    .slice(0, 13)
    .map((o) => {
      const label = String(o.title ?? o).slice(0, LABEL_LIMIT);
      return { type: "action", action: { type: "message", label, text: String(o.title ?? o) } };
    });
  const msg = textMsg(text);
  if (items.length) msg.quickReply = { items };
  return pushMessages(auth, userId, [msg]);
};

function mediaMsg(kind, url) {
  switch (String(kind || "").toLowerCase()) {
    case "video": return { type: "video", originalContentUrl: url, previewImageUrl: url };
    case "audio": return { type: "audio", originalContentUrl: url, duration: 60000 };
    default:      return { type: "image", originalContentUrl: url, previewImageUrl: url };
  }
}

const sendMedia = (auth, userId, kind, url, caption) => {
  const messages = [mediaMsg(kind, url)];
  if (caption) messages.push(textMsg(caption)); // LINE media carries no caption
  return pushMessages(auth, userId, messages);
};

// ── Laravel bridges (AI / webhook / logging stay in PHP) ─────────────────────
async function logToLaravel(appDomain, payload) {
  try {
    await axios.post(`${appDomain}/api/line/flow-log`, payload, { headers: nodeHeaders(), timeout: 15000 });
  } catch (e) {
    console.warn(`[LINE-FLOW-NODE] flow-log failed: ${e?.message}`);
  }
}
async function askLaravel(appDomain, payload) {
  const r = await axios.post(`${appDomain}/api/line/flow-node`, payload, { headers: nodeHeaders(), timeout: 60000, validateStatus: () => true });
  if (r.status >= 400) throw new Error(`flow-node HTTP ${r.status}: ${JSON.stringify(r.data)}`);
  return r.data || {};
}

// ── Graph helpers (identical to the FB/TikTok/Telegram engines) ──────────────
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
    .slice(0, 13);
// ── Condition ────────────────────────────────────────────────────────────
// The builder persists `data.conditions[]` + `data.operators[]` (AND/OR
// joiners) — the same shape flowService reads. Reading the flat
// {variable,value,operator} keys instead made every rule fall through to
// `contains ""` and evaluate TRUE, so the ELSE port was unreachable. The flat
// shape is still honoured for hand-written / imported graphs.
const condIsSet = (v) => {
  if (v === undefined || v === null) return false;
  if (typeof v === "string") return v.trim() !== "";
  if (Array.isArray(v)) return v.length > 0;
  if (typeof v === "object") return Object.keys(v).length > 0;
  return true;
};
function condVar(name, vars) {
  const key = String(name ?? "").trim().replace(/^\{\{\s*/, "").replace(/\s*\}\}$/, "").trim();
  if (key === "") return undefined;
  const bag = vars || {};
  if (Object.prototype.hasOwnProperty.call(bag, key)) return bag[key];
  const hit = Object.keys(bag).find((k) => k.toLowerCase() === key.toLowerCase());
  return hit === undefined ? undefined : bag[hit];
}
function evalOneCondition(c, vars) {
  const op = String(c?.operator ?? c?.op ?? "contains").toLowerCase().trim().replace(/\s+/g, "_");
  const resolved = condVar(c?.variable ?? c?.left, vars);
  if (op === "exists" || op === "is_set") return condIsSet(resolved);
  if (op === "not_exists" || op === "is_not_set") return !condIsSet(resolved);
  // Same fallback the WhatsApp engine uses: a blank variable compares against
  // whatever the customer last wrote.
  const src = condIsSet(resolved) ? resolved : String(vars?.text ?? "");
  const uRaw = typeof src === "object" ? JSON.stringify(src) : String(src);
  const u = uRaw.toLowerCase().trim();
  const checkValue = c?.value ?? c?.right ?? "";
  const v = String(checkValue).toLowerCase().trim();
  switch (op) {
    case "equals": case "=": case "==":   return u === v;
    case "not_equals": case "!=":         return u !== v;
    case "contains":                      return u.includes(v);
    case "not_contains":                  return !u.includes(v);
    case "gt": case "greater_than":       return parseFloat(uRaw) > parseFloat(checkValue);
    case "lt": case "less_than":          return parseFloat(uRaw) < parseFloat(checkValue);
    case "is_empty":                      return u === "";
    case "is_not_empty":                  return u !== "";
    case "starts_with":                   return u.startsWith(v);
    case "ends_with":                     return u.endsWith(v);
    default:
      console.warn(`[LINE-NODE] condition: unknown operator "${c?.operator}" — treated as FALSE`);
      return false;
  }
}
function evalCondition(d, vars) {
  const rules = Array.isArray(d?.conditions) && d.conditions.length
    ? d.conditions
    : [{ variable: d?.variable ?? d?.left, operator: d?.operator ?? d?.op, value: d?.value ?? d?.right }];
  const joiners = Array.isArray(d?.logicOperators) ? d.logicOperators
                : Array.isArray(d?.operators)      ? d.operators
                : [];
  let met = evalOneCondition(rules[0], vars);
  for (let i = 1; i < rules.length; i++) {
    const j = String(joiners[i - 1] || "AND").toUpperCase();
    const next = evalOneCondition(rules[i], vars);
    met = j === "OR" ? (met || next) : (met && next);
  }
  console.log(`[LINE-NODE] condition rules=${rules.length} -> ${met ? "TRUE (yes port)" : "FALSE (no port)"}`);
  return met;
}

// ── The walker ───────────────────────────────────────────────────────────────
async function walk(ctx, startId) {
  const { auth, flow, userId, appDomain, channelId, flowId, workspaceId } = ctx;
  const nodes = indexNodes(flow);
  let current = startId;
  let guard = 0;
  console.log(`[LINE-WALK] start flow=${flowId} user=${userId} from=${startId} nodes=${nodes.size}`);

  while (current && guard++ < 100) {
    const node = nodes.get(String(current));
    if (!node) { console.warn(`[LINE-WALK] node id="${current}" NOT FOUND — ending flow=${flowId}`); break; }
    const type = String(node.type || "");
    const d = node.data || {};
    let port = "out";
    console.log(`[LINE-NODE] → type=${type} id=${node.id} flow=${flowId}`);

    try {
      switch (type) {
        case "message": {
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, userId, body);
            await logToLaravel(appDomain, { channelId, userId, workspaceId, direction: "out", body, source: "flow", mid: midOf(r) });
          }
          break;
        }
        case "media": {
          let url = subst(d.url ?? d.mediaUrl, ctx.vars).trim();
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith("data:")) {
            url = `${String(appDomain).replace(/\/+$/, "")}${url.startsWith("/") ? "" : "/"}${url}`;
          }
          const kind = String(d.kind ?? d.mediaType ?? "image").toLowerCase();
          const cap = subst(d.caption, ctx.vars).trim();
          if (url) {
            const r = await sendMedia(auth, userId, kind, url, cap || undefined);
            await logToLaravel(appDomain, { channelId, userId, workspaceId, direction: "out", body: cap || `[${kind}]`, source: "flow", mid: midOf(r) });
          } else if (cap) {
            const r = await sendText(auth, userId, cap);
            await logToLaravel(appDomain, { channelId, userId, workspaceId, direction: "out", body: cap, source: "flow", mid: midOf(r) });
          }
          break;
        }
        case "buttons": {
          const body = subst(d.prompt ?? d.text, ctx.vars);
          const opts = chatOptions(d);
          const r = await sendChoices(auth, userId, body, opts);
          await logToLaravel(appDomain, { channelId, userId, workspaceId, direction: "out", body, source: "flow", mid: midOf(r), buttons: opts.map((o) => ({ title: String(o.title ?? o) })) });
          park(ctx, node.id);
          return; // wait for the tap
        }
        case "ask": {
          const q = subst(d.prompt ?? d.question ?? d.text, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, userId, q);
            await logToLaravel(appDomain, { channelId, userId, workspaceId, direction: "out", body: q, source: "flow", mid: midOf(r) });
          }
          park(ctx, node.id);
          return; // wait for the answer
        }
        case "delay": {
          const ms = delayMsOf(d);
          if (ms > 0) { console.log(`[LINE-FLOW-NODE] delay node=${node.id} ${ms}ms`); await sleep(ms); }
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
        case "line_ai": {
          const out = await askLaravel(appDomain, { action: "ai", node: d, vars: ctx.vars, workspaceId, channelId, userId });
          const reply = String(out?.reply || "");
          if (reply) {
            const r = await sendText(auth, userId, reply);
            await logToLaravel(appDomain, { channelId, userId, workspaceId, direction: "out", body: reply, source: "ai", mid: midOf(r) });
          }
          const saveKey = String(d.save || "").trim();
          if (saveKey) ctx.vars[saveKey] = reply;
          break;
        }
        case "end":
          clearSession(channelId, userId);
          console.log(`[LINE-FLOW-NODE] end flow=${flowId} user=${userId}`);
          return;
        default:
          console.warn(`[LINE-FLOW-NODE] node type "${type}" has no executor — skipped (flow=${flowId} node=${node.id})`);
      }
    } catch (e) {
      console.error(`[LINE-FLOW-NODE] node ${node.id} (${type}) failed: ${e?.message}`);
    }

    current = nextNode(flow, node.id, port);
  }

  if (guard >= 100) console.warn(`[LINE-FLOW-NODE] walk hit the 100-node guard (flow=${flowId})`);
  clearSession(channelId, userId);
}

// ── Session state ────────────────────────────────────────────────────────────
function park(ctx, nodeId) {
  const key = sessionKeyFor(ctx.channelId, ctx.userId);
  LINE_SESSIONS.set(key, {
    channelId: ctx.channelId, userId: ctx.userId, workspaceId: ctx.workspaceId,
    flowId: ctx.flowId, flow: ctx.flow, auth: ctx.auth, appDomain: ctx.appDomain,
    nodeId: String(nodeId), vars: ctx.vars, parkedAt: Date.now(),
  });
  console.log(`[LINE-FLOW-NODE] parked at node=${nodeId} key=${key}`);
}
function clearSession(channelId, userId) { LINE_SESSIONS.delete(sessionKeyFor(channelId, userId)); }
export const hasSession = (channelId, userId) => LINE_SESSIONS.has(sessionKeyFor(channelId, userId));
export function pruneSessions(maxAgeMs = 86_400_000) {
  const cutoff = Date.now() - maxAgeMs; let n = 0;
  for (const [k, s] of LINE_SESSIONS) if (s.parkedAt < cutoff) { LINE_SESSIONS.delete(k); n++; }
  return n;
}

// ── Public API ───────────────────────────────────────────────────────────────
export async function runFlow({ auth, flow, userId, text, flowId, channelId, workspaceId, appDomain, vars }) {
  clearSession(channelId, userId);
  const start = entryNode(flow);
  if (!start) { console.warn(`[LINE-FLOW-NODE] flow ${flowId} has no trigger node`); return false; }
  const ctx = {
    auth, flow, userId, flowId, channelId, workspaceId, appDomain,
    vars: { text: String(text || ""), user_id: String(userId), ...(vars || {}) },
  };
  console.log(`[LINE-FLOW-NODE] START flow=${flowId} channel=${channelId} user=${userId}`);
  await walk(ctx, nextNode(flow, start.id, "out"));
  return true;
}

/**
 * Can the parked session actually consume this reply? The controller MUST ask
 * before answering `consumed: true` — otherwise a reply the flow cannot take
 * still suppresses the PHP fallback chain (routing / AI / keyword replies) and
 * the thread goes silent until the session expires. Mirrors the `buttons`
 * matching rule in resumeFlow() below exactly, so the two can never disagree.
 */
export function canResume(channelId, userId, text) {
  const sess = LINE_SESSIONS.get(sessionKeyFor(channelId, userId));
  if (!sess) return false;
  const parked = indexNodes(sess.flow).get(String(sess.nodeId));
  if (!parked) return false;
  // `ask` takes ANY reply as its answer; only `buttons` needs a valid pick.
  if (String(parked.type || "") !== "buttons") return true;
  const opts = chatOptions(parked.data || {});
  const t = String(text || "").toLowerCase().trim();
  const asNum = parseInt(t, 10);
  if (Number.isInteger(asNum) && asNum >= 1 && asNum <= opts.length) return true;
  return opts.some((o) => t === String(o.title).toLowerCase().trim());
}

export async function resumeFlow({ channelId, userId, text, vars }) {
  const key = sessionKeyFor(channelId, userId);
  const sess = LINE_SESSIONS.get(key);
  if (!sess) { console.log(`[LINE-RESUME] no session key=${key}`); return false; }
  if (vars && typeof vars === "object") Object.assign(sess.vars, vars);
  const nodes = indexNodes(sess.flow);
  const parked = nodes.get(String(sess.nodeId));
  if (!parked) { console.warn(`[LINE-RESUME] parked node "${sess.nodeId}" missing — dropping`); LINE_SESSIONS.delete(key); return false; }

  const d = parked.data || {};
  const type = String(parked.type || "");
  const t = String(text || "").toLowerCase().trim();
  let port = "out";
  console.log(`[LINE-RESUME] key=${key} parkedNode=${sess.nodeId} type=${type} reply="${t.slice(0, 40)}"`);

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
    console.log(`[LINE-RESUME] button match reply="${t}" → idx=${idx}`);
    if (idx === null) return false; // not a valid pick — let normal handling take it
    port = `p${idx}`;
    const saveKey = String(d.var || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  LINE_SESSIONS.delete(key);
  sess.vars.text = String(text || "");
  console.log(`[LINE-FLOW-NODE] RESUME flow=${sess.flowId} from=${sess.nodeId} port=${port}`);
  await walk({ ...sess, vars: sess.vars }, nextNode(sess.flow, sess.nodeId, port));
  return true;
}

export default { runFlow, resumeFlow, hasSession, pruneSessions, delayMsOf };
