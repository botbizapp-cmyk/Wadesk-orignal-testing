<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel callbacks for the Node Telegram flow engine
 * (node/services/telegramFlowService.js). Node runs every SEND itself via the
 * Bot API and calls back here only to mirror a flow message into the inbox
 * (flow-log) and to resolve "smart" nodes — AI / webhook (flow-node). Both are
 * X-Node-Token guarded. Mirrors TiktokFlowNodeController.
 */
class TelegramFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();

        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/telegram/flow-log — mirror an outbound flow message into the inbox. */
    public function log(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $botId  = (int) $request->input('botId', 0);
        $chatId = (string) $request->input('chatId', '');
        $body   = (string) $request->input('body', '');
        $direction = $request->input('direction') === 'in' ? 'in' : 'out';
        $bot = $botId > 0 ? TelegramBot::find($botId) : null;

        if ($bot && $chatId !== '' && $body !== '') {
            $wsId = (int) $bot->workspace_id;
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'telegram', 'raw_jid' => 'tg:'.$bot->id.':'.$chatId],
                ['title' => 'Telegram', 'provider' => 'telegram', 'origin' => 'telegram', 'status' => 'pending', 'inbox_status' => 'open', 'last_message_at' => now(), 'contact_digits' => null]
            );
            try {
                InboxMessage::create(array_filter([
                    'conversation_id' => $conv->id,
                    'provider'        => 'telegram',
                    'direction'       => $direction,
                    'body'            => $body,
                    'status'          => $direction === 'in' ? 'received' : 'sent',
                    'meta'            => ['telegram' => array_filter([
                        'chat_id'    => $chatId,
                        'message_id' => (string) $request->input('mid', ''),
                        'source'     => (string) $request->input('source', 'flow'),
                        'buttons'    => is_array($request->input('buttons')) ? $request->input('buttons') : null,
                    ], fn ($v) => $v !== null && $v !== '')],
                    'sent_at'         => now(),
                ], fn ($v) => $v !== null));
                $conv->forceFill(['preview' => \Illuminate\Support\Str::limit($body, 120), 'last_message_at' => now()])->save();
            } catch (\Throwable $e) {
                Log::warning('[TG-FLOW-NODE] flow-log write failed: '.$e->getMessage());
            }
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/telegram/flow-node — resolve a smart node (ai / webhook). */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $type = (string) ($request->input('action') ?: $request->input('type', ''));
        if ($type === 'ai' || $type === 'tg_ai') {
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
            ?: 'You are a helpful assistant replying inside a Telegram conversation. Be concise and friendly.';
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
                Log::warning('[TG-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
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
            Log::warning('[TG-FLOW-NODE] webhook request failed: '.mb_substr($e->getMessage(), 0, 200));
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
