// controllers/emailFlowController.js
// ==================================
// Email (MailTrixy mirror) inbound → Node flow engine. Same shape as
// wechatFlowController: Laravel ingests the MailTrixy webhook, hands the
// message here, we decide SYNCHRONOUSLY whether a flow consumes it, answer
// immediately, and run the flow detached (Delay = real await). Sends are
// delegated back to PHP (MailTrixy creds stay server-side). PURELY ADDITIVE.
// Key = the WorkspaceEmailAccount mirror row id + MailTrixy conversation id.
import { runFlow, resumeFlow, canResume, hasSession, pruneSessions } from "../services/emailFlowService.js";

/**
 * POST /api/email-flow/inbound
 *   auth{base,token}?, accountId, conversationId, workspaceId, text, flow?, flowId?, vars?
 * Auth: X-Node-Token. Response: { ok, consumed, mode }
 */
export const emailInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const auth           = req.body?.auth || {};
  const accountId      = Number(req.body?.accountId || req.body?.mirrorId || 0);
  const conversationId = String(req.body?.conversationId ?? req.body?.mtxConvId ?? "");
  const workspaceId    = Number(req.body?.workspaceId || 0);
  const text           = String(req.body?.text || "");
  const flow           = req.body?.flow || null;
  const flowId         = req.body?.flowId ? String(req.body.flowId) : "";
  const vars           = req.body?.vars || {};
  const appDomain      = String(auth.base || req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");
  const authToken      = String(auth.token || "");

  if (!accountId || !conversationId) {
    return res.status(400).send({ ok: false, error: "accountId and conversationId required" });
  }

  const hasContent = text.trim() !== "";
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  const restart  = canStart && !hasSession(accountId, conversationId);
  // canResume() must decide BEFORE the 202 below: answering `consumed: true`
  // makes PHP skip routing / AI / keyword replies, so a reply the parked node
  // cannot take has to come back `consumed: false` or the customer gets
  // nothing at all.
  const isResume = !restart && hasSession(accountId, conversationId) && hasContent
                   && canResume(accountId, conversationId, text);

  console.log(`[EM-FLOW-NODE] IN account=${accountId} conversation=${conversationId} text="${text.slice(0, 50)}" restart=${restart} isResume=${isResume} canStart=${canStart}`);

  if (!restart && !isResume) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  res.status(202).send({ ok: true, consumed: true, mode: restart ? "start" : "resume" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (restart) {
        await runFlow({ flow, conversationId, text, flowId, accountId, workspaceId, appDomain, authToken, vars });
        return;
      }
      const done = await resumeFlow({ accountId, conversationId, text, vars });
      if (!done) console.log(`[EM-FLOW-NODE] resume declined (no matching branch) account=${accountId} conversation=${conversationId}`);
    } catch (e) {
      console.error(`[EM-FLOW-NODE] handler crashed account=${accountId} conversation=${conversationId}: ${e?.message}`);
    }
  })();
};

/** GET /api/email-flow/health */
export const emailFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "email-flow" });
};

export default { emailInbound, emailFlowHealth };
