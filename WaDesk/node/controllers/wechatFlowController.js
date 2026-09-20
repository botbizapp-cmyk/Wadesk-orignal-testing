// controllers/wechatFlowController.js
// ==================================
// WeChat Official Account inbound → Node flow engine. Same shape as
// lineFlowController: Laravel verifies the webhook signature, hands the message
// here, we decide SYNCHRONOUSLY whether a flow consumes it, answer immediately,
// and run the flow detached (Delay = real await). Sends are delegated back to
// PHP (the WeChat token is managed there). PURELY ADDITIVE. Key = the OpenID.
import { runFlow, resumeFlow, canResume, hasSession, pruneSessions } from "../services/wechatFlowService.js";

/**
 * POST /api/wechat-flow/inbound
 *   channelId, workspaceId, openid, text, flow?, flowId?, vars?
 * Auth: X-Node-Token. Response: { ok, consumed, mode }
 */
export const wechatInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const channelId   = Number(req.body?.channelId || 0);
  const workspaceId = Number(req.body?.workspaceId || 0);
  const openid      = String(req.body?.openid || "");
  const text        = String(req.body?.text || "");
  const flow        = req.body?.flow || null;
  const flowId      = req.body?.flowId ? String(req.body.flowId) : "";
  const vars        = req.body?.vars || {};
  const appDomain   = String(req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");

  if (!channelId || !openid) {
    return res.status(400).send({ ok: false, error: "channelId and openid required" });
  }

  const hasContent = text.trim() !== "";
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  const restart  = canStart && !hasSession(channelId, openid);
  // canResume() must decide BEFORE the 202 below: answering `consumed: true`
  // suppresses the PHP fallback chain, so a reply the parked node cannot
  // take has to come back `consumed: false` or the thread goes silent.
  const isResume = !restart && hasSession(channelId, openid) && hasContent
                   && canResume(channelId, openid, text);

  console.log(`[WC-FLOW-NODE] IN channel=${channelId} openid=${openid} text="${text.slice(0, 50)}" restart=${restart} isResume=${isResume} canStart=${canStart}`);

  if (!restart && !isResume) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  res.status(202).send({ ok: true, consumed: true, mode: restart ? "start" : "resume" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (restart) {
        await runFlow({ flow, openid, text, flowId, channelId, workspaceId, appDomain, vars });
        return;
      }
      const done = await resumeFlow({ channelId, openid, text, vars });
      if (!done) console.log(`[WC-FLOW-NODE] resume declined (no matching branch) channel=${channelId} openid=${openid}`);
    } catch (e) {
      console.error(`[WC-FLOW-NODE] handler crashed channel=${channelId} openid=${openid}: ${e?.message}`);
    }
  })();
};

/** GET /api/wechat-flow/health */
export const wechatFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "wechat-flow" });
};

export default { wechatInbound, wechatFlowHealth };
