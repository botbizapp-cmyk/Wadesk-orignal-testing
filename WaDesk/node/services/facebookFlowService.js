// services/facebookFlowService.js
// ================================
// Facebook Messenger flow engine — the Node counterpart of flowService.js and
// a near-verbatim clone of instagramFlowService.js with ONLY the send layer
// swapped to the Facebook Messenger (Page) Send API.
//
// WHY THIS EXISTS
// ---------------
// Facebook Messenger flows used to run in PHP inside Meta's webhook request.
// That works, but a webhook handler cannot sleep, so a "Wait 5 min" node had to
// park the session in the DB and rely on a later request to sweep it — meaning
// the wait fired late on a quiet Page. Baileys flows never had that problem
// because Node is a long-lived process and can simply `await` a timer.
//
// This module gives Messenger the SAME model as WhatsApp/Instagram: Laravel
// hands the flow off, Node walks it in the background, real awaits for delays,
// and the customer's next message resumes a parked node.
//
// PURELY ADDITIVE. Nothing here is imported by flowService.js or the Baileys
// client manager — the WhatsApp path is untouched. Mirrors the precedent set by
// instagramFlowService.js, which added Instagram the same way.
//
// DIVISION OF LABOUR
//   Node    → walks the graph, times the delays, calls the Send API to send
//   Laravel → business logic that needs DB/keys (AI reply, catalog carousel,
//             lead capture) and all message logging, reached over
//             /api/facebook/flow-node and /api/facebook/flow-log.
import axios from "axios";

const FB_SESSIONS = new Map(); // `${pageId}_${psid}` → session

const nodeHeaders = () => ({
  "X-Node-Token": process.env.NODE_WEBHOOK_TOKEN || "",
  Accept: "application/json",
});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sessionKeyFor = (pageId, psid) => `${pageId}_${psid}`;

// ---------------------------------------------------------------------------
// Facebook Messenger Send API. Deliberately self-contained — these are the
// message shapes a Messenger flow needs. Unlike Instagram, Facebook supports
// the button template and the 'file' (document) attachment type.
// ---------------------------------------------------------------------------
async function graphSend(auth, message) {
  const base = String(auth?.base || "").replace(/\/+$/, "");
  const pageId = String(auth?.pageId || auth?.page_id || "");
  const token = String(auth?.token || "");
  if (!base || !pageId || !token) throw new Error("facebook auth incomplete (base/pageId/token)");

  // FULL request logging — the exact JSON body sent to Meta (token redacted).
  const kind = message?.message?.attachment?.payload?.template_type
    ? `template:${message.message.attachment.payload.template_type}`
    : message?.message?.attachment?.type
      ? `attachment:${message.message.attachment.type}`
      : message?.message?.quick_replies
        ? "quick_replies"
        : "text";
  console.log(`[FB-SEND→] host=${base} page=${pageId} kind=${kind} body=${JSON.stringify(message)}`);

  const r = await axios.post(
    `${base}/${pageId}/messages`,
    message,
    { params: { access_token: token }, timeout: 30000, validateStatus: () => true }
  );
  if (r.status >= 400 || r.data?.error) {
    const e = r.data?.error || { message: `HTTP ${r.status}` };
    console.error(`[FB-SEND✗] kind=${kind} HTTP ${r.status} error=${JSON.stringify(r.data?.error || r.data)}`);
    throw new Error(`${e.code || r.status}/${e.error_subcode || 0}: ${e.message || "graph error"}`);
  }
  console.log(`[FB-SEND✓] kind=${kind} HTTP ${r.status} resp=${JSON.stringify(r.data)}`);
  return r.data;
}

const sendText = (auth, psid, text) =>
  graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: { text: String(text || "") },
  });

// Facebook supports 'image', 'video', 'audio' AND 'file' (documents) — pass the
// type straight through so all four keep working.
const sendAttachment = (auth, psid, type, url) =>
  graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: { attachment: { type, payload: { url: String(url), is_reusable: true } } },
  });

// Meta caps quick replies at 13 and titles at 20 chars; over either and the
// whole send is rejected, so clamp rather than fail.
const sendQuickReplies = (auth, psid, text, options) =>
  graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: {
      text: String(text || ""),
      quick_replies: options.slice(0, 13).map((o, i) => ({
        content_type: "text",
        title: String(o.title ?? o).slice(0, 20),
        payload: String(o.payload ?? `OPT_${i}`),
      })),
    },
  });

