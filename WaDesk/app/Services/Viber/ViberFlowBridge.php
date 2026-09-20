<?php

namespace App\Services\Viber;

use App\Models\SystemSetting;
use App\Models\ViberChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for Viber flows. Mirrors LineFlowBridge: the token is
 * STATIC, so the Node engine sends DIRECTLY to Viber (unlike WeChat which delegates
 * sends back to PHP). All channel flows run on the long-lived Node runtime
 * (node/services/viberFlowService.js at /api/viber-flow/inbound) so a Delay/Wait
 * node is a real `await`.
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * letting the webhook skip PHP keyword/AI auto-reply so the user never gets a
 * double reply. The recipient key is the Viber user id.
 */
class ViberFlowBridge
{
    public static function handoff(
        ViberChannel $channel,
        string $userId,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $userId === '') {
            return false;
        }

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)->acceptJson()
                ->post(rtrim($nodeUrl, '/').'/api/viber-flow/inbound', array_filter([
                    'channelId'   => $channel->id,
                    'workspaceId' => $channel->workspace_id,
                    'userId'      => $userId,
                    'text'        => $text,
                    'auth'        => [
                        'base'   => 'https://chatapi.viber.com/pa',
                        'token'  => $channel->auth_token,
                        'sender' => $channel->senderObject(),
                    ],
                    'flow'      => $flow,
                    'flowId'    => $flowId,
                    'vars'      => (object) $vars,
                    'appDomain' => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[VIBER-FLOW-BRIDGE] Node '.$r->status().': '.mb_substr((string) $r->body(), 0, 150));

                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[VIBER-FLOW-BRIDGE] Node unreachable: '.mb_substr($e->getMessage(), 0, 150));

            return false;
        }
    }
}
