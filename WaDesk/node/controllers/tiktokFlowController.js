// controllers/tiktokFlowController.js
// ====================================
// TikTok (Business Messaging) inbound → Node flow engine.
//
// Same shape as facebookFlowController.js: Laravel receives the DM webhook,
// verifies it, then hands the message here. We decide SYNCHRONOUSLY whether a
// flow consumes it, answer immediately, and run the flow detached.
//
// The synchronous `consumed` answer matters: Laravel skips its keyword
// auto-reply and AI agent when it is true, so the customer never gets a
// double reply.
//
// PURELY ADDITIVE — nothing here is imported by flowService.js, the Baileys
// manager, or the Facebook engine. TikTok DM is partner-gated + region-locked;
// this only ever runs for approved, in-region accounts.
import { runFlow, resumeFlow, hasSession, pruneSessions } from "../services/tiktokFlowService.js";

/**
 * POST /api/tiktok-flow/inbound
 *
 * Body:
 *   accountId    (int)    tiktok_accounts.id
 *   openId       (string)
 *   workspaceId  (int)
 *   convId       (string) the Business-Messaging conversation id (the recipient)
 *   text         (string) message text / quick-reply payload
 *   auth         ({base, businessId, token}) Business Messaging creds
 *   flow         (object, optional) flow_data; required to START, unused to RESUME
 *   flowId       (int, optional)
 *   vars         (object, optional)
 *
 * Auth: X-Node-Token shared secret.
 * Response: { ok, consumed, mode }
 */
export const tiktokInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const accountId   = Number(req.body?.accountId || 0);
  const workspaceId = Number(req.body?.workspaceId || 0);
  const convId      = String(req.body?.convId || "");
  const text        = String(req.body?.text || "");
  const auth        = req.body?.auth || null;
  const flow        = req.body?.flow || null;
  const flowId      = req.body?.flowId ? String(req.body.flowId) : "";
  const vars        = req.body?.vars || {};
  const appDomain   = String(req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");

  if (!accountId || !convId) {
    return res.status(400).send({ ok: false, error: "accountId and convId required" });
  }

  const hasContent = text.trim() !== "";
  const isResume   = hasSession(accountId, convId) && hasContent;
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  console.log(`[TT-FLOW-NODE] IN acct=${accountId} conv=${convId} text="${text.slice(0, 50)}" isResume=${isResume} canStart=${canStart}`);

  if (!isResume && !canStart) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  // Answer BEFORE running — a flow with a Wait node must never hold the request.
  res.status(202).send({ ok: true, consumed: true, mode: isResume ? "resume" : "start" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (isResume) {
        const done = await resumeFlow({ accountId, convId, text });
        if (!done) console.log(`[TT-FLOW-NODE] resume declined (no matching branch) acct=${accountId} conv=${convId}`);
        return;
      }
      await runFlow({ auth, flow, convId, text, flowId, accountId, workspaceId, appDomain, vars });
    } catch (e) {
      console.error(`[TT-FLOW-NODE] handler crashed acct=${accountId} conv=${convId}: ${e?.message}`);
    }
  })();
};

/** GET /api/tiktok-flow/health */
export const tiktokFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "tiktok-flow" });
};

export default { tiktokInbound, tiktokFlowHealth };