// Facebook DOES support the Messenger "button" template (unlike Instagram). It
// renders text with up to 3 persistent, tappable buttons. If an imageUrl is
// supplied we upgrade to a single-element "generic" card so the image sits above
// the buttons; otherwise the plain button template is the primary shape.
const sendButtons = (auth, psid, text, buttons, imageUrl) => {
  const mapped = (buttons || []).slice(0, 3).map((b, i) => (
    String(b.type) === "web_url"
      ? { type: "web_url", url: String(b.url || ""), title: String(b.title || "").slice(0, 20) }
      : { type: "postback", title: String(b.title || b).slice(0, 20), payload: String(b.payload ?? b.title ?? `OPT_${i}`) }
  ));
  if (imageUrl) {
    // Generic card variant — image_url + the same buttons on one element.
    return graphSend(auth, {
      recipient: { id: psid },
      messaging_type: "RESPONSE",
      message: {
        attachment: {
          type: "template",
          payload: {
            template_type: "generic",
            elements: [{
              title: String(text || "Choose one").slice(0, 80) || "Choose one",
              image_url: String(imageUrl),
              buttons: mapped,
            }],
          },
        },
      },
    });
  }
  // Primary: button template.
  return graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: {
      attachment: {
        type: "template",
        payload: {
          template_type: "button",
          text: String(text || "Choose one").slice(0, 640) || "Choose one",
          buttons: mapped,
        },
      },
    },
  });
};

// The shared "buttons" node renders ≤3 options as a persistent card. On Facebook
// the generic template with one element does this cleanly (image optional).
const sendGenericButtons = (auth, psid, text, buttons, imageUrl) => {
  const el = {
    title: String(text || "Choose one").slice(0, 80) || "Choose one",
    buttons: buttons.slice(0, 3).map((b, i) => (
      String(b.type) === "web_url"
        ? { type: "web_url", url: String(b.url || ""), title: String(b.title || "").slice(0, 20) }
        : { type: "postback", title: String(b.title || b).slice(0, 20), payload: String(b.payload ?? b.title ?? `OPT_${i}`) }
    )),
  };
  if (imageUrl) el.image_url = String(imageUrl);
  return graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: { attachment: { type: "template", payload: { template_type: "generic", elements: [el] } } },
  });
};

// ---------------------------------------------------------------------------
// Laravel bridges — business logic and logging stay on the PHP side so there is
// exactly ONE implementation of AI / catalog / lead capture in the codebase.
// ---------------------------------------------------------------------------
async function logToLaravel(appDomain, payload) {
  try {
    await axios.post(`${appDomain}/api/facebook/flow-log`, payload,
      { headers: nodeHeaders(), timeout: 15000 });
  } catch (e) {
    // Never let a logging failure break the conversation.
    console.warn(`[FB-FLOW-NODE] flow-log failed: ${e?.message}`);
  }
}

async function askLaravel(appDomain, payload) {
  const r = await axios.post(`${appDomain}/api/facebook/flow-node`, payload,
    { headers: nodeHeaders(), timeout: 60000, validateStatus: () => true });
  if (r.status >= 400) throw new Error(`flow-node HTTP ${r.status}: ${JSON.stringify(r.data)}`);
  return r.data || {};
}

// ---------------------------------------------------------------------------
// Graph helpers
// ---------------------------------------------------------------------------
const nodesOf = (flow) => (flow?.flowNodes || flow?.nodes || []);
const edgesOf = (flow) => (flow?.flowEdges || flow?.edges || []);

function indexNodes(flow) {
  const map = new Map();
  for (const n of nodesOf(flow)) if (n?.id) map.set(String(n.id), n);
  return map;
}

/** Follow the edge leaving nodeId on `port`, falling back to any out edge. */
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

/** Wait node {amount, unit} → ms. Unknown unit falls back to minutes. */
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

/** Chat `buttons` options are plain strings; the Send API wants objects. */
const chatOptionsToQuickReplies = (d) =>
  (d?.options || [])
    .map((o, i) => ({ title: String(typeof o === "object" ? (o.title ?? o.label ?? "") : o).trim(), payload: `OPT_${i}` }))
    .filter((o) => o.title !== "")
    .slice(0, 13);

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
/**
 * Walk from `startId` until the flow ends or parks on a node awaiting the
 * customer. Delays are REAL awaits — this runs detached from any HTTP request,
 * which is the whole reason Messenger flows moved into Node.
 */
