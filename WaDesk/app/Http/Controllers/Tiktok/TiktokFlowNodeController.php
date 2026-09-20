<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\TiktokAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel callbacks for the ported TikTok flow engine on Node
 * (node/services/tiktokFlowService.js). Node runs every SEND node itself via the
 * Business Messaging API and calls back here for two things only:
 *
 *   POST /api/tiktok/flow-log   — mirror a flow message into WaDesk's inbox.
 *   POST /api/tiktok/flow-node  — resolve a "smart" node (AI / webhook).
 *
 * The TikTok clone of FacebookFlowNodeController: resolves a TiktokAccount by its
 * row id, mirrors into the channel='tiktok' inbox (raw_jid 'tt:<openId>:<convId>',
 * matching TiktokIngestService), and reuses the SAME AI-provider + SSRF-guarded
 * webhook path so a flow built once behaves identically on every channel. Both
 * endpoints are guarded with the shared X-Node-Token.
 */
class TiktokFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();
        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/tiktok/flow-log — mirror an outbound flow message into the inbox. */
    public function log(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) return response()->json(['ok' => false], 401);

        $accountId = (int) $request->input('accountId', 0);
        $convId    = (string) $request->input('convId', '');
        $body      = (string) $request->input('body', '');
        $direction = $request->input('direction') === 'in' ? 'in' : 'out';
        $account   = $accountId > 0 ? TiktokAccount::find($accountId) : null;

        $mediaType = ($t = trim((string) $request->input('mediaType', ''))) !== '' ? $t : null;
        $mediaPath = ($p = trim((string) $request->input('mediaPath', ''))) !== '' ? $p : null;
        $buttons   = is_array($request->input('buttons')) ? $request->input('buttons') : null;

        if ($account && $convId !== '' && ($body !== '' || $mediaPath !== null)) {
            $wsId = (int) $account->workspace_id;
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'tiktok', 'raw_jid' => 'tt:'.$account->open_id.':'.$convId],
                [
                    'title'           => $account->display_name ?: __('TikTok user'),
                    'provider'        => 'tiktok',
                    'origin'          => 'tiktok',
                    'status'          => 'pending',
                    'inbox_status'    => 'open',
                    'last_message_at' => now(),
                    'contact_digits'  => null,
                ]
            );

            try {
                InboxMessage::create(array_filter([
                    'conversation_id' => $conv->id,
                    'provider'        => 'tiktok',
                    'direction'       => $direction,
                    'body'            => $body,
                    'media_type'      => $mediaType,
                    'media_path'      => $mediaPath,
                    'status'          => $direction === 'in' ? 'received' : 'sent',
                    'meta'            => ['tiktok' => array_filter([
                        'message_id'      => (string) $request->input('mid', ''),
                        'conversation_id' => $convId,
                        'source'          => (string) $request->input('source', 'flow'),
                        'buttons'         => $buttons,
                    ], fn ($v) => $v !== null && $v !== '')],
                    'sent_at'         => now(),
                ], fn ($v) => $v !== null));

                $conv->forceFill([
                    'preview'         => \Illuminate\Support\Str::limit($body ?: '['.$mediaType.']', 120),
                    'last_message_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                Log::warning('[TT-FLOW-NODE] flow-log write failed: '.$e->getMessage());
            }
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/tiktok/flow-node — resolve a smart node (ai / webhook). */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) return response()->json(['ok' => false], 401);

        $type = (string) ($request->input('action') ?: $request->input('type', ''));

        if ($type === 'ai' || $type === 'tt_ai') {
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

    /** AI reply node — same provider path as the WhatsApp/Facebook flow AI node. */
    private function handleAi(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $wsId = (int) $request->input('workspaceId', 0);

        $model = trim((string) ($node['model'] ?? $node['aiModel'] ?? '')) ?: 'gpt-4o-mini';
        $ml = strtolower($model);
        $provider = str_starts_with($ml, 'claude') ? 'anthropic'
            : (str_starts_with($ml, 'gemini') ? 'gemini'
            : ((str_starts_with($ml, 'mistral') || str_starts_with($ml, 'ministral') || str_starts_with($ml, 'open-mistral') || str_starts_with($ml, 'open-mixtral')) ? 'mistral'
            : 'openai'));

        $system = trim((string) ($node['system'] ?? $node['systemPrompt'] ?? $node['prompt'] ?? ''))
            ?: 'You are a helpful assistant replying inside a TikTok direct-message conversation. Be concise and friendly.';
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
                Log::warning('[TT-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
            }
        }

        $userPrompt = $this->subst((string) ($node['userPrompt'] ?? $node['user_prompt'] ?? ''), $vars);
        if (trim($userPrompt) === '') {
            $userPrompt = (string) ($vars['text'] ?? $vars['user_message'] ?? $vars['last_message'] ?? '');
        }

        $reply = (string) (app(\App\Services\AiAgentService::class)->callProvider(
            provider:     $provider,
            model:        $model,
            workspaceId:  $wsId,
            systemPrompt: $system,
            userPrompt:   $userPrompt,
            maxTokens:    (int) ($node['maxTokens'] ?? $node['max_tokens'] ?? 350),
            temperature:  (float) ($node['temperature'] ?? 0.7),
            jsonMode:     false,
        ) ?? '');

        $out = ['ok' => true, 'type' => 'ai', 'reply' => $reply];
        $save = trim((string) ($node['save'] ?? ''));
        if ($save !== '') {
            $out['vars'] = [$save => $reply];
        }

        return response()->json($out);
    }

    /** Webhook node — SSRF-guarded server-side HTTP call, JSON flattened to dotted vars. */
    private function handleWebhook(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);

        $url = trim($this->subst((string) ($node['url'] ?? ''), $vars));
        if ($url === '' || ! $this->isPublicHttpUrl($url)) {
            Log::warning('[TT-FLOW-NODE] webhook skipped — empty or unsafe url', ['url' => $url]);
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
            Log::warning('[TT-FLOW-NODE] webhook request failed: '.mb_substr($e->getMessage(), 0, 200), ['url' => $url]);
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

    /** SSRF guard: http/https to a PUBLIC IP only. */
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
