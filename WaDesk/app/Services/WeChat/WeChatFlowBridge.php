<?php

namespace App\Services\WeChat;

use App\Models\SystemSetting;
use App\Models\WeChatChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for WeChat flows. Mirrors LineFlowBridge, with one
 * difference driven by WeChat's SHARED access_token: the Node engine does NOT
 * hold a WeChat token — it delegates every SEND back to PHP
 * (/api/wechat/flow-send), so token management stays in one place (the cached +
 * locked manager on the channel row). Node only orchestrates (walk the graph,
 * real `await` on Delay, park/resume) and asks PHP for smart nodes (ai/webhook).
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * letting the webhook skip PHP keyword/AI auto-reply so the user never gets a
 * double reply. The recipient key is the WeChat OpenID.
 */
class WeChatFlowBridge
{
    /**
     * @param  array|null  $flow  flow_data — REQUIRED to START, omitted to RESUME.
     * @return bool  true when a flow consumed the message.
     */
    public static function handoff(
        WeChatChannel $channel,
        string $openid,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $openid === '') {
            return false;
        }

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/').'/api/wechat-flow/inbound', array_filter([
                    'channelId'   => $channel->id,
                    'workspaceId' => $channel->workspace_id,
                    'openid'      => $openid,
                    'text'        => $text,
                    'flow'        => $flow,
                    'flowId'      => $flowId,
                    'vars'        => (object) $vars,
                    'appDomain'   => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[WECHAT-FLOW-BRIDGE] Node '.$r->status().': '.mb_substr((string) $r->body(), 0, 150));

                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[WECHAT-FLOW-BRIDGE] Node unreachable: '.mb_substr($e->getMessage(), 0, 150));

            return false;
        }
    }
}
