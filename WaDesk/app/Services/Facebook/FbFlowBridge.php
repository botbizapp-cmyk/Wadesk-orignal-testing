<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for Facebook Messenger flows.
 *
 * WaDesk's WhatsApp flow runtime (node/services/flowService.js) resolves a phone
 * sender and can only talk to Baileys/WABA/Twilio — it has no Messenger branch.
 * So Facebook flows run on the SEPARATE, ported Node runtime
 * (node/services/facebookFlowService.js, mounted at /api/facebook-flow/inbound).
 *
 * This bridge POSTs an inbound FB event to that engine. Node answers
 * SYNCHRONOUSLY whether a flow consumed the message (`consumed`), which lets the
 * caller skip WaDesk's PHP keyword auto-reply / AI so the customer never gets a
 * double reply — exactly how the WhatsApp Baileys/WABA paths already behave.
 */
class FbFlowBridge
{
    /**
     * Hand an inbound FB message to the Node flow engine.
     *
     * @param  array|null  $flow  flow_data — REQUIRED to START a flow, omitted to RESUME a parked session.
     * @return bool  true when a flow consumed the message (caller must not auto-reply).
     */
    public static function handoff(
        FacebookPage $page,
        string $psid,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = [],
        string $commentId = ''
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $psid === '') {
            return false;
        }

        // auth = {base, pageId, token} — Graph base so Node's graphSend hits the
        // same /{pageId}/messages endpoint. Host is always graph.facebook.com.
        $v = \App\Services\Facebook\FacebookPageClient::version();

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/') . '/api/facebook-flow/inbound', array_filter([
                    'pageId'      => $page->page_id,
                    'workspaceId' => $page->workspace_id,
                    'psid'        => $psid,
                    'text'        => $text,
                    'commentId'   => $commentId,
                    'auth'        => [
                        'base'   => "https://graph.facebook.com/{$v}",
                        'pageId' => $page->page_id,
                        'token'  => $page->access_token,
                    ],
                    'flow'      => $flow,
                    'flowId'    => $flowId,
                    'vars'      => (object) $vars,
                    'appDomain' => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (!$r->successful()) {
                Log::warning('[FB-FLOW-BRIDGE] Node ' . $r->status() . ': ' . mb_substr((string) $r->body(), 0, 150));
                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[FB-FLOW-BRIDGE] Node unreachable: ' . mb_substr($e->getMessage(), 0, 150));
            return false;
        }
    }
}
