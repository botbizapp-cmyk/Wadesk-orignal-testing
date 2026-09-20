// controllers/lineFlowController.js
// =================================
// LINE (Messaging API) inbound → Node flow engine. Same shape as
// telegramFlowController / facebookFlowController: Laravel verifies the webhook
// signature, hands the message here, we decide SYNCHRONOUSLY whether a flow
// consumes it, answer immediately, and run the flow detached (Delay = real
// await).
//
// PURELY ADDITIVE — imported by nothing else. The recipient key is the LINE
// userId.
import { runFlow, resumeFlow, canResume, hasSession, pruneSessions } from "../services/lineFlowService.js";

/**
 * POST /api/line-flow/inbound
 *   channelId, workspaceId, userId, text, auth({base, token}), replyToken?, flow?, flowId?, vars?
 * Auth: X-Node-Token. Response: { ok, consumed, mode }
 */
export const lineInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const channelId   = Number(req.body?.channelId || 0);
  const workspaceId = Number(req.body?.workspaceId || 0);
  const userId      = String(req.body?.userId || "");
  const text        = String(req.body?.text || "");
  const auth        = req.body?.auth || null;
  const flow        = req.body?.flow || null;
  const flowId      = req.body?.flowId ? String(req.body.flowId) : "";
  const vars        = req.body?.vars || {};
  const appDomain   = String(req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");

  if (!channelId || !userId) {
    return res.status(400).send({ ok: false, error: "channelId and userId required" });
  }

  const hasContent = text.trim() !== "";
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  // A matched start-flow RE-FIRES only when nothing is parked (a keyword hit
  // while a flow is mid-run is treated as an answer to the parked node, not a
  // restart — otherwise mid-flow keywords would hijack the conversation). A
  // NON-matching reply RESUMES a parked flow.
  const restart  = canStart && !hasSession(channelId, userId);
  // canResume() must decide BEFORE the 202 below: answering `consumed: true`
  // suppresses the PHP fallback chain, so a reply the parked node cannot
  // take has to come back `consumed: false` or the thread goes silent.
  const isResume = !restart && hasSession(channelId, userId) && hasContent
                   && canResume(channelId, userId, text);

  console.log(`[LINE-FLOW-NODE] IN channel=${channelId} user=${userId} text="${text.slice(0, 50)}" restart=${restart} isResume=${isResume} canStart=${canStart}`);

  if (!restart && !isResume) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  // Answer BEFORE running — a Wait node must never hold the request open.
  res.status(202).send({ ok: true, consumed: true, mode: restart ? "start" : "resume" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (restart) {
        await runFlow({ auth, flow, userId, text, flowId, channelId, workspaceId, appDomain, vars });
        return;
      }
      const done = await resumeFlow({ channelId, userId, text, vars });
      if (!done) console.log(`[LINE-FLOW-NODE] resume declined (no matching branch) channel=${channelId} user=${userId}`);
    } catch (e) {
      console.error(`[LINE-FLOW-NODE] handler crashed channel=${channelId} user=${userId}: ${e?.message}`);
    }
  })();
};

/** GET /api/line-flow/health */
export const lineFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "line-flow" });
};

export default { lineInbound, lineFlowHealth };
