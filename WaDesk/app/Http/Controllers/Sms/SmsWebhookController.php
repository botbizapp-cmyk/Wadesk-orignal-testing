<?php

namespace App\Http\Controllers\Sms;

use App\Events\Inbox\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\Sms\SmsSender;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Inbound SMS (Twilio / MSG91).
 *
 * A SEPARATE ENDPOINT FROM THE WHATSAPP WEBHOOK, on purpose. Twilio posts the
 * same form shape for both, so a shared endpoint would file an SMS as a
 * WhatsApp thread and the operator's reply would then go out over WhatsApp to
 * someone who only ever texted. A dedicated /api/sms/inbound means the channel
 * is decided by the URL the provider was told to call.
 *
 * Answers TwiML (empty <Response/>) not JSON — Twilio records a non-XML reply as
 * error 12300 and, on repeated failures, disables the webhook. It never 500s
 * for the same reason.
 *
 * The conversation is keyed `sms:<senderConfigId>:<customerDigits>` so a reply
 * leaves from the exact number the customer texted (mirrors Telegram's
 * `tg:<botId>:<chatId>`). Lands in the unified team inbox like any channel.
 */
class SmsWebhookController extends Controller
{
    public function ingest(Request $request): Response
    {
        // Twilio inbound field names.
        $to   = (string) $request->input('To', '');
        $from = (string) $request->input('From', '');
        $body = (string) $request->input('Body', '');
        $sid  = (string) $request->input('MessageSid', $request->input('SmsMessageSid', ''));

        // MSG91 posts a different shape (sender/receiver/content …). Map it when
        // the Twilio fields are absent so both providers thread the same way.
        if ($to === '' && $from === '') {
            [$to, $from, $body, $sid] = $this->msg91Inbound($request);
        }

        if ($to === '' || $from === '') {
            return $this->twiml();
        }

        // WHICH of our numbers was texted decides the workspace + sender.
        $sender = SmsSender::byNumber($to);
        if (! $sender) {
            // Not a number we know — acknowledge (retrying won't make it ours).
            return $this->twiml();
        }

        // Twilio request-signature check — only when the header is present (Msg91
        // and our own smoke tests carry none). A present-but-wrong signature is
        // rejected; an absent one is allowed through (the number match already
        // scoped it to this workspace).
        if ($request->hasHeader('X-Twilio-Signature')
            && ! $this->twilioSignatureValid($request, $sender->auth_token)) {
            Log::warning('[SMS] inbound signature mismatch', ['to' => $to]);

            return response('forbidden', 403);
        }

        // A status callback pasted into the messaging field carries a
        // MessageStatus and no Body — don't file "" as a customer message.
        if (trim($body) === '') {
            return $this->twiml();
        }

        try {
            $this->store($sender, $from, $body, $sid, (string) $request->input('NumSegments', '1'));
        } catch (\Throwable $e) {
            Log::warning('[SMS] inbound store failed: ' . $e->getMessage());
        }

        return $this->twiml();
    }

    /**
     * Map an MSG91 inbound (2-way) webhook to [to, from, body, sid].
     *
     * MSG91's incoming payload varies by account/version, so we probe the
     * documented field-name variants defensively. `receiver`/`number` is OUR
     * number (used to resolve the workspace), `sender`/`mobile` is the customer.
     * The operator points their MSG91 panel's inbound webhook at /api/sms/inbound.
     */
    private function msg91Inbound(Request $request): array
    {
        $all = $request->all();
        // Some MSG91 setups wrap the fields under `data` (or send a `report`-style array).
        if (isset($all['data']) && is_array($all['data'])) {
            $all = array_merge($all, $all['data']);
        }
        $pick = function (array $keys) use ($all, $request) {
            foreach ($keys as $k) {
                $v = $request->input($k, $all[$k] ?? null);
                if (is_string($v) && trim($v) !== '') return trim($v);
            }
            return '';
        };
        $from = $pick(['sender', 'mobile', 'msisdn', 'from', 'senderMobile']);
        $to   = $pick(['receiver', 'number', 'shortcode', 'to', 'receiverNumber']);
        $body = $pick(['content', 'text', 'message', 'body', 'msg']);
        $sid  = $pick(['requestId', 'request_id', 'messageId', 'message_id', 'id']);

        return [$to, $from, $body, $sid];
    }

