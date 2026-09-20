<?php

namespace App\Http\Controllers\Mailtrixy;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WorkspaceEmailAccount;
use App\Services\Mailtrixy\MailtrixyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Laravel callbacks for the Node email flow engine
 * (node/services/emailFlowService.js). Like WeChat, the Node engine delegates
 * every SEND back here (/api/email/flow-send) so the MailTrixy bridge secret
 * stays managed in one place — Node holds no mail credentials. `node` resolves
 * smart AI / webhook nodes; `log` mirrors an already-sent flow message. All
 * X-Node-Token guarded.
 */
class EmailFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();

        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/email/flow-send — send a flow message via the MailTrixy bridge + mirror it. */
    public function send(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $accountId = (int) $request->input('accountId', 0);
        $mtxConvId = trim((string) $request->input('conversationId', ''));
        $acct      = $accountId > 0 ? WorkspaceEmailAccount::find($accountId) : null;
        if (! $acct || $mtxConvId === '' || ! ctype_digit($mtxConvId)) {
            return response()->json(['ok' => false, 'error' => 'account/conversation missing']);
        }
        // An unlinked mailbox must not keep sending through the bridge — same
        // rule as InboxDispatcher::dispatchMailtrixy.
        if ($acct->status !== 'connected') {
            return response()->json(['ok' => false, 'error' => 'account not connected']);
        }

        $client = MailtrixyClient::fromSettings();
        if (! $client->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'email bridge not configured']);
        }

        $kind = (string) $request->input('kind', 'text');
        $text = (string) $request->input('text', '');
        $body = $text;

        if ($kind === 'menu') {
            // Buttons render as a numbered list in the email body — the Node
            // engine resumes on a reply matching the label OR the 1-based number.
            $lines = [];
            $i = 0;
            foreach ((array) $request->input('options', []) as $o) {
                $title = trim(is_array($o) ? (string) ($o['title'] ?? $o['content'] ?? '') : (string) $o);
                if ($title !== '') {
                    $lines[] = (++$i).'. '.mb_substr($title, 0, 200);
                }
            }
            if ($lines) {
                $body = trim($text."\n\n".implode("\n", $lines));
            }
        } elseif ($kind === 'media') {
            // MailTrixy's reply contract carries text/html only — attachments
            // can't cross the bridge, so ship the media as a link line.
            $url = trim((string) $request->input('mediaUrl', ''));
            if ($url !== '') {
                $body = trim(($text !== '' ? $text."\n\n" : '').$url);
            }
        }

        if (trim($body) === '') {
            return response()->json(['ok' => false, 'error' => 'empty body']);
        }

        $html = nl2br(e($body));
        $res  = $client->reply($mtxConvId, $body, $html);
        $ok   = ($res['ok'] ?? false) === true;
        if ($ok) {
            $this->mirror($acct, $mtxConvId, $body, $res['message_id'] ?? null);
        } else {
            Log::warning('[EMAIL-FLOW-NODE] flow-send failed', [
                'account' => $acct->id, 'mtx_conversation' => $mtxConvId,
                'error'   => (string) ($res['error'] ?? ''),
            ]);
        }

        return response()->json([
            'ok'         => (bool) $ok,
            'message_id' => $res['message_id'] ?? null,
            'error'      => $ok ? '' : (string) ($res['error'] ?? ''),
        ]);
    }

    /** POST /api/email/flow-node — resolve a smart node (ai / webhook). */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $type = (string) ($request->input('action') ?: $request->input('type', ''));
        if ($type === 'ai' || $type === 'email_ai') {
            return $this->handleAi($request);
        }
        if ($type === 'webhook') {
            return $this->handleWebhook($request);
        }

        return response()->json(['ok' => true, 'type' => $type, 'text' => '']);
    }

    /** POST /api/email/flow-log — mirror a flow message into the inbox (no send). */
    public function log(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $accountId = (int) $request->input('accountId', 0);
        $mtxConvId = trim((string) $request->input('conversationId', ''));
        $body      = (string) ($request->input('body', '') ?: $request->input('text', ''));
        $acct      = $accountId > 0 ? WorkspaceEmailAccount::find($accountId) : null;

        // Same guard as send() / InboxDispatcher::dispatchMailtrixy: a
        // non-numeric MailTrixy conversation id (or an unlinked mailbox) would
        // mint a thread whose raw_jid can never be replied on.
        if ($acct && $mtxConvId !== '' && ctype_digit($mtxConvId)
            && $acct->status === 'connected' && trim($body) !== '') {
            $this->mirror($acct, $mtxConvId, $body, null, (string) $request->input('source', 'flow'));
        }

        return response()->json(['ok' => true]);
    }

    // -----------------------------------------------------------------

    /** Mirror an outbound flow message onto the email thread in the inbox. */
    private function mirror(WorkspaceEmailAccount $acct, string $mtxConvId, string $body, $messageId = null, string $source = 'flow'): void
    {
        if (trim($body) === '') {
            return;
        }
        try {
            $wsId = (int) $acct->workspace_id;
            // provider MUST be in the create attributes — InboxMessage stamps its
            // provider from the parent conversation on create (see
            // MailtrixyIngestService), so a thread created without one would
            // stamp the workspace's WhatsApp engine.
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'email', 'raw_jid' => 'email:'.$acct->id.':'.$mtxConvId],
                ['title' => $acct->email ?: 'Email', 'provider' => 'email', 'origin' => 'email', 'status' => 'pending', 'inbox_status' => 'open', 'last_message_at' => now(), 'contact_digits' => null]
            );
            InboxMessage::create([
                'conversation_id' => $conv->id,
                'provider'        => 'email',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => ['email' => array_filter([
                    'mtx_conversation_id' => $mtxConvId,
                    'mtx_message_id'      => $messageId,
                    'mtx_account_id'      => (int) $acct->mailtrixy_account_id,
                    'source'              => $source,
                ], fn ($v) => $v !== null && $v !== '')],
                'sent_at'         => now(),
            ]);
            $update = ['preview' => Str::limit($body, 120), 'last_message_at' => now()];
            if (Schema::hasColumn('conversations', 'last_outbound_at')) {
                $update['last_outbound_at'] = now();
            }
            $conv->forceFill($update)->save();
        } catch (\Throwable $e) {
            Log::warning('[EMAIL-FLOW-NODE] mirror failed: '.$e->getMessage());
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
            ?: 'You are a helpful assistant replying inside an email conversation. Be concise and professional.';
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
                Log::warning('[EMAIL-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
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
            Log::warning('[EMAIL-FLOW-NODE] webhook request failed: '.mb_substr($e->getMessage(), 0, 200));
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