async function walk(ctx, startId) {
  const { auth, flow, psid, appDomain, pageId, flowId, workspaceId } = ctx;
  const nodes = indexNodes(flow);
  let current = startId;
  let guard = 0;

  console.log(`[FB-WALK] start flow=${flowId} psid=${psid} from=${startId} nodes=${nodes.size} vars=${JSON.stringify(ctx.vars || {})}`);

  while (current && guard++ < 100) {
    const node = nodes.get(String(current));
    if (!node) { console.warn(`[FB-WALK] node id="${current}" NOT FOUND — ending flow=${flowId}`); break; }
    const type = String(node.type || "");
    const d = node.data || {};
    let port = "out";

    console.log(`[FB-NODE] → type=${type} id=${node.id} flow=${flowId} data=${JSON.stringify(d).slice(0, 300)}`);

    try {
      switch (type) {
        // ---- shared nodes (same types the WhatsApp builder uses) ----------
        case "message": {
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, psid, body);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
          }
          break;
        }

        case "media": {
          let url = subst(d.url ?? d.mediaUrl, ctx.vars).trim();
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith("data:")) {
            url = `${String(appDomain).replace(/\/+$/, "")}${url.startsWith("/") ? "" : "/"}${url}`;
          }
          let kind = String(d.kind ?? d.mediaType ?? "image").toLowerCase();
          if (kind === "document") kind = "file"; // Facebook's document attachment type
          if (url && ["image", "video", "audio", "file"].includes(kind)) {
            const r = await sendAttachment(auth, psid, kind, url);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: `[${kind}]`, source: "flow", mid: r?.message_id || null });
          } else if (url) {
            // Unknown kind — send the link as text rather than dropping the node.
            const r = await sendText(auth, psid, url);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: url, source: "flow", mid: r?.message_id || null });
          }
          const cap = subst(d.caption, ctx.vars).trim();
          if (cap) {
            const r = await sendText(auth, psid, cap);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: cap, source: "flow", mid: r?.message_id || null });
          }
          break;
        }

        case "buttons": {
          const body = subst(d.prompt ?? d.text, ctx.vars);
          const opts = chatOptionsToQuickReplies(d);
          // ≤3 options → persistent generic-template buttons. >3 → quick-reply
          // chips (generic/button templates cap at 3).
          const mode = (opts.length > 0 && opts.length <= 3) ? "generic-buttons" : "quick-replies";
          const r = mode === "generic-buttons"
            ? await sendGenericButtons(auth, psid, body, opts)
            : await sendQuickReplies(auth, psid, body, opts);
          console.log(`[FB-FLOW-NODE] buttons node=${node.id} mode=${mode} opts=${opts.length} resp=${JSON.stringify(r).slice(0, 200)}`);
          // Mirror the buttons into the inbox so the operator sees the same
          // tappable card, not just "What next?" as plain text.
          await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null, buttons: opts.map((o) => ({ title: String(o.title ?? o) })) });
          park(ctx, node.id);
          return; // wait for the tap
        }

        case "ask": {
          const q = subst(d.prompt ?? d.question ?? d.text, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, psid, q);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: q, source: "flow", mid: r?.message_id || null });
          }
          park(ctx, node.id);
          return; // wait for the answer
        }

        case "delay": {
          // THE POINT OF THIS MODULE. Node is long-lived, so a wait is just a
          // timer — no DB parking, no sweep, no dependence on later traffic.
          const ms = delayMsOf(d);
          if (ms > 0) {
            console.log(`[FB-FLOW-NODE] delay node=${node.id} ${ms}ms flow=${flowId}`);
            await sleep(ms);
          }
          break;
        }

        case "condition":
          port = evalCondition(d, ctx.vars) ? "yes" : "no";
          break;

        case "webhook": {
          // Laravel owns this: it already has the SSRF guard (scheme + public-IP
          // check) that must apply to an operator-supplied URL.
          const out = await askLaravel(appDomain, {
            action: "webhook", node: d, vars: ctx.vars, workspaceId,
          });
          if (out?.vars) Object.assign(ctx.vars, out.vars);
          break;
        }

        // ---- nodes whose logic lives in Laravel (AI keys, catalog, CRM) ----
        case "ai":
        case "fb_ai": {
          const out = await askLaravel(appDomain, {
            action: "ai", node: d, vars: ctx.vars, workspaceId, pageId, psid,
          });
          const reply = String(out?.reply || "");
          if (reply) {
            const r = await sendText(auth, psid, reply);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: reply, source: "ai", mid: r?.message_id || null });
          }
          const saveKey = String(d.save || "").trim();
          if (saveKey) ctx.vars[saveKey] = reply;
          break;
        }

        case "fb_gallery":
        case "fb_products":
        case "fb_lead":
        case "fb_reply_comment": {
          // Catalog carousel / lead+deal creation / public comment reply all
          // need DB access — hand back to Laravel, which already implements
          // each one and logs its own outbound message.
          const out = await askLaravel(appDomain, {
            action: type, node: d, vars: ctx.vars, workspaceId, pageId, psid,
            commentId: ctx.vars.comment_id || "",
          });
          if (out?.vars) Object.assign(ctx.vars, out.vars);
          break;
        }

        case "fb_send_dm": {   // legacy node, still runs on older flows
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, psid, body);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
          }
          break;
        }

        case "fb_quick": {     // legacy node
          const body = subst(d.text, ctx.vars);
          const r = await sendQuickReplies(auth, psid, body, (d.options || []));
          await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
          park(ctx, node.id);
          return;
        }

        case "fb_ask": {       // legacy node
          const q = subst(d.question, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, psid, q);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: q, source: "flow", mid: r?.message_id || null });
          }
          park(ctx, node.id);
          return;
        }

        case "fb_buttons": {
          const body = subst(d.text, ctx.vars);
          const btns = d.buttons || [];
          const img = subst(d.imageUrl ?? d.image_url, ctx.vars).trim();
          const r = await sendButtons(auth, psid, body, btns, img || undefined);
          await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null, buttons: btns.map((b) => ({ title: String(b.title || ""), url: String(b.url || "") })) });
          park(ctx, node.id);
          return;
        }

        case "fb_to_whatsapp": {
          // Cross-channel handoff: move this Messenger conversation to WhatsApp.
          const mode = (d.mode === "direct") ? "direct" : "deeplink";
          if (mode === "deeplink") {
            // Send a wa.me click-to-chat link. The USER taps it and messages
            // your WhatsApp — fully Meta-compliant. A matching keyword-trigger
            // WhatsApp flow then auto-starts from the pre-filled text.
            const waNum = String(subst(d.waNumber, ctx.vars) || "").replace(/\D+/g, "");
            const prefill = subst(d.prefillText, ctx.vars) || "";
            if (waNum) {
              const link = `https://wa.me/${waNum}${prefill ? `?text=${encodeURIComponent(prefill)}` : ""}`;
              const intro = subst(d.introText, ctx.vars).trim();
              const body = intro ? `${intro}\n${link}` : link;
              const r = await sendText(auth, psid, body);
              await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
            } else {
              console.warn(`[FB-FLOW-NODE] fb_to_whatsapp deeplink node=${node.id} has no waNumber — skipping`);
            }
          } else {
            // Direct: hand the captured WhatsApp number to Laravel, which
            // resolves the workspace's WA device and STARTS the target flow.
            // (Meta requires the number to have opted in / be in a 24h window.)
            const intro = subst(d.introText, ctx.vars).trim();
            if (intro) {
              const r = await sendText(auth, psid, intro);
              await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: intro, source: "flow", mid: r?.message_id || null });
            }
            const out = await askLaravel(appDomain, {
              action: "fb_to_whatsapp", node: d, vars: ctx.vars, workspaceId, pageId, psid,
            });
            if (out?.vars) Object.assign(ctx.vars, out.vars);
          }
          break;
        }

        case "end":
          clearSession(pageId, psid);
          console.log(`[FB-FLOW-NODE] end flow=${flowId} psid=${psid}`);
          return;

        default:
          // A node this engine doesn't implement must be LOUD, never a silent
          // skip — silent skips are exactly how the old PHP runner hid bugs.
          console.warn(`[FB-FLOW-NODE] node type "${type}" has no executor — skipped (flow=${flowId} node=${node.id})`);
      }
    } catch (e) {
      console.error(`[FB-FLOW-NODE] node ${node.id} (${type}) failed: ${e?.message}`);
      // Keep walking: one bad node shouldn't strand the customer mid-conversation.
    }

    current = nextNode(flow, node.id, port);
  }

  if (guard >= 100) console.warn(`[FB-FLOW-NODE] walk hit the 100-node guard — possible loop (flow=${flowId})`);
  clearSession(pageId, psid);
}