    private function store(SmsSender $sender, string $from, string $body, string $sid, string $segments): void
    {
        $wsId   = (int) $sender->workspace_id;
        $digits = preg_replace('/\D+/', '', $from);
        // ONE THREAD PER CUSTOMER NUMBER — keyed on the customer's digits only, so
        // the model's contact_digits hook (ConversationResolver::digitsFor) keeps a
        // clean phone identity and the SMS thread links to the same contact as the
        // person's WhatsApp thread. The sender to reply FROM is resolved at send
        // time from the inbound meta (sms.to), not encoded here.
        $key    = 'sms:' . $digits;

        $convo = $this->thread($sender, $wsId, $key, $digits);

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider'        => 'sms',
            'direction'       => 'in',
            'body'            => $body,
            'media_path'      => null,
            'media_type'      => null,
            'from_number'     => '+' . $digits,
            'status'          => 'received',
            'meta'            => ['sms' => array_filter([
                'from'        => $from,
                'to'          => $sender->from_number,
                'message_sid' => $sid,
                'segments'    => (int) $segments,
                'provider'    => $sender->provider,
            ], fn ($v) => $v !== null && $v !== '' && $v !== 0)],
            'sent_at'      => now(),
            'delivered_at' => now(),
        ]);

        $convo->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'provider'        => 'sms',
            'preview'         => Str::limit($body, 120),
            'inbox_status'    => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ])->save();

        if (Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        // Capture the sender as a Contact (dedups by phone) so opt-out flags and
        // campaign targeting have a row to hang off — same as WhatsApp inbound.
        try {
            $contact = \App\Models\Contact::rememberPhone($wsId, null, '+' . $digits, null);
            if ($contact && empty($convo->contact_id) && Schema::hasColumn('conversations', 'contact_id')) {
                $convo->forceFill(['contact_id' => $contact->id])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS] contact capture failed: ' . $e->getMessage());
        }

        try {
            event(new MessageReceived($inbox->id, $convo->id, $wsId, 'in', null));
        } catch (\Throwable $e) {
            Log::warning('[SMS] MessageReceived failed: ' . $e->getMessage());
        }

        // STOP / START — a legal requirement on SMS (TCPA / DLT), not a courtesy.
        // A STOP must not ALSO trip an auto-reply, so bail after handling it.
        try {
            if (class_exists(\App\Services\Inbox\OptOutService::class)
                && app(\App\Services\Inbox\OptOutService::class)->handle($convo->fresh() ?: $convo, $body, '+' . $digits)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS] opt-out check failed: ' . $e->getMessage());
        }

        // Channel-generic automation: routing → AI agent → keyword auto-reply.
        // The reply ships back through InboxDispatcher, which resolves dispatchSms.
        try {
            app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                $convo->fresh() ?: $convo,
                ['message_text' => $body, 'contact_phone' => '+' . $digits],
                isFollowUp: ! $convo->wasRecentlyCreated,
            );
            $convo = $convo->fresh() ?: $convo;

            if ($convo->assignee_agent_id) {
                app(\App\Services\AiAgentService::class)->respondIfAssigned($convo->fresh() ?: $convo);
            } else {
                app(\App\Services\Inbox\KeywordReplyDispatcher::class)->maybeDispatch(
                    $convo->fresh() ?: $convo, $body, '+' . $digits, $sender->from_number, null,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS] automation failed: ' . $e->getMessage());
        }
    }

    /** Find or create the channel='sms' thread keyed by sender + customer. */
    private function thread(SmsSender $sender, int $wsId, string $key, string $digits): Conversation
    {
        return Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'sms', 'raw_jid' => $key],
            [
                'title'           => '+' . $digits,
                'provider'        => 'sms',
                'origin'          => 'sms',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
                'contact_digits'  => $digits,
            ]
        );
    }

    /**
     * Verify Twilio's X-Twilio-Signature: base64(HMAC-SHA1(fullUrl + sorted
     * POST params, authToken)). Returns true when it matches.
     */
    private function twilioSignatureValid(Request $request, string $authToken): bool
    {
        $authToken = trim($authToken);
        if ($authToken === '') {
            return false;
        }
        $sig = (string) $request->header('X-Twilio-Signature', '');
        $url = preg_replace('#^http://#i', 'https://', $request->fullUrl());

        $params = $request->post();
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k . (is_array($v) ? implode('', $v) : (string) $v);
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $sig);
    }

    /** Empty TwiML acknowledgement — "received, send no automatic reply". */
    private function twiml(): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response/>', 200)
            ->header('Content-Type', 'text/xml');
    }
}
