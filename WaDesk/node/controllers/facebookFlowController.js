// controllers/facebookFlowController.js
// ======================================
// Facebook (Messenger) inbound → Node flow engine.
//
// Same shape as instagramFlowController.js (which did this for Instagram):
// Laravel receives Meta's webhook, verifies the signature, then hands the
// message here. We decide SYNCHRONOUSLY whether a flow consumes it, answer
// immediately, and run the flow detached.
//
// The synchronous `consumed` answer matters: Laravel skips its keyword
// auto-reply and AI agent when it is true, so the customer never gets a
// double reply — exactly how the Baileys and WABA paths behave.
//
// PURELY ADDITIVE — nothing here is imported by flowService.js or the Baileys
// manager. The WhatsApp path is untouched.
import { runFlow, resumeFlow, hasSession, pruneSessions } from "../services/facebookFlowService.js";

/**
 * POST /api/facebook-flow/inbound
 *
 * Body:
 *   pageId       (int)    facebook_pages.id
 *   workspaceId  (int)
 *   psid         (string) the customer's Page-scoped id
 *   text         (string) message text, or the payload of a quick-reply/postback tap
 *   auth         ({base, ig, token}) Graph creds — same shape scheduler receives
 *   flow         (object, optional) flow_data; required to START, unused to RESUME
 *   flowId       (int, optional)
 *   vars         (object, optional) extra starting variables
 *
 * Auth: X-Node-Token shared secret.
 *
 * Response: { ok, consumed, mode }
 *   consumed=true  → a flow took this message; Laravel must not auto-reply
 *   mode           → 'resume' | 'start' | 'none'
 */
export const facebookInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const pageId      = Number(req.body?.pageId || 0);
  const workspaceId = Number(req.body?.workspaceId || 0);
  const psid        = String(req.body?.psid || "");
  const text        = String(req.body?.text || "");
  const auth        = req.body?.auth || null;
  const flow        = req.body?.flow || null;
  const flowId      = req.body?.flowId ? String(req.body.flowId) : "";
  const vars        = req.body?.vars || {};
  const appDomain   = String(req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");

  if (!pageId || !psid) {
    return res.status(400).send({ ok: false, error: "pageId and psid required" });
  }

  // Only a real customer action may resume. Empty payloads (reactions, read
  // receipts, system events) must not re-enter a parked node — that is how the
  // WABA path once re-fired a parked AI node with empty input.
  const hasContent = text.trim() !== "";
  const isResume   = hasSession(pageId, psid) && hasContent;
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  console.log(`[FB-FLOW-NODE] IN page=${pageId} psid=${psid} text="${text.slice(0, 50)}" isResume=${isResume} canStart=${canStart}`);

  if (!isResume && !canStart) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  // Answer BEFORE running — a flow with a 5-minute Wait must never hold the
  // request open. This is the whole reason Facebook flows moved into Node.
  res.status(202).send({ ok: true, consumed: true, mode: isResume ? "resume" : "start" });

  // Opportunistic housekeeping: drop sessions parked past Meta's 24h window.
  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (isResume) {
        const done = await resumeFlow({ pageId, psid, text });
        // resumeFlow returns false when the reply didn't match any branch of
        // the parked node (e.g. free text while we expected a button tap). The
        // session is left intact so the customer can still tap.
        if (!done) console.log(`[FB-FLOW-NODE] resume declined (no matching branch) page=${pageId} psid=${psid}`);
        return;
      }
      await runFlow({ auth, flow, psid, text, flowId, pageId, workspaceId, appDomain, vars });
    } catch (e) {
      console.error(`[FB-FLOW-NODE] handler crashed page=${pageId} psid=${psid}: ${e?.message}`);
    }
  })();
};

/**
 * GET /api/facebook-flow/health
 * Lets Laravel check whether the Node engine is reachable before handing off,
 * so it can fall back to the in-process PHP runner instead of dropping the
 * flow when Node is down.
 */
export const facebookFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "facebook-flow" });
};

export default { facebookInbound, facebookFlowHealth };
