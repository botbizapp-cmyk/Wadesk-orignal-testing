// services/tiktokFlowService.js
// =============================
// TikTok Business-Messaging flow engine — the Node counterpart of
// facebookFlowService.js, a near-verbatim clone with ONLY the send layer swapped
// to the TikTok Business Messaging API.
//
// WHY: like Facebook/Instagram, TikTok DM flows need real `await` timers for Wait
// nodes and in-memory session parking for resume-on-reply — a webhook handler
// cannot sleep. Laravel hands the flow off (TtFlowBridge), Node walks the graph
// detached, and the customer's next message resumes a parked node.
//
// DIFFERENCE FROM FACEBOOK: TikTok delivers into a CONVERSATION (conversation_id)
// with the token in the Access-Token header, and its DM API has NO Messenger
// button/generic templates — so `buttons` render as a numbered text prompt and
// resume matches the typed number or the option label.
//
// PARTNER-GATED + REGION-LOCKED. Only runs for approved, in-region accounts.
// PURELY ADDITIVE — imported by nothing else.
import axios from "axios";

const TT_SESSIONS = new Map(); // `${accountId}_${convId}` → session

const nodeHeaders = () => ({
  "X-Node-Token": process.env.NODE_WEBHOOK_TOKEN || "",
  Accept: "application/json",
});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sessionKeyFor = (accountId, convId) => `${accountId}_${convId}`;

// ---------------------------------------------------------------------------
// TikTok Business Messaging send. Token in the Access-Token header; every call
// carries business_id + conversation_id. Only text + image are broadly
// supported, so richer nodes degrade to text.
// ---------------------------------------------------------------------------
async function ttSend(auth, convId, message) {
  const base = String(auth?.base || "").replace(/\/+$/, "");
  const businessId = String(auth?.businessId || "");
  const token = String(auth?.token || "");
  if (!base || !token) throw new Error("tiktok auth incomplete (base/token)");

  const kind = message?.type || "text";
  console.log(`[TT-SEND→] base=${base} conv=${convId} kind=${kind} body=${JSON.stringify(message)}`);

  const r = await axios.post(
    `${base}/business/messaging/message/send/`,
    { business_id: businessId, conversation_id: convId, message },
    { headers: { "Access-Token": token, "Content-Type": "application/json" }, timeout: 30000, validateStatus: () => true }
  );
  const failed = r.status >= 400 || (r.data && typeof r.data.code !== "undefined" && Number(r.data.code) !== 0);
  if (failed) {
    console.error(`[TT-SEND✗] kind=${kind} HTTP ${r.status} resp=${JSON.stringify(r.data)}`);
    throw new Error(`${r.data?.code || r.status}: ${r.data?.message || "tiktok send error"}`);
  }
  console.log(`[TT-SEND✓] kind=${kind} HTTP ${r.status}`);
  return r.data;
}

const midOf = (r) => r?.data?.message_id || r?.message_id || null;

const sendText = (auth, convId, text) => ttSend(auth, convId, { type: "text", text: String(text || "") });

const sendImage = (auth, convId, url) => ttSend(auth, convId, { type: "image", image_url: String(url) });

// TikTok has no button template — render the prompt + a numbered option list as
// text. Resume matches the typed number OR the option label.
const sendButtonsAsText = (auth, convId, text, opts) => {
  const lines = opts.map((o, i) => `${i + 1}) ${String(o.title ?? o)}`);
  const body = [String(text || "").trim(), ...lines].filter(Boolean).join("\n");
  return sendText(auth, convId, body);
};

// ---------------------------------------------------------------------------
// Laravel bridges — AI / webhook / logging stay on the PHP side.
// ---------------------------------------------------------------------------
async function logToLaravel(appDomain, payload) {
  try {
    await axios.post(`${appDomain}/api/tiktok/flow-log`, payload, { headers: nodeHeaders(), timeout: 15000 });
  } catch (e) {
    console.warn(`[TT-FLOW-NODE] flow-log failed: ${e?.message}`);
  }
}

async function askLaravel(appDomain, payload) {
  const r = await axios.post(`${appDomain}/api/tiktok/flow-node`, payload,
    { headers: nodeHeaders(), timeout: 60000, validateStatus: () => true });
  if (r.status >= 400) throw new Error(`flow-node HTTP ${r.status}: ${JSON.stringify(r.data)}`);
  return r.data || {};
}

// ---------------------------------------------------------------------------
// Graph helpers (identical to the Facebook engine)
// ---------------------------------------------------------------------------
const nodesOf = (flow) => (flow?.flowNodes || flow?.nodes || []);
const edgesOf = (flow) => (flow?.flowEdges || flow?.edges || []);

function indexNodes(flow) {
  const map = new Map();
  for (const n of nodesOf(flow)) if (n?.id) map.set(String(n.id), n);
  return map;
}

