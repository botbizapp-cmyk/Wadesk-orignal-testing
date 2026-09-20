// controllers/wabaCallController.js
// ==================================
// WABA Calling — Live AI pickup
//
// Laravel calls /api/waba-call/answer when an incoming WABA call lands
// AND a voice assistant is configured. This module:
//
//   1. Validates the X-Node-Token shared secret.
//   2. Hands off to wabaCallBridge.openSession() which (in Phase 2)
//      will negotiate WebRTC SDP with Meta + run the STT → LLM → TTS
//      audio loop.
//
// Phase-1 (this session): the route + auth + session bookkeeping ship.
// The real-time audio loop is documented in
//   D:\Vault\kapil\WaDesk - WABA Calls AI Pickup.md
// and will need the `wrtc` npm dep + Meta's ICE servers configured.
// ==================================

import { openSession, closeSession, openOutboundSession, linkMetaCall, applyRemoteAnswer } from "../services/wabaCallBridge.js";
import { timingSafeEqualStr } from "../utils/helpers.js";

function authed(req) {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  // Accept the shared secret from the header OR the request body — Laravel
  // sends it both ways (X-Node-Token header + `node_token` in the body), so a
  // proxy that strips custom headers can't lock the bridge out.
  // Constant-time compare (finding #50) — mirrors Laravel's hash_equals().
  const token = req.headers["x-node-token"] || (req.body && req.body.node_token) || "";
  return expected !== "" && timingSafeEqualStr(token, expected);
}

/**
 * POST /api/waba-call/answer
 * Body: {
 *   wa_call_id, meta_call_id, workspace_id, assistant_id,
 *   caller_phone, callee_phone, sdp_offer
 * }
 *
 * Returns 202 immediately (audio negotiation is async). Laravel doesn't
 * block waiting for us — Meta has its own 10s deadline on the connect
 * event which the existing webhook handler already meets.
 */
export const answer = async (req, res, app) => {
  if (!authed(req)) {
    console.warn("[WABA-CALL][trace] /answer UNAUTHORIZED — X-Node-Token/node_token mismatch. Check node_webhook_token matches between Laravel + Node .env.");
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }
  const b = req.body || {};
  console.log("[WABA-CALL][trace] /answer received", {
    wa_call_id: b.wa_call_id, meta_call_id: b.meta_call_id, workspace_id: b.workspace_id,
    assistant_id: b.assistant_id, has_sdp_offer: !!b.sdp_offer,
    has_meta_token: !!b.meta_token, has_phone_id: !!b.phone_number_id,
  });
  const required = ["wa_call_id", "meta_call_id", "workspace_id", "assistant_id"];
  for (const k of required) {
    if (!b[k]) {
      console.warn(`[WABA-CALL][trace] /answer rejected — missing ${k}`);
      return res.status(400).send({ ok: false, error: "missing_" + k });
    }
  }

  // Kick off the bridge in the background — every WABA call gets its
  // own session keyed by meta_call_id. Idempotent: if a session is
  // already open for this id, openSession() returns the existing one.
  try {
    openSession(app, {
      waCallId:      b.wa_call_id,
      metaCallId:    b.meta_call_id,
      workspaceId:   b.workspace_id,
      assistantId:   b.assistant_id,
      callerPhone:   b.caller_phone || "",
      calleePhone:   b.callee_phone || "",
      sdpOffer:      b.sdp_offer || null,
      // Every value below comes from Laravel's DB (system_settings +
      // wa_provider_configs.credentials_json). No env fallback — the
      // admin manages everything at /admin/settings.
      metaToken:     b.meta_token || "",
      phoneNumberId: b.phone_number_id || "",
      graphVersion:  b.graph_version || "v23.0",
      nodeToken:     b.node_token || "",
    });
    return res.status(202).send({ ok: true, status: "bridging" });
  } catch (e) {
    console.error("[WABA-CALL] answer failed:", e?.message);
    return res.status(500).send({ ok: false, error: e?.message || "bridge_failed" });
  }
};

