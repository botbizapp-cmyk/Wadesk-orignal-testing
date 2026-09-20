<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\WaProviderConfig;
use App\Enums\WaProvider;

/**
 * Twilio MessageStatus webhook receiver.
 *
 * Twilio POSTs here every time an outbound message changes state:
 * queued → sent → delivered → read (when read-receipts enabled) → undelivered/failed.
 * Without this endpoint WaDesk's Twilio broadcasts/sends stayed frozen at
 * `sent` forever because Twilio only fires delivery events when the
 * caller registers a StatusCallback URL on the send. We append that URL
 * via WhatsAppDispatcher::dispatchTwilio + InboxDispatcher::dispatchTwilio
 * + node/utils/helpers.js::sendMessageViaTwilioApi.
 *
 * Auth: Twilio signs the request with HMAC-SHA1 over (URL + sorted form
 * fields) using the workspace's AuthToken. We validate
 * `X-Twilio-Signature` so a casual probe can't fake delivery events.
 *
 * Body fields we read (form-encoded):
 *   MessageSid       — `SM…` / `MM…` outbound message id
 *   MessageStatus    — queued | sent | delivered | read | undelivered | failed | received
 *   AccountSid       — used to find the matching workspace
 *   ErrorCode        — present on failed/undelivered
 *   ErrorMessage     — human-readable
 *   From / To        — `whatsapp:+E164`
 */