// ---------------------------------------------------------------------------
// Session state — in memory, like Baileys' activeFlowSessions.
// ---------------------------------------------------------------------------
function park(ctx, nodeId) {
  const key = sessionKeyFor(ctx.pageId, ctx.psid);
  FB_SESSIONS.set(key, {
    pageId: ctx.pageId, psid: ctx.psid, workspaceId: ctx.workspaceId,
    flowId: ctx.flowId, flow: ctx.flow, auth: ctx.auth, appDomain: ctx.appDomain,
    nodeId: String(nodeId), vars: ctx.vars, parkedAt: Date.now(),
  });
  console.log(`[FB-FLOW-NODE] parked at node=${nodeId} key=${key}`);
}

function clearSession(pageId, psid) {
  FB_SESSIONS.delete(sessionKeyFor(pageId, psid));
}

export const hasSession = (pageId, psid) => FB_SESSIONS.has(sessionKeyFor(pageId, psid));

/** Drop sessions parked longer than `maxAgeMs` (default 24h — Meta's window). */
export function pruneSessions(maxAgeMs = 86_400_000) {
  const cutoff = Date.now() - maxAgeMs;
  let n = 0;
  for (const [k, s] of FB_SESSIONS) if (s.parkedAt < cutoff) { FB_SESSIONS.delete(k); n++; }
  return n;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------
/** Fresh run from the Trigger node. Fire-and-forget — never await in a webhook. */
export async function runFlow({ auth, flow, psid, text, commentId, flowId, pageId, workspaceId, appDomain, vars }) {
  clearSession(pageId, psid);
  const start = entryNode(flow);
  if (!start) { console.warn(`[FB-FLOW-NODE] flow ${flowId} has no trigger node`); return false; }

  const ctx = {
    auth, flow, psid, flowId, pageId, workspaceId, appDomain,
    vars: { text: String(text || ""), psid: String(psid), page_id: String(pageId || ""), comment_id: String(commentId || ""), ...(vars || {}) },
  };
  console.log(`[FB-FLOW-NODE] START flow=${flowId} page=${pageId} psid=${psid}`);
  await walk(ctx, nextNode(flow, start.id, "out"));
  return true;
}

/**
 * Resume a parked flow from the customer's reply.
 * @returns true if a session was found and consumed.
 */
export async function resumeFlow({ pageId, psid, text }) {
  const key = sessionKeyFor(pageId, psid);
  const sess = FB_SESSIONS.get(key);
  if (!sess) { console.log(`[FB-RESUME] no session key=${key}`); return false; }

  const nodes = indexNodes(sess.flow);
  const parked = nodes.get(String(sess.nodeId));
  if (!parked) { console.warn(`[FB-RESUME] parked node "${sess.nodeId}" missing — dropping session`); FB_SESSIONS.delete(key); return false; }

  const d = parked.data || {};
  const type = String(parked.type || "");
  const t = String(text || "").toLowerCase().trim();
  let port = "out";

  console.log(`[FB-RESUME] key=${key} parkedNode=${sess.nodeId} type=${type} reply="${t.slice(0, 40)}"`);

  // Ask nodes: the reply IS the answer.
  if (type === "ask" || type === "fb_ask") {
    const saveKey = String(d.var || d.save || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  // Expected-answer branching on the shared `ask` node (p0..pN + else).
  if (type === "ask") {
    const expected = (d.options || []).map((o) => String(o).trim()).filter(Boolean);
    if (expected.length) {
      port = "else";
      for (let i = 0; i < expected.length; i++) {
        if (t === expected[i].toLowerCase()) { port = `p${i}`; break; }
      }
    }
  }

  // Quick-reply / button taps arrive as the PAYLOAD, not the visible title —
  // match payload first, then fall back to a typed-out label.
  if (type === "buttons" || type === "fb_quick" || type === "fb_buttons") {
    const opts = type === "buttons"
      ? chatOptionsToQuickReplies(d)
      : (type === "fb_quick" ? (d.options || []) : (d.buttons || []))
        .map((o, i) => ({ title: String(o.title || ""), payload: String(o.payload || `OPT_${i}`) }));
    let idx = null;
    for (let i = 0; i < opts.length; i++) {
      if (t === String(opts[i].payload).toLowerCase() || t === String(opts[i].title).toLowerCase().trim()) { idx = i; break; }
    }
    console.log(`[FB-RESUME] button match reply="${t}" opts=${JSON.stringify(opts)} → idx=${idx}`);
    if (idx === null) return false;   // not a tap — let normal handling take it
    port = `p${idx}`;
    const saveKey = String(d.var || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  FB_SESSIONS.delete(key);   // consumed
  sess.vars.text = String(text || "");
  console.log(`[FB-FLOW-NODE] RESUME flow=${sess.flowId} from=${sess.nodeId} port=${port}`);
  await walk({ ...sess, vars: sess.vars }, nextNode(sess.flow, sess.nodeId, port));
  return true;
}

export default { runFlow, resumeFlow, hasSession, pruneSessions, delayMsOf };