/**
 * POST /api/waba-call/place-outbound
 * Body: { wa_call_id, workspace_id, assistant_id, to, from,
 *         meta_token, phone_number_id, graph_version, node_token }
 *
 * BUSINESS-INITIATED (outbound) AI call. Laravel has already created the
 * wa_calls row; here we spin up the bridge, which mints the SDP OFFER and
 * posts it back to Laravel's /api/waba-call/bridge-offer to actually dial
 * the customer via Meta's calls API. Returns 202 — negotiation is async.
 */
export const placeOutbound = async (req, res, app) => {
  if (!authed(req)) {
    console.warn("[WABA-CALL][trace] /place-outbound UNAUTHORIZED — token mismatch.");
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }
  const b = req.body || {};
  console.log("[WABA-CALL][trace] /place-outbound received", {
    wa_call_id: b.wa_call_id, workspace_id: b.workspace_id, assistant_id: b.assistant_id,
    to: b.to, has_meta_token: !!b.meta_token, has_phone_id: !!b.phone_number_id,
  });
  const required = ["wa_call_id", "workspace_id", "assistant_id", "to"];
  for (const k of required) {
    if (!b[k]) {
      console.warn(`[WABA-CALL][trace] /place-outbound rejected — missing ${k}`);
      return res.status(400).send({ ok: false, error: "missing_" + k });
    }
  }
  try {
    const s = openOutboundSession(app, {
      waCallId:      b.wa_call_id,
      workspaceId:   b.workspace_id,
      assistantId:   b.assistant_id,
      to:            b.to,
      from:          b.from || "",
      metaToken:     b.meta_token || "",
      phoneNumberId: b.phone_number_id || "",
      graphVersion:  b.graph_version || "v23.0",
      nodeToken:     b.node_token || "",
    });
    if (!s) return res.status(500).send({ ok: false, error: "bridge_unavailable" });
    return res.status(202).send({ ok: true, status: "dialing" });
  } catch (e) {
    console.error("[WABA-CALL] place-outbound failed:", e?.message);
    return res.status(500).send({ ok: false, error: e?.message || "bridge_failed" });
  }
};

/**
 * POST /api/waba-call/link-meta
 * Body: { wa_call_id, meta_call_id }
 * Laravel dialed Meta with our offer and got back a call id — bind it to the
 * pending outbound session so the customer's answer can be routed to it.
 */
export const linkMeta = async (req, res, app) => {
  if (!authed(req)) return res.status(401).send({ ok: false, error: "unauthorized" });
  const b = req.body || {};
  if (!b.wa_call_id || !b.meta_call_id) {
    return res.status(400).send({ ok: false, error: "missing_ids" });
  }
  const ok = linkMetaCall(app, b.wa_call_id, b.meta_call_id);
  return res.status(ok ? 200 : 404).send({ ok });
};

/**
 * POST /api/waba-call/connect
 * Body: { meta_call_id, sdp_answer }
 * The customer accepted the outbound call — Meta's connect webhook carried the
 * answer SDP, which Laravel forwards here to complete the WebRTC handshake.
 */
export const connect = async (req, res, app) => {
  if (!authed(req)) return res.status(401).send({ ok: false, error: "unauthorized" });
  const b = req.body || {};
  if (!b.meta_call_id || !b.sdp_answer) {
    return res.status(400).send({ ok: false, error: "missing_meta_call_id_or_sdp" });
  }
  const ok = await applyRemoteAnswer(app, b.meta_call_id, b.sdp_answer);
  return res.status(ok ? 200 : 404).send({ ok });
};

/**
 * POST /api/waba-call/terminate
 * Hard-close a session — used when Laravel's terminate webhook lands
 * before the bridge has noticed Meta hung up. Idempotent.
 */
export const terminate = async (req, res, app) => {
  if (!authed(req)) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }
  const metaCallId = req.body?.meta_call_id;
  if (!metaCallId) return res.status(400).send({ ok: false, error: "missing_meta_call_id" });
  try {
    closeSession(app, metaCallId);
    return res.status(200).send({ ok: true });
  } catch (e) {
    console.error("[WABA-CALL] terminate failed:", e?.message);
    return res.status(500).send({ ok: false, error: e?.message || "close_failed" });
  }
};
