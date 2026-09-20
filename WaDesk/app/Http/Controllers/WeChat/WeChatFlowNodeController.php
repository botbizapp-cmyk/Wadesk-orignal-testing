<?php

namespace App\Http\Controllers\WeChat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WeChatChannel;
use App\Services\WeChat\WeChatClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Laravel callbacks for the Node WeChat flow engine
 * (node/services/wechatFlowService.js). Unlike LINE (whose Node engine sends
 * directly), the WeChat Node engine delegates every SEND back here
 * (/api/wechat/flow-send) so the SHARED access_token stays managed in one place.
 * `node` resolves smart AI / webhook nodes. Both are X-Node-Token guarded.
 */
class WeChatFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();

        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/wechat/flow-send — send a flow message via the managed token + mirror it. */
    public function send(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $channelId = (int) $request->input('channelId', 0);
        $openid    = (string) $request->input('openid', '');
        $channel   = $channelId > 0 ? WeChatChannel::find($channelId) : null;
        if (! $channel || $openid === '') {
            return response()->json(['ok' => false, 'error' => 'channel/openid missing']);
        }

        $client = new WeChatClient($channel);
        $kind   = (string) $request->input('kind', 'text');
        $text   = (string) $request->input('text', '');
        $mirror = $text;

        if ($kind === 'menu') {
            $items = [];
            foreach ((array) $request->input('options', []) as $i => $o) {
                $c = is_array($o) ? (string) ($o['title'] ?? $o['content'] ?? '') : (string) $o;
                if (trim($c) !== '') {
                    $items[] = ['id' => 'opt_'.$i, 'content' => mb_substr($c, 0, 200)];
                }
            }
            $message = $items ? WeChatClient::msgmenuMessage($text, $items) : WeChatClient::textMessage($text !== '' ? $text : ' ');
        } elseif ($kind === 'media') {
            [$message, $mirror] = $this->buildMedia($client, $request, $text);
        } else {
            $message = WeChatClient::textMessage($text !== '' ? $text : ' ');
        }

        $r  = $client->sendCustomMessage($openid, $message);
        $ok = $r['ok'] ?? false;
        if ($ok) {
            $this->mirror($channel, $openid, $mirror);
        }

        return response()->json(['ok' => (bool) $ok, 'error' => $ok ? '' : (string) ($r['error'] ?? '')]);
    }

    /** POST /api/wechat/flow-node — resolve a smart node (ai / webhook). */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $type = (string) ($request->input('action') ?: $request->input('type', ''));
        if ($type === 'ai' || $type === 'wechat_ai') {
            return $this->handleAi($request);
        }
        if ($type === 'webhook') {
            return $this->handleWebhook($request);
        }

        return response()->json(['ok' => true, 'type' => $type, 'text' => '']);
    }

    // -----------------------------------------------------------------

    /** Fetch a flow media URL → media_id → WeChat media message; fall back to text. */
    private function buildMedia(WeChatClient $client, Request $request, string $text): array
    {
        $url  = (string) $request->input('mediaUrl', '');
        $kind = strtolower((string) $request->input('mediaKind', 'image'));
        $wxType = match ($kind) { 'audio', 'voice' => 'voice', 'video' => 'video', default => 'image' };
        if ($url !== '') {
            try {
                $res = Http::timeout(30)->get($url);
                if ($res->successful()) {
                    $up = $client->uploadMedia($wxType, $res->body(), 'flow.'.($wxType === 'voice' ? 'amr' : ($wxType === 'video' ? 'mp4' : 'jpg')));
                    if ($up['ok'] ?? false) {
                        $mid = (string) $up['media_id'];
                        $msg = match ($wxType) {
                            'voice' => WeChatClient::voiceMessage($mid),
                            'video' => WeChatClient::videoMessage($mid, $mid),
                            default => WeChatClient::imageMessage($mid),
                        };

                        return [$msg, $text !== '' ? $text : '['.$wxType.']'];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to text
            }
        }
        $t = $text !== '' ? $text : $url;

        return [WeChatClient::textMessage($t !== '' ? $t : ' '), $t];
    }

    /** Mirror an outbound flow message into the inbox. */
    private function mirror(WeChatChannel $channel, string $openid, string $body): void
    {
        if (trim($body) === '') {
            return;
        }
        try {
            $wsId = (int) $channel->workspace_id;
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'wechat', 'raw_jid' => 'wechat:'.$channel->id.':'.$openid],
                ['title' => 'WeChat', 'provider' => 'wechat', 'origin' => 'wechat', 'status' => 'pending', 'inbox_status' => 'open', 'last_message_at' => now(), 'contact_digits' => null]
            );
            InboxMessage::create([
                'conversation_id' => $conv->id,
                'provider'        => 'wechat',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => ['wechat' => ['openid' => $openid, 'source' => 'flow']],
                'sent_at'         => now(),
            ]);
            $conv->forceFill(['preview' => Str::limit($body, 120), 'last_message_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('[WECHAT-FLOW-NODE] mirror failed: '.$e->getMessage());
        }
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
            ?: 'You are a helpful assistant replying inside a WeChat conversation. Be concise and friendly.';
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
                Log::warning('[WECHAT-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
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
            Log::warning('[WECHAT-FLOW-NODE] webhook request failed: '.mb_substr($e->getMessage(), 0, 200));
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