function nextNode(flow, nodeId, port = "out") {
  let any = null;
  for (const e of edgesOf(flow)) {
    if (String(e?.source) !== String(nodeId)) continue;
    if (any === null) any = String(e?.target || "");
    if (String(e?.sourceHandle || "out") === port) return String(e?.target || "");
  }
  return port === "out" ? any : null;
}

function entryNode(flow) {
  for (const n of nodesOf(flow)) if (String(n?.type) === "trigger") return n;
  return null;
}

const subst = (s, vars) =>
  String(s ?? "").replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_, k) => String(vars?.[k] ?? ""));

export function delayMsOf(d) {
  const amount = Number(d?.amount ?? d?.delay ?? d?.value ?? 0);
  if (!(amount > 0)) return 0;
  const unit = String(d?.unit || "min").toLowerCase();
  const mult = unit.startsWith("s") ? 1000
    : unit.startsWith("h") ? 3_600_000
      : unit.startsWith("d") ? 86_400_000
        : 60_000;
  return Math.round(amount * mult);
}

const chatOptions = (d) =>
  (d?.options || [])
    .map((o, i) => ({ title: String(typeof o === "object" ? (o.title ?? o.label ?? "") : o).trim(), payload: `OPT_${i}` }))
    .filter((o) => o.title !== "")
    .slice(0, 10);

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

// ---------------------------------------------------------------------------
// The walker
// ---------------------------------------------------------------------------
async function walk(ctx, startId) {
  const { auth, flow, convId, appDomain, accountId, flowId, workspaceId } = ctx;
  const nodes = indexNodes(flow);
  let current = startId;
  let guard = 0;

  console.log(`[TT-WALK] start flow=${flowId} conv=${convId} from=${startId} nodes=${nodes.size}`);

  while (current && guard++ < 100) {
    const node = nodes.get(String(current));
    if (!node) { console.warn(`[TT-WALK] node id="${current}" NOT FOUND — ending flow=${flowId}`); break; }
    const type = String(node.type || "");
    const d = node.data || {};
    let port = "out";

    console.log(`[TT-NODE] → type=${type} id=${node.id} flow=${flowId}`);

    try {
      switch (type) {
        case "message": {
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, convId, body);
            await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body, source: "flow", mid: midOf(r) });
          }
          break;
        }

        case "media": {
          let url = subst(d.url ?? d.mediaUrl, ctx.vars).trim();
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith("data:")) {
            url = `${String(appDomain).replace(/\/+$/, "")}${url.startsWith("/") ? "" : "/"}${url}`;
          }
          const kind = String(d.kind ?? d.mediaType ?? "image").toLowerCase();
          if (url && kind === "image") {
            const r = await sendImage(auth, convId, url);
            await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body: "[image]", source: "flow", mid: midOf(r) });
          } else if (url) {
            // TikTok DM only broadly supports image — send other media as a link.
            const r = await sendText(auth, convId, url);
            await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body: url, source: "flow", mid: midOf(r) });
          }
          const cap = subst(d.caption, ctx.vars).trim();
          if (cap) {
            const r = await sendText(auth, convId, cap);
            await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body: cap, source: "flow", mid: midOf(r) });
          }
          break;
        }

        case "buttons": {
          const body = subst(d.prompt ?? d.text, ctx.vars);
          const opts = chatOptions(d);
          const r = await sendButtonsAsText(auth, convId, body, opts);
          await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body, source: "flow", mid: midOf(r), buttons: opts.map((o) => ({ title: String(o.title ?? o) })) });
          park(ctx, node.id);
          return; // wait for the pick
        }

        case "ask": {
          const q = subst(d.prompt ?? d.question ?? d.text, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, convId, q);
            await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body: q, source: "flow", mid: midOf(r) });
          }
          park(ctx, node.id);
          return; // wait for the answer
        }

        case "delay": {
          const ms = delayMsOf(d);
          if (ms > 0) { console.log(`[TT-FLOW-NODE] delay node=${node.id} ${ms}ms`); await sleep(ms); }
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
        case "tt_ai": {
          const out = await askLaravel(appDomain, { action: "ai", node: d, vars: ctx.vars, workspaceId, accountId, convId });
          const reply = String(out?.reply || "");
          if (reply) {
            const r = await sendText(auth, convId, reply);
            await logToLaravel(appDomain, { accountId, convId, workspaceId, direction: "out", body: reply, source: "ai", mid: midOf(r) });
          }
          const saveKey = String(d.save || "").trim();
          if (saveKey) ctx.vars[saveKey] = reply;
          break;
        }

        case "end":
          clearSession(accountId, convId);
          console.log(`[TT-FLOW-NODE] end flow=${flowId} conv=${convId}`);
          return;

        default:
          console.warn(`[TT-FLOW-NODE] node type "${type}" has no executor — skipped (flow=${flowId} node=${node.id})`);
      }
    } catch (e) {
      console.error(`[TT-FLOW-NODE] node ${node.id} (${type}) failed: ${e?.message}`);
    }

    current = nextNode(flow, node.id, port);
  }

  if (guard >= 100) console.warn(`[TT-FLOW-NODE] walk hit the 100-node guard — possible loop (flow=${flowId})`);
  clearSession(accountId, convId);
}

