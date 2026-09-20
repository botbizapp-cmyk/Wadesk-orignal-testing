<?php

namespace App\Http\Controllers\Viber;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\ViberChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Laravel callbacks for the Node Viber flow engine (node/services/viberFlowService.js).
 * Node sends every message itself via the Viber API (static token) and calls back
 * here only to mirror a flow message into the inbox (flow-log) and resolve smart
 * AI / webhook nodes (flow-node). Both X-Node-Token guarded. Mirrors
 * LineFlowNodeController.
 */
class ViberFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();

        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/viber/flow-log — mirror an outbound flow message into the inbox. */
    public function log(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $channelId = (int) $request->input('channelId', 0);
        $userId    = (string) $request->input('userId', '');
        $body      = (string) $request->input('body', '');
        $direction = $request->input('direction') === 'in' ? 'in' : 'out';
        $channel   = $channelId > 0 ? ViberChannel::find($channelId) : null;

        if ($channel && $userId !== '' && $body !== '') {
            $wsId = (int) $channel->workspace_id;
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'viber', 'raw_jid' => 'viber:'.$channel->id.':'.$userId],
                ['title' => 'Viber', 'provider' => 'viber', 'origin' => 'viber', 'status' => 'pending', 'inbox_status' => 'open', 'last_message_at' => now(), 'contact_digits' => null]
            );
            try {
                InboxMessage::create(array_filter([
                    'conversation_id' => $conv->id,
                    'provider'        => 'viber',
                    'direction'       => $direction,
                    'body'            => $body,
                    'status'          => $direction === 'in' ? 'received' : 'sent',
                    'meta'            => ['viber' => array_filter([
                        'user_id' => $userId,
                        'source'  => (string) $request->input('source', 'flow'),
                    ], fn ($v) => $v !== null && $v !== '')],
                    'sent_at'         => now(),
                ], fn ($v) => $v !== null));
                $conv->forceFill(['preview' => Str::limit($body, 120), 'last_message_at' => now()])->save();
            } catch (\Throwable $e) {
                Log::warning('[VIBER-FLOW-NODE] flow-log write failed: '.$e->getMessage());
            }
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/viber/flow-node — resolve a smart node (ai / webhook). */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $type = (string) ($request->input('action') ?: $request->input('type', ''));
        if ($type === 'ai' || $type === 'viber_ai') {
            return $this->handleAi($request);
        }
        if ($type === 'webhook') {
            return $this->handleWebhook($request);
        }

        return response()->json(['ok' => true, 'type' => $type, 'text' => '']);
    }

    private function subst(string $s, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';

            return is_scalar($v) ? (string) $v : '';
        }, $s);
    }

    private function handleAi(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $wsId = (int) $request->input('workspaceId', 0);

        $model = trim((string) ($node['model'] ?? $node['aiModel'] ?? '')) ?: 'gpt-4o-mini';
        $ml = strtolower($model);
        $provider = str_starts_with($ml, 'claude') ? 'anthropic'
            : (str_starts_with($ml, 'gemini') ? 'gemini'
            : ((str_starts_with($ml, 'mistral') || str_starts_with($ml, 'ministral') || str_starts_with($ml, 'open-mistral') || str_starts_with($ml, 'open-mixtral')) ? 'mistral' : 'openai'));

        $system = trim((string) ($node['system'] ?? $node['systemPrompt'] ?? $node['prompt'] ?? ''))
            ?: 'You are a helpful assistant replying inside a Viber conversation. Be concise and friendly.';
        $system = $this->subst($system, $vars);

        $assistantId = (int) ($node['assistantId'] ?? $node['assistant_id'] ?? $node['knowledgeBaseId'] ?? 0);
        if ($assistantId > 0 && $wsId > 0) {
            try {
                $assistant = \App\Models\AiChatAssistant::where('workspace_id', $wsId)->find($assistantId);
                if ($assistant) {
                    $kb = app(\App\Services\AiChat\AiChatService::class)->contextFor($assistant);
                    if (trim($kb) !== '') {
                        $system .= "\n\n--- Knowledge base ---\n".$kb."\n--- End knowledge base ---";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[VIBER-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
            }
        }

        $userPrompt = $this->subst((string) ($node['userPrompt'] ?? $node['user_prompt'] ?? ''), $vars);
        if (trim($userPrompt) === '') {
            $userPrompt = (string) ($vars['text'] ?? $vars['user_message'] ?? $vars['last_message'] ?? '');
        }

        $reply = (string) (app(\App\Services\AiAgentService::class)->callProvider(
            provider: $provider, model: $model, workspaceId: $wsId,
            systemPrompt: $system, userPrompt: $userPrompt,
            maxTokens: (int) ($node['maxTokens'] ?? $node['max_tokens'] ?? 350),
            temperature: (float) ($node['temperature'] ?? 0.7), jsonMode: false,
        ) ?? '');

        $out = ['ok' => true, 'type' => 'ai', 'reply' => $reply];
        $save = trim((string) ($node['save'] ?? ''));
        if ($save !== '') {
            $out['vars'] = [$save => $reply];
        }

        return response()->json($out);
    }

    private function handleWebhook(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $url = trim($this->subst((string) ($node['url'] ?? ''), $vars));
        if ($url === '' || ! $this->isPublicHttpUrl($url)) {
            return response()->json(['ok' => false, 'type' => 'webhook', 'vars' => []]);
        }
        $method = strtoupper(trim((string) ($node['method'] ?? 'POST')));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            $method = 'POST';
        }
        $contentType = (string) ($node['contentType'] ?? 'application/json');
        $saveAs = trim((string) ($node['variable'] ?? 'response')) ?: 'response';
        $headers = ['Content-Type' => $contentType];
        foreach ((array) ($node['headers'] ?? []) as $h) {
            $k = trim((string) ($h['key'] ?? ''));
            if ($k !== '') {
                $headers[$k] = $this->subst((string) ($h['value'] ?? ''), $vars);
            }
        }
        $body = $this->subst((string) ($node['body'] ?? ''), $vars);
        $outVars = [];
        try {
            $http = Http::withHeaders($headers)->timeout(15)->withoutRedirecting();
            if ($method === 'GET' || $method === 'HEAD') {
                $resp = $http->send($method, $url);
            } elseif (str_contains($contentType, 'json')) {
                $decoded = $body !== '' ? json_decode($body, true) : null;
                $resp = $http->withBody(is_array($decoded) ? json_encode($decoded) : $body, $contentType)->send($method, $url);
            } else {
                $resp = $http->withBody($body, $contentType)->send($method, $url);
            }
            $json = $resp->json();
            $outVars[$saveAs] = is_array($json) ? json_encode($json) : (string) $resp->body();
            if (is_array($json)) {
                $this->flattenInto($outVars, $saveAs, $json);
            }
        } catch (\Throwable $e) {
            Log::warning('[VIBER-FLOW-NODE] webhook request failed: '.mb_substr($e->getMessage(), 0, 200));
        }

        return response()->json(['ok' => true, 'type' => 'webhook', 'vars' => $outVars]);
    }

    private function flattenInto(array &$bag, string $prefix, array $data): void
    {
        foreach ($data as $k => $v) {
            $key = $prefix.'.'.$k;
            if (is_array($v)) {
                $this->flattenInto($bag, $key, $v);
            } elseif (is_scalar($v) || $v === null) {
                $bag[$key] = $v === null ? '' : (string) $v;
            }
        }
    }

    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }
        $ips = @gethostbynamel($host) ?: [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        }
        if (empty($ips)) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
