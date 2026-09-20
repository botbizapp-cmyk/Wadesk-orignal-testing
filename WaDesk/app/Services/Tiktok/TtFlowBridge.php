<?php

namespace App\Services\Tiktok;

use App\Models\SystemSetting;
use App\Models\TiktokAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for TikTok Business-Messaging flows.
 *
 * Mirrors FbFlowBridge: WaDesk's WhatsApp flow runtime can't talk to TikTok's DM
 * API, so TikTok flows run on a SEPARATE ported Node runtime
 * (node/services/tiktokFlowService.js, mounted at /api/tiktok-flow/inbound). The
 * ONLY difference from the Facebook engine is the send layer — TikTok delivers
 * into a CONVERSATION (conversation_id) via the Business Messaging API, so the
 * flow's "recipient" key is the conversation id, not a psid.
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * letting the caller skip PHP keyword/AI auto-reply so the customer never gets a
 * double reply. Partner-gated + region-locked (checked before calling).
 */
class TtFlowBridge
{
    /**
     * @param  array|null  $flow  flow_data — REQUIRED to START, omitted to RESUME.
     * @return bool  true when a flow consumed the message.
     */
    public static function handoff(
        TiktokAccount $account,
        string $conversationId,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $conversationId === '') {
            return false;
        }

        // Business Messaging auth — token in the Access-Token header, business_id
        // on every call. Both live on the account's meta_json.business.*.
        $token      = (string) data_get($account->meta_json, 'business.access_token', '');
        $businessId = (string) data_get($account->meta_json, 'business.business_id', '');

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/') . '/api/tiktok-flow/inbound', array_filter([
                    'accountId'   => $account->id,
                    'openId'      => $account->open_id,
                    'workspaceId' => $account->workspace_id,
                    'convId'      => $conversationId,
                    'text'        => $text,
                    'auth'        => [
                        'base'       => 'https://business-api.tiktok.com/open_api/v1.3',
                        'businessId' => $businessId,
                        'token'      => $token,
                    ],
                    'flow'      => $flow,
                    'flowId'    => $flowId,
                    'vars'      => (object) $vars,
                    'appDomain' => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[TT-FLOW-BRIDGE] Node ' . $r->status() . ': ' . mb_substr((string) $r->body(), 0, 150));
                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[TT-FLOW-BRIDGE] Node unreachable: ' . mb_substr($e->getMessage(), 0, 150));
            return false;
        }
    }
}