class TwilioStatusController extends Controller
{
    public function handle(Request $request): Response
    {
        $params = $request->all();
        $sig    = (string) $request->header('X-Twilio-Signature', '');
        $accountSid = (string) ($params['AccountSid'] ?? '');
        $messageSid = (string) ($params['MessageSid'] ?? '');
        $status     = strtolower((string) ($params['MessageStatus'] ?? ''));

        if ($messageSid === '' || $status === '') {
            return response('missing fields', 400);
        }

        // Resolve workspace by AccountSid so each tenant validates against
        // its own AuthToken. A platform-shared installation may have many
        // Twilio configs; match the one whose creds.account_sid matches.
        $workspaceId = null;
        $authToken   = '';
        if ($accountSid !== '') {
            $cfgs = WaProviderConfig::query()
                ->where('provider', WaProvider::Twilio->value)
                ->where('status', WaProviderConfig::STATUS_CONNECTED)
                ->get();
            foreach ($cfgs as $cfg) {
                $creds = $cfg->creds();
                if (($creds['account_sid'] ?? '') === $accountSid) {
                    $workspaceId = (int) $cfg->workspace_id;
                    $authToken   = (string) ($creds['auth_token'] ?? '');
                    break;
                }
            }
        }
        // Fallback to admin-default creds for legacy single-tenant installs.
        if ($authToken === '') {
            $authToken = (string) \App\Models\SystemSetting::get('twilio_auth_token', env('TWILIO_AUTH_TOKEN', ''));
        }

        // Validate Twilio signature. Twilio's algorithm: HMAC-SHA1 over
        // the full URL + alphabetically-sorted form fields concatenated
        // as key+value, base64-encoded. We rebuild the same string and
        // compare against the supplied X-Twilio-Signature.
        // FAIL CLOSED, both ways — identical to WaWebhookController::receiveTwilio.
        // An unresolvable auth token refuses the webhook instead of skipping
        // verification: an AccountSid matching no connected config (or omitted
        // entirely) left $authToken empty and ran all five table updates on
        // unauthenticated, attacker-supplied input. A missing or wrong
        // X-Twilio-Signature is likewise rejected.
        if ($authToken === '') {
            Log::error('[TWILIO-STATUS] auth token unresolvable — refusing webhook', [
                'account_sid' => $accountSid,
                'message_sid' => $messageSid,
            ]);
            return response('server misconfigured', 500);
        }
        if ($sig === '') {
            Log::warning('[TWILIO-STATUS] missing X-Twilio-Signature (auth token resolved)', [
                'message_sid' => $messageSid,
                'workspace_id' => $workspaceId,
            ]);
            return response('missing signature', 403);
        }
        $url = $request->fullUrl();
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k . (is_array($v) ? json_encode($v) : (string) $v);
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        if (!hash_equals($expected, $sig)) {
            Log::warning('[TWILIO-STATUS] signature mismatch', [
                'message_sid' => $messageSid,
                'workspace_id' => $workspaceId,
            ]);
            return response('invalid signature', 403);
        }

        // Map Twilio status → WaDesk's canonical status enum.
        $mapped = match ($status) {
            'queued', 'accepted', 'sending', 'sent'  => 'sent',
            'delivered'                              => 'delivered',
            'read'                                   => 'read',
            'failed', 'undelivered'                  => 'failed',
            default                                  => $status,
        };
        $errorMsg = ($status === 'failed' || $status === 'undelivered')
            ? trim(($params['ErrorCode'] ?? '') . ' ' . ($params['ErrorMessage'] ?? ''))
            : null;

        // Central billing — charge on delivery, refund on failure (Twilio).
        // MessageSid is globally unique, so this bills each message exactly once
        // regardless of send type. Twilio carries no WABA category → flat rate.
        if ($workspaceId) {
            try {
                $billing = app(\App\Services\MessageBillingService::class);
                if ($mapped === 'delivered' || $mapped === 'read') {
                    $billing->settleDelivered((int) $workspaceId, $messageSid, (string) ($params['To'] ?? ''), null, 'twilio');
                } elseif ($mapped === 'failed') {
                    $billing->settleFailed((int) $workspaceId, $messageSid);
                }
            } catch (\Throwable $e) {
                Log::warning('[BILLING] twilio settle failed', ['sid' => $messageSid, 'err' => $e->getMessage()]);
            }
        }

        // No shared blanket patch: every branch below advances its own row only
        // when the status state machine allows it, and writes the columns that
        // table actually has.
        $now     = Carbon::now();
        $touched = 0;

        // 1. broadcast_contacts — broadcast recipients.
        // Per-row monotonic guard: a replayed or out-of-order Twilio callback
        // (queued/accepted/sending/sent ALL map to `sent`, so an ordinary
        // duplicate is enough) must never demote read -> delivered -> sent or
        // resurrect a failed row. Eloquent, not DB::table, so error_message goes
        // through the SafeEncrypted cast instead of landing as plaintext.
        try {
            \App\Models\BroadcastContact::query()
                ->where('whatsapp_message_id', $messageSid)
                ->get()
                ->each(function ($row) use (&$touched, $mapped, $now, $errorMsg) {
                    if (!$this->shouldAdvance($row->status, $mapped)) return;
                    $row->status = $mapped;
                    if ($mapped === 'delivered' && !$row->delivered_at) $row->delivered_at = $now;
                    if ($mapped === 'read'      && !$row->read_at)      $row->read_at      = $now;
                    // Read implies delivered — Twilio can skip the `delivered`
                    // callback, and the double tick needs delivered_at stamped.
                    if ($mapped === 'read'      && !$row->delivered_at) $row->delivered_at = $now;
                    if ($errorMsg !== null && $errorMsg !== '') {
                        $row->error_message = mb_substr($errorMsg, 0, 255);
                    }
                    $row->save();
                    $touched++;
                });
        } catch (\Throwable $e) {
            Log::warning('[TWILIO-STATUS] broadcast_contacts update failed', [
                'sid' => $messageSid, 'error' => $e->getMessage(),
            ]);
        }

        // 2. messages — chat composer + flow sends.
        // Matched on meta JSON, the same way the WABA status path matches
        // (WaWebhookController::applyStatus) and the same key every send site
        // stamps the provider id into. The two legs this replaces could never
        // match: Message casts from_number to 'encrypted' (non-deterministic
        // ciphertext vs a plaintext SID), and `platform_message_id` is not a
        // column on this schema at all. Do not re-add either.
        // Eloquent, not DB::table, so failure_reason goes through the encrypted
        // cast instead of landing as plaintext in an encrypted column.
        try {
            \App\Models\Message::query()
                ->whereJsonContains('meta->wa_message_id', $messageSid)
                ->get()
                ->each(function ($m) use (&$touched, $mapped, $now, $errorMsg) {
                    if (!$this->shouldAdvance($m->status, $mapped)) return;
                    $m->status = $mapped;
                    if ($mapped === 'delivered' && !$m->delivered_at) $m->delivered_at = $now;
                    if ($mapped === 'read'      && !$m->read_at)      $m->read_at      = $now;
                    // Read implies delivered — Twilio can skip the `delivered`
                    // callback, and the double tick needs delivered_at stamped.
                    if ($mapped === 'read'      && !$m->delivered_at) $m->delivered_at = $now;
                    if ($errorMsg !== null && $errorMsg !== '') {
                        $m->failure_reason = mb_substr($errorMsg, 0, 255);
                    }
                    $m->save();
                    $touched++;
                });
        } catch (\Throwable $e) {
            Log::warning('[TWILIO-STATUS] messages update failed', [
                'sid' => $messageSid, 'error' => $e->getMessage(),
            ]);
        }

        // 3. inbox_messages — team-inbox replies.
        // There is NO wa_message_id column on inbox_messages (the SID lives in
        // meta JSON, stamped at send time by TeamInboxController), so the old
        // `where('wa_message_id', ...)` raised SQLSTATE 42S22 on every receipt
        // and the bare catch swallowed it — Twilio ticks never moved. Same match
        // the WABA path uses. `error_message` is not a column here either; the
        // inbox stores the reason as failure_reason.
        // Eloquent for the same reason branch 2 is: inbox_messages casts
        // failure_reason with SafeEncrypted, and DB::table bypasses the cast.
        try {
            \App\Models\InboxMessage::query()
                ->whereJsonContains('meta->wa_message_id', $messageSid)
                ->get()
                ->each(function ($row) use (&$touched, $mapped, $now, $errorMsg) {
                    // Monotonic guard: a replayed / out-of-order Twilio callback
                    // must never demote read -> delivered -> sent or resurrect a
                    // failed row.
                    if (!$this->shouldAdvance($row->status, $mapped)) return;
                    $row->status = $mapped;
                    if ($mapped === 'delivered' && !$row->delivered_at) $row->delivered_at = $now;
                    if ($mapped === 'read'      && !$row->read_at)      $row->read_at      = $now;
                    // Read implies delivered — needed for the double tick.
                    if ($mapped === 'read'      && !$row->delivered_at) $row->delivered_at = $now;
                    if ($errorMsg !== null && $errorMsg !== '') {
                        $row->failure_reason = mb_substr($errorMsg, 0, 255);
                    }
                    $row->save();
                    $touched++;
                });
        } catch (\Throwable $e) {
            Log::warning('[TWILIO-STATUS] inbox_messages update failed', [
                'sid' => $messageSid, 'error' => $e->getMessage(),
            ]);
        }

        // 4. wa_orders — order confirmations sent via Twilio template.
        // The SID column here is `wa_message_id` (there is no
        // whatsapp_message_id / delivered_at / read_at / error_message on this
        // table, so the old statement raised SQLSTATE 42S22 on every receipt),
        // and wa_orders.status is the ORDER lifecycle status — stamping a
        // delivery status over it would corrupt the order. Touch updated_at only,
        // exactly like the WABA twin (WaWebhookController::applyStatus).
        try {
            $touched += DB::table('wa_orders')
                ->where('wa_message_id', $messageSid)
                ->update(['updated_at' => $now]);
        } catch (\Throwable $e) {
            Log::warning('[TWILIO-STATUS] wa_orders update failed', [
                'sid' => $messageSid, 'error' => $e->getMessage(),
            ]);
        }

        // 5. wp_campaign_contacts — CAMPAIGN recipients.
        //
        // This table was missing from the list, so Twilio delivery/read
        // receipts never reached a campaign. The MessageSid IS stored here at
        // send time (WaCampaignsController: 'whatsapp_message_id' =>
        // $result['provider_id']) — nothing ever queried it with that SID, so
        // a Twilio campaign's Delivered/Read counters were permanently 0 while
        // the same receipts updated broadcasts fine.
        // Same per-row monotonic guard as branch 1 — without it a replayed
        // callback demoted the recipient rows AND then recomputed the campaign's
        // Delivered/Read counters from the corrupted rows, moving them backwards.
        try {
            $changedCampaignIds = [];
            \App\Models\WpCampaignContact::query()
                ->where('whatsapp_message_id', $messageSid)
                ->get()
                ->each(function ($row) use (&$touched, &$changedCampaignIds, $mapped, $now, $errorMsg) {
                    if (!$this->shouldAdvance($row->status, $mapped)) return;
                    $row->status = $mapped;
                    if ($mapped === 'delivered' && !$row->delivered_at) $row->delivered_at = $now;
                    if ($mapped === 'read'      && !$row->read_at)      $row->read_at      = $now;
                    // Read implies delivered — needed for the double tick.
                    if ($mapped === 'read'      && !$row->delivered_at) $row->delivered_at = $now;
                    if ($errorMsg !== null && $errorMsg !== '') {
                        $row->error_message = mb_substr($errorMsg, 0, 255);
                    }
                    $row->save();
                    $touched++;
                    $changedCampaignIds[(int) $row->campaign_id] = true;
                });

            // Roll the per-recipient rows up into the campaign counters — the
            // same recompute the WABA webhook calls, so both engines land on one
            // implementation instead of two. Only campaigns whose rows actually
            // advanced are recomputed, so a replayed callback can't move the
            // aggregates at all.
            foreach (array_keys($changedCampaignIds) as $cid) {
                try {
                    \App\Models\WpCampaign::find($cid)?->recomputeAggregates();
                } catch (\Throwable $e) {
                    Log::warning('[TWILIO-STATUS] campaign recompute failed', [
                        'campaign_id' => $cid, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[TWILIO-STATUS] wp_campaign_contacts update failed', [
                'sid' => $messageSid, 'error' => $e->getMessage(),
            ]);
        }

        Log::info('[TWILIO-STATUS] applied', [
            'message_sid'  => $messageSid,
            'status'       => $mapped,
            'workspace_id' => $workspaceId,
            'rows_touched' => $touched,
        ]);

        // Twilio expects 200/204 within 15s; anything else triggers retries.
        return response('', 204);
    }

    /**
     * Status state machine — identical ranking to
     * WaWebhookController::shouldAdvance, duplicated here because that one is
     * private and widening its visibility would expose a webhook internal.
     * Once a row is `read` it is never demoted back to delivered/sent by a
     * replayed callback — nor overwritten by a LATE `failed`, since the provider
     * already confirmed the customer read it. `failed` is otherwise terminal
     * except for another `failed` (Twilio can refine the error code). Both
     * copies of this state machine must stay identical, so /chat (messages) and
     * the team inbox (inbox_messages) never show contradictory terminal states.
     */
    private function shouldAdvance(?string $current, string $incoming): bool
    {
        $rank = ['' => 0, 'pending' => 1, 'queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];
        $curRank = $rank[$current ?? ''] ?? 0;
        $incRank = $rank[$incoming]      ?? 0;

        if ($current === 'failed' && $incoming !== 'failed') return false;
        if ($current === 'read' && in_array($incoming, ['delivered', 'sent', 'failed'], true)) return false;
        return $incRank >= $curRank;
    }
}
