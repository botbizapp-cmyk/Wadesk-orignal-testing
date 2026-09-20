<?php

namespace App\Services\Line;

use App\Models\LineChannel;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for LINE flows. Mirrors TgFlowBridge / FbFlowBridge:
 * all channel flows run on the long-lived Node runtime
 * (node/services/lineFlowService.js at /api/line-flow/inbound) so a Delay/Wait
 * node is a real `await` — no PHP job, no queue.
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * letting the webhook skip PHP keyword/AI auto-reply so the customer never gets
 * a double reply. The recipient key is the LINE userId. When a fresh replyToken
 * is available the node uses the FREE reply path; otherwise it pushes.
 */
class LineFlowBridge
{
    /**
     * @param  array|null  $flow  flow_data — REQUIRED to START, omitted to RESUME.
     * @return bool  true when a flow consumed the message.
     */
    public static function handoff(
        LineChannel $channel,
        string $userId,
        string $text,
        ?array $flow = null,
        $flowId = null,
        ?string $replyToken = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $userId === '') {
            return false;
        }

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/').'/api/line-flow/inbound', array_filter([
                    'channelId'   => $channel->id,
                    'workspaceId' => $channel->workspace_id,
                    'userId'      => $userId,
                    'text'        => $text,
                    'replyToken'  => $replyToken,
                    'auth'        => [
                        'base'  => 'https://api.line.me',
                        'token' => $channel->activeAccessToken(),
                    ],
                    'flow'      => $flow,
                    'flowId'    => $flowId,
                    'vars'      => (object) $vars,
                    'appDomain' => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[LINE-FLOW-BRIDGE] Node '.$r->status().': '.mb_substr((string) $r->body(), 0, 150));

                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[LINE-FLOW-BRIDGE] Node unreachable: '.mb_substr($e->getMessage(), 0, 150));

            return false;
        }
    }
}
