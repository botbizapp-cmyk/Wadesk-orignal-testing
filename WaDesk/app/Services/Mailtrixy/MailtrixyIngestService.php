<?php

namespace App\Services\Mailtrixy;

use App\Events\Inbox\MessageReceived;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WorkspaceEmailAccount;
use App\Services\AiAgentService;
use App\Services\Inbox\KeywordReplyDispatcher;
use App\Services\Inbox\RoutingEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns one MailTrixy inbound-email payload into a WaDesk unified-inbox row.
 *
 * Email threads are keyed by the namespaced MailTrixy conversation id
 * (raw_jid = "email:<mirrorRowId>:<mtxConversationId>", channel = 'email'),
 * deduped on the MailTrixy message id so a redelivered push never
 * double-renders. The reply path is InboxDispatcher::dispatchMailtrixy →
 * MailtrixyClient::reply, which sends on the same MailTrixy conversation so
 * threading holds in both inboxes.
 */
class MailtrixyIngestService
{
    /** Byte cap for the stored HTML copy of a message (meta.email.html). */
    private const HTML_MAX_BYTES = 65535;

    /**
     * @param  array  $data  {event, mtx_workspace_id, account{id,workspace_id,email,name},
     *                       conversation{id,subject},
     *                       message{id,direction,from_email,from_name,to_email,subject,text,html,at}}
     * @return InboxMessage|null the stored (or existing, on dedup) row; null if not a message
     */
    public static function ingest(WorkspaceEmailAccount $acct, array $data): ?InboxMessage
    {
        if (($data['event'] ?? 'message') !== 'message') {
            return null;
        }

        $wsId      = (int) $acct->workspace_id;
        $mtxConvId = (string) data_get($data, 'conversation.id', '');
        $m         = (array) data_get($data, 'message', []);

        $subject   = trim((string) (data_get($m, 'subject') ?? data_get($data, 'conversation.subject') ?? ''));
        $fromEmail = trim((string) data_get($m, 'from_email', ''));
        $fromName  = trim((string) data_get($m, 'from_name', ''));
        $toEmail   = trim((string) data_get($m, 'to_email', ''));
        $title     = $subject !== '' ? $subject : ($fromName !== '' ? $fromName : ($fromEmail !== '' ? $fromEmail : 'Email'));
        $key       = 'email:'.$acct->id.':'.($mtxConvId !== '' ? $mtxConvId : 'unknown-'.md5(json_encode($data)));

        // provider MUST be in the create attributes: InboxMessage auto-stamps
        // its provider from the parent conversation on create, so a thread
        // created without one would stamp the workspace's WhatsApp engine.
        $convo = Conversation::firstOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'email', 'raw_jid' => $key],
            [
                'title' => $title,
                'provider' => 'email',
                'origin' => 'email',
                'status' => 'pending',
                'inbox_status' => 'open',
                'last_message_at' => now(),
                'contact_digits' => null,
            ]
        );

        $dir          = data_get($m, 'direction') === 'out' ? 'out' : 'in';
        $providerMid  = data_get($m, 'id');
        $html         = (string) data_get($m, 'html', '');
        $body         = trim((string) data_get($m, 'text', ''));
        if ($body === '' && $html !== '') {
            $body = trim(strip_tags($html));
        }

        // Idempotency: dedup on the MailTrixy message id within the thread so a
        // redelivered push is stored only once.
        if ($providerMid !== null && $providerMid !== '') {
            $existing = InboxMessage::where('conversation_id', $convo->id)
                ->where('meta->email->mtx_message_id', $providerMid)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        // Preserve the original send time when MailTrixy provides it (ISO-8601);
        // fall back to now() so ordering never breaks.
        $sentAt = now();
        $rawAt = (string) data_get($m, 'at', '');
        if ($rawAt !== '') {
            try {
                $sentAt = Carbon::parse($rawAt);
            } catch (\Throwable $e) {
            }
        }

        $inbox = InboxMessage::create([
            'conversation_id' => $convo->id,
            'provider' => 'email',
            'direction' => $dir,
            'body' => $body,
            'status' => $dir === 'in' ? 'received' : 'sent',
            'meta' => [
                'email' => array_filter([
                    'subject' => $subject ?: null,
                    'from_email' => $fromEmail ?: null,
                    'from_name' => $fromName ?: null,
                    'to_email' => $toEmail ?: null,
                    'mtx_conversation_id' => $mtxConvId !== '' ? $mtxConvId : null,
                    'mtx_message_id' => $providerMid,
                    'mtx_account_id' => (int) $acct->mailtrixy_account_id,
                    // mb_strcut caps at the byte limit WITHOUT splitting a
                    // UTF-8 character (a raw substr could make meta unencodable).
                    'html' => $html !== '' ? mb_strcut($html, 0, self::HTML_MAX_BYTES) : null,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
            'sent_at' => $sentAt,
            'delivered_at' => $sentAt,
        ]);

        // Lead capture — keyed by the sender address so a repeat email re-uses
        // the same contact. Non-fatal by design (forSocialSender never throws).
        if ($dir === 'in' && $fromEmail !== '') {
            Contact::forSocialSender($wsId, 'email', $fromEmail, $fromName !== '' ? $fromName : null, null, 'Source: Email');
        }

        // Last-message preview + thread bump — same block the other bridge
        // channels use, so email rows sort/badge exactly like WhatsApp rows.
        $previewText = $body !== '' ? $body : $subject;
        $convoUpdate = [
            'last_message_at' => $sentAt,
            'title' => $title,
            'provider' => 'email',
            'preview' => mb_substr($previewText, 0, 140),
            'inbox_status' => $convo->inbox_status === 'resolved' ? 'open' : $convo->inbox_status,
        ];
        if ($dir === 'in' && Schema::hasColumn('conversations', 'last_inbound_at')) {
            $convoUpdate['last_inbound_at'] = $sentAt;
        } elseif ($dir === 'out' && Schema::hasColumn('conversations', 'last_outbound_at')) {
            $convoUpdate['last_outbound_at'] = $sentAt;
        }
        $convo->forceFill($convoUpdate)->save();
        if ($dir === 'in' && Schema::hasColumn('conversations', 'unread_count')) {
            $convo->increment('unread_count');
        }

        event(new MessageReceived($inbox->id, $convo->id, $wsId, $dir, null));

        Log::info('[MAILTRIXY] inbox message stored', [
            'workspace' => $wsId,
            'conversation_id' => $convo->id,
            'inbox_message_id' => $inbox->id,
            'provider_message_id' => $providerMid,
            'direction' => $dir,
        ]);

        // Automation for genuine inbound — FLOW FIRST (on the Node runtime),
        // then routing → AI agent → keyword reply. Mirrors the Telegram/WeChat
        // ingest so a customer never gets a double reply: a consumed handoff
        // (started OR resumed a flow) skips the whole PHP chain. Flow sends
        // leave via PHP flow-send (the MailTrixy bridge secret stays
        // server-side). Failures never block the ingest.
        if ($dir === 'in') {
            try {
                $startFlow = self::resolveEmailKeywordFlow($acct, $body);
                $consumedByFlow = EmailFlowBridge::handoff(
                    $acct, $mtxConvId, $body,
                    $startFlow ? $startFlow->decoded_flow_data : null,
                    $startFlow?->id,
                );

                if (! $consumedByFlow) {
                    app(RoutingEngine::class)->applyToInbound(
                        $convo->fresh() ?: $convo,
                        ['message_text' => $body, 'contact_phone' => $fromEmail],
                        isFollowUp: ! $convo->wasRecentlyCreated,
                    );
                    $convo = $convo->fresh() ?: $convo;

                    if ($convo->assignee_agent_id) {
                        app(AiAgentService::class)->respondIfAssigned($convo->fresh() ?: $convo);
                    } else {
                        app(KeywordReplyDispatcher::class)->maybeDispatch(
                            $convo->fresh() ?: $convo, $body, $fromEmail, null, null,
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[MAILTRIXY] automation failed: '.$e->getMessage(), ['convo' => $convo->id]);
            }
        }

        return $inbox;
    }

    /**
     * A PUBLISHED, active email flow bound to this mailbox whose keyword
     * trigger matches — the START path (mirrors resolveTelegramKeywordFlow).
     * The flow's trigger_device_id carries the WorkspaceEmailAccount mirror
     * row id. Returns the flow to START, or null to RESUME / fall through to
     * keyword/AI.
     */
    private static function resolveEmailKeywordFlow(WorkspaceEmailAccount $acct, string $body): ?\App\Models\Flow
    {
        $text = mb_strtolower(trim($body));
        if ($text === '' || ! class_exists(\App\Models\Flow::class)) {
            return null;
        }
        $flows = \App\Models\Flow::query()
            ->where('workspace_id', $acct->workspace_id)
            ->where('flow_type', 'email')
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('trigger_device_id', $acct->id)
            ->orderByDesc('updated_at')
            ->get();
        foreach ($flows as $flow) {
            $raw = trim((string) $flow->trigger_keywords);
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', mb_strtolower($raw)) as $kw) {
                $kw = trim($kw);
                if ($kw === '') {
                    continue;
                }
                if (in_array($kw, ['any', '*', '.*', '.+'], true) || str_contains($text, $kw)) {
                    return $flow;
                }
            }
        }

        return null;
    }
}