// ---------------------------------------------------------------------------
// Session state — in memory.
// ---------------------------------------------------------------------------
function park(ctx, nodeId) {
  const key = sessionKeyFor(ctx.accountId, ctx.convId);
  TT_SESSIONS.set(key, {
    accountId: ctx.accountId, convId: ctx.convId, workspaceId: ctx.workspaceId,
    flowId: ctx.flowId, flow: ctx.flow, auth: ctx.auth, appDomain: ctx.appDomain,
    nodeId: String(nodeId), vars: ctx.vars, parkedAt: Date.now(),
  });
  console.log(`[TT-FLOW-NODE] parked at node=${nodeId} key=${key}`);
}

function clearSession(accountId, convId) {
  TT_SESSIONS.delete(sessionKeyFor(accountId, convId));
}

export const hasSession = (accountId, convId) => TT_SESSIONS.has(sessionKeyFor(accountId, convId));

/** Drop sessions parked longer than maxAgeMs (default 48h — TikTok's window). */
export function pruneSessions(maxAgeMs = 172_800_000) {
  const cutoff = Date.now() - maxAgeMs;
  let n = 0;
  for (const [k, s] of TT_SESSIONS) if (s.parkedAt < cutoff) { TT_SESSIONS.delete(k); n++; }
  return n;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------
export async function runFlow({ auth, flow, convId, text, flowId, accountId, workspaceId, appDomain, vars }) {
  clearSession(accountId, convId);
  const start = entryNode(flow);
  if (!start) { console.warn(`[TT-FLOW-NODE] flow ${flowId} has no trigger node`); return false; }

  const ctx = {
    auth, flow, convId, flowId, accountId, workspaceId, appDomain,
    vars: { text: String(text || ""), conv_id: String(convId), ...(vars || {}) },
  };
  console.log(`[TT-FLOW-NODE] START flow=${flowId} acct=${accountId} conv=${convId}`);
  await walk(ctx, nextNode(flow, start.id, "out"));
  return true;
}

export async function resumeFlow({ accountId, convId, text }) {
  const key = sessionKeyFor(accountId, convId);
  const sess = TT_SESSIONS.get(key);
  if (!sess) { console.log(`[TT-RESUME] no session key=${key}`); return false; }

  const nodes = indexNodes(sess.flow);
  const parked = nodes.get(String(sess.nodeId));
  if (!parked) { console.warn(`[TT-RESUME] parked node "${sess.nodeId}" missing — dropping session`); TT_SESSIONS.delete(key); return false; }

  const d = parked.data || {};
  const type = String(parked.type || "");
  const t = String(text || "").toLowerCase().trim();
  let port = "out";

  console.log(`[TT-RESUME] key=${key} parkedNode=${sess.nodeId} type=${type} reply="${t.slice(0, 40)}"`);

  if (type === "ask") {
    const saveKey = String(d.var || d.save || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
    const expected = (d.options || []).map((o) => String(o).trim()).filter(Boolean);
    if (expected.length) {
      port = "else";
      for (let i = 0; i < expected.length; i++) {
        if (t === expected[i].toLowerCase()) { port = `p${i}`; break; }
      }
    }
  }

  // Numbered-option buttons: match the typed number (1-based) or the label.
  if (type === "buttons") {
    const opts = chatOptions(d);
    let idx = null;
    const asNum = parseInt(t, 10);
    if (Number.isInteger(asNum) && asNum >= 1 && asNum <= opts.length) {
      idx = asNum - 1;
    } else {
      for (let i = 0; i < opts.length; i++) {
        if (t === String(opts[i].title).toLowerCase().trim() || t === String(opts[i].payload).toLowerCase()) { idx = i; break; }
      }
    }
    console.log(`[TT-RESUME] button match reply="${t}" → idx=${idx}`);
    if (idx === null) return false;   // not a valid pick — let normal handling take it
    port = `p${idx}`;
    const saveKey = String(d.var || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  TT_SESSIONS.delete(key);   // consumed
  sess.vars.text = String(text || "");
  console.log(`[TT-FLOW-NODE] RESUME flow=${sess.flowId} from=${sess.nodeId} port=${port}`);
  await walk({ ...sess, vars: sess.vars }, nextNode(sess.flow, sess.nodeId, port));
  return true;
}

export default { runFlow, resumeFlow, hasSession, pruneSessions, delayMsOf };
