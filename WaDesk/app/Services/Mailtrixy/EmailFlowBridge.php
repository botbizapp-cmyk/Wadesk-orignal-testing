<?php

namespace App\Services\Mailtrixy;

use App\Models\SystemSetting;
use App\Models\WorkspaceEmailAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for email flows. Mirrors WeChatFlowBridge: the Node
 * engine holds NO mail credentials — it delegates every SEND back to PHP
 * (/api/email/flow-send), so the MailTrixy bridge secret stays server-side.
 * Node only orchestrates (walk the graph, real `await` on Delay, park/resume)
 * and asks PHP for smart nodes (ai/webhook via /api/email/flow-node).
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * letting MailtrixyIngestService skip PHP routing/keyword/AI auto-reply so the
 * customer never gets a double reply. The recipient key is the mirror row id +
 * the MailTrixy conversation id (the thread's raw_jid is
 * 'email:<mirrorRowId>:<mtxConversationId>').
 */
class EmailFlowBridge
{
    /**
     * @param  array|null  $flow  flow_data — REQUIRED to START, omitted to RESUME.
     * @return bool  true when a flow consumed the message.
     */
    public static function handoff(
        WorkspaceEmailAccount $acct,
        string $mtxConvId,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $mtxConvId === '') {
            return false;
        }

        $appDomain = rtrim((string) (config('app.url') ?: url('/')), '/');

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/').'/api/email-flow/inbound', array_filter([
                    'accountId'      => $acct->id,
                    'conversationId' => $mtxConvId,
                    'workspaceId'    => $acct->workspace_id,
                    'text'           => $text,
                    'flow'           => $flow,
                    'flowId'         => $flowId,
                    'vars'           => (object) $vars,
                    'appDomain'      => $appDomain,
                    // Node stores this per-session so flow-send/flow-node
                    // callbacks reach the right PHP install with the right token.
                    'auth'           => ['base' => $appDomain, 'token' => node_token()],
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[EMAIL-FLOW-BRIDGE] Node '.$r->status().': '.mb_substr((string) $r->body(), 0, 150));

                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[EMAIL-FLOW-BRIDGE] Node unreachable: '.mb_substr($e->getMessage(), 0, 150));

            return false;
        }
    }
}
