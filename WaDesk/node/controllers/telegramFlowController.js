// controllers/telegramFlowController.js
// =====================================
// Telegram (Bot API) inbound → Node flow engine. Same shape as
// facebookFlowController / tiktokFlowController: Laravel verifies the webhook,
// hands the message here, we decide SYNCHRONOUSLY whether a flow consumes it,
// answer immediately, and run the flow detached (Delay = real await).
//
// PURELY ADDITIVE — imported by nothing else. The recipient key is the Telegram
// chat id.
import { runFlow, resumeFlow, hasSession, pruneSessions } from "../services/telegramFlowService.js";

/**
 * POST /api/telegram-flow/inbound
 *   botId, workspaceId, chatId, text, auth({base, token}), flow?, flowId?, vars?
 * Auth: X-Node-Token. Response: { ok, consumed, mode }
 */
export const telegramInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const botId       = Number(req.body?.botId || 0);
  const workspaceId = Number(req.body?.workspaceId || 0);
  const chatId      = String(req.body?.chatId || "");
  const text        = String(req.body?.text || "");
  const auth        = req.body?.auth || null;
  const flow        = req.body?.flow || null;
  const flowId      = req.body?.flowId ? String(req.body.flowId) : "";
  const vars        = req.body?.vars || {};
  const appDomain   = String(req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");

  if (!botId || !chatId) {
    return res.status(400).send({ ok: false, error: "botId and chatId required" });
  }

  const hasContent = text.trim() !== "";
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));
  const isCommand  = text.trim().startsWith("/"); // Telegram bot command, e.g. /start

  // A matched start-flow RE-FIRES even when a stale session is parked, IF the
  // customer sent a bot COMMAND (/start …) or nothing is parked. This fixes the
  // "get-started bot only responds the first time" bug: after the first run left a
  // node parked, a repeat /start (e.g. tapping Start again after clearing the
  // chat) was being swallowed as a resume-answer to the old node, so the flow
  // never re-triggered. runFlow() clears any old session first, so a restart is
  // clean. A NON-command reply that merely contains a keyword still RESUMES a
  // parked flow, so mid-flow answers and 'any'-triggered flows aren't hijacked.
  const restart  = canStart && (isCommand || !hasSession(botId, chatId));
  const isResume = !restart && hasSession(botId, chatId) && hasContent;

  console.log(`[TG-FLOW-NODE] IN bot=${botId} chat=${chatId} text="${text.slice(0, 50)}" restart=${restart} isResume=${isResume} canStart=${canStart}`);

  if (!restart && !isResume) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  // Answer BEFORE running — a Wait node must never hold the request open.
  res.status(202).send({ ok: true, consumed: true, mode: restart ? "start" : "resume" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (restart) {
        await runFlow({ auth, flow, chatId, text, flowId, botId, workspaceId, appDomain, vars });
        return;
      }
      const done = await resumeFlow({ botId, chatId, text, vars });
      if (!done) console.log(`[TG-FLOW-NODE] resume declined (no matching branch) bot=${botId} chat=${chatId}`);
    } catch (e) {
      console.error(`[TG-FLOW-NODE] handler crashed bot=${botId} chat=${chatId}: ${e?.message}`);
    }
  })();
};

/** GET /api/telegram-flow/health */
export const telegramFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "telegram-flow" });
};

export default { telegramInbound, telegramFlowHealth };
