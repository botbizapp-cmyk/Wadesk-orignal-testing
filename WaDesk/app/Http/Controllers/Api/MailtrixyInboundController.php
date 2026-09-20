<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\WorkspaceEmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MailTrixy → WaDesk push.
 *
 * The separate MailTrixy deployment POSTs each new inbound email here so it
 * surfaces in WaDesk's unified team-inbox (the SAME Conversation + InboxMessage
 * tables WhatsApp uses, with provider = 'email' and channel = 'email').
 * Authenticated by the SAME shared secret the admin pasted on the Add-ons
 * "Connect" card — no Laravel session, so this route is outside the web auth
 * group and self-guards.
 *
 * Email threads are keyed by the MailTrixy conversation id, namespaced as
 * raw_jid = "email:<mirrorRowId>:<mtxConversationId>" and channel = 'email'
 * (in Conversation::ENGINE_AGNOSTIC_CHANNELS, so they show regardless of the
 * workspace's connected WhatsApp engine and never collide with a phone thread).
 *
 * Body (MailTrixy sends; only channel='email' conversations, only inbound):
 *   {
 *     "event":            "message",
 *     "mtx_workspace_id": 12,
 *     "account":          { "id":3, "workspace_id":12, "email":"…", "name":"…" },
 *     "conversation":     { "id":55, "subject":"…" },
 *     "message":          { "id":901, "direction":"in", "from_email":"…", "from_name":"…",
 *                           "to_email":"…", "subject":"…", "text":"…", "html":"…", "at":"ISO-8601" }
 *   }
 */
class MailtrixyInboundController extends Controller
{
    public function ingest(Request $request): JsonResponse
    {
        // ── Auth: constant-time compare against the stored shared secret ──────
        $sent   = (string) $request->header('X-Mailtrixy-Secret', '');
        $stored = (string) SystemSetting::get('mailtrixy_secret', '');
        if ($stored === '' || ! hash_equals($stored, $sent)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'event'            => 'required|string|in:message',
            'mtx_workspace_id' => 'nullable|integer',
            'account'          => 'required|array',
            'conversation'     => 'nullable|array',
            'message'          => 'required_if:event,message|array',
        ]);

        // A real push proves the link is live — reflect that on the Add-ons card.
        SystemSetting::set('mailtrixy_connected', '1', 'bool', 'MailTrixy handshake result');
        SystemSetting::set('mailtrixy_last_inbound', now()->toDateTimeString(), 'string', 'MailTrixy last inbound push');

        // The WaDesk workspace comes from the mirror row this MailTrixy account
        // is linked to — an account no workspace linked is ACKNOWLEDGED, never
        // errored, so MailTrixy doesn't retry mail nobody here subscribed to.
        // Should the same account ever be mirrored by more than one workspace,
        // the payload's MailTrixy workspace id picks the matching mirror;
        // first() is only the fallback when no row carries that id (legacy
        // rows can have a null mtx_workspace_id).
        $mtxAccountId = (int) data_get($data, 'account.id', 0);
        $mtxWsId      = (int) (data_get($data, 'account.workspace_id') ?: data_get($data, 'mtx_workspace_id', 0));
        $acct = null;
        if ($mtxAccountId > 0) {
            $mirrors = WorkspaceEmailAccount::query()->where('mailtrixy_account_id', $mtxAccountId)->connected();
            if ($mtxWsId > 0) {
                $acct = (clone $mirrors)->where('mtx_workspace_id', $mtxWsId)->first();
            }
            $acct = $acct ?? $mirrors->first();
        }
        if (! $acct) {
            return response()->json(['ok' => true, 'ignored' => 'unlinked_account']);
        }

        try {
            $msg = \App\Services\Mailtrixy\MailtrixyIngestService::ingest($acct, $data);
        } catch (\Throwable $e) {
            Log::error('[MAILTRIXY] inbound store failed', ['ws' => $acct->workspace_id, 'err' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'store_failed'], 500);
        }
        if (! $msg) {
            return response()->json(['ok' => true, 'received' => $data['event'] ?? 'message']);
        }

        return response()->json(['ok' => true, 'message_id' => $msg->id, 'conversation_id' => $msg->conversation_id]);
    }
}
