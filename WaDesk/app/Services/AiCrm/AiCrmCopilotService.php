<?php

namespace App\Services\AiCrm;

use App\Models\AdminAiKey;
use App\Models\AiCrmAction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiKeyResolver;
use App\Services\AiTokenMeter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI CRM Copilot — a natural-language assistant that runs the CRM via
 * LLM tool-calling. Given a staff member's message, it decides which CRM
 * tool(s) to call (from CrmToolkit), executes them, and replies in prose.
 *
 * Design notes
 * - Provider-agnostic: OpenAI / Anthropic / Gemini tool-calling, resolved via
 *   the same admin/BYOK key stack (AiKeyResolver) as the rest of the app.
 * - Confirm-before-act: WRITE tools (create/move) are never executed on the
 *   first pass. The intended action is parked in the cache and the user is
 *   asked to confirm ("reply YES"). confirmPending() then runs it deterministically
 *   — the LLM never gets to silently mutate CRM data.
 * - Every executed tool is logged to ai_crm_actions with masked params.
 *
 * Same service backs the /ai-crm dashboard and the WhatsApp staff channel.
 */
class AiCrmCopilotService
{
    private const MAX_STEPS = 5;
    private const MAX_TOKENS = 800;
    /** Preferred provider order when the workspace hasn't pinned one. */
    private const PROVIDER_ORDER = ['anthropic', 'openai', 'gemini'];

    /**
     * Handle one user turn.
     *
     * @param  array  $history  prior turns as [['role'=>'user'|'assistant','text'=>string], ...]
     * @return array{reply:string, actions:array, pending:?array, provider:?string}
     */
    public function ask(Workspace $ws, ?User $user, string $channel, array $history, string $message): array
    {
        $userId = $user?->id;

        // Pending confirm-before-act: if a write is parked and the user affirmed, run it.
        $pending = $this->pending($ws->id, $userId, $channel);
        if ($pending && $this->isAffirmative($message)) {
            return $this->runPending($ws, $user, $channel, $pending);
        }
        if ($pending && $this->isNegative($message)) {
            $this->clearPending($ws->id, $userId, $channel);
            $this->log($ws->id, $userId, $channel, $pending['tool'], 'write', 'cancelled', $pending['args'], 'Cancelled by user', null, null, 0);
            return ['reply' => 'Okay, cancelled — nothing was changed.', 'actions' => [], 'pending' => null, 'provider' => null];
        }

        [$provider, $model, $key] = $this->resolveProvider($ws);
        if (!$key) {
            return ['reply' => 'No AI provider is configured. Ask your admin to add an AI key.', 'actions' => [], 'pending' => null, 'provider' => null];
        }

        $toolkit = new CrmToolkit($ws->id, $userId);
        $system  = $this->systemPrompt($ws);

        try {
            return match ($provider) {
                'openai'    => $this->runOpenAI($ws, $user, $channel, $key, $model, $system, $history, $message, $toolkit),
                'anthropic' => $this->runAnthropic($ws, $user, $channel, $key, $model, $system, $history, $message, $toolkit),
                'gemini'    => $this->runGemini($ws, $user, $channel, $key, $model, $system, $history, $message, $toolkit),
                default     => ['reply' => 'AI provider not supported.', 'actions' => [], 'pending' => null, 'provider' => $provider],
            };
        } catch (\Throwable $e) {
            Log::error('[AI-CRM] loop error: ' . $e->getMessage());
            return ['reply' => 'Sorry, something went wrong handling that. Please try again.', 'actions' => [], 'pending' => null, 'provider' => $provider];
        }
    }

    // ---- provider loops -----------------------------------------------------

    private function runOpenAI(Workspace $ws, ?User $user, string $channel, string $key, string $model, string $system, array $history, string $message, CrmToolkit $toolkit): array
    {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $h['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $tools = array_map(fn ($d) => [
            'type' => 'function',
            'function' => ['name' => $d['name'], 'description' => $d['description'], 'parameters' => $d['parameters']],
        ], $toolkit->definitions());

        $m = strtolower($model);
        $newFamily = (bool) preg_match('/^(gpt-5|gpt-6|o[1-9])/', $m);
        $actions = [];
        $tokens = 0;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $payload = ['model' => $model, 'messages' => $messages, 'tools' => $tools];
            $payload[$newFamily ? 'max_completion_tokens' : 'max_tokens'] = self::MAX_TOKENS;
            if (!$newFamily) $payload['temperature'] = 0.2;

            $res = Http::withToken($key)->timeout(45)->post('https://api.openai.com/v1/chat/completions', $payload);
            if (!$res->ok()) {
                Log::warning('[AI-CRM] OpenAI non-200', ['status' => $res->status(), 'body' => substr($res->body(), 0, 300)]);
                return ['reply' => 'The AI service returned an error. Please try again.', 'actions' => $actions, 'pending' => null, 'provider' => 'openai'];
            }
            $tokens += (int) ($res->json('usage.total_tokens') ?? 0);
            $msg = $res->json('choices.0.message') ?? [];
            $calls = $msg['tool_calls'] ?? [];

            if (empty($calls)) {
                $reply = trim((string) ($msg['content'] ?? ''));
                $this->meter($ws, 'openai', $model, $tokens);
                return ['reply' => $reply ?: 'Done.', 'actions' => $actions, 'pending' => null, 'provider' => 'openai'];
            }

            $messages[] = $msg; // assistant turn carrying the tool_calls
            foreach ($calls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: [];

                if ($toolkit->kindOf($name) === 'write') {
                    $this->meter($ws, 'openai', $model, $tokens);
                    return $this->askConfirm($ws, $user, $channel, $name, $args, $toolkit, $actions, 'openai');
                }
                $result = $toolkit->execute($name, $args);
                $actions[] = ['tool' => $name, 'summary' => $result['summary'] ?? ''];
                $this->log($ws->id, $user?->id, $channel, $name, 'read', $result['ok'] ? 'ok' : 'error', $args, $result['summary'] ?? '', 'openai', $model, 0, $result);
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => json_encode($result['data'] ?? $result)];
            }
        }
        $this->meter($ws, 'openai', $model, $tokens);
        return ['reply' => 'I gathered the data but ran out of steps — please ask again more specifically.', 'actions' => $actions, 'pending' => null, 'provider' => 'openai'];
    }

    private function runAnthropic(Workspace $ws, ?User $user, string $channel, string $key, string $model, string $system, array $history, string $message, CrmToolkit $toolkit): array
    {
        $messages = [];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $h['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $tools = array_map(fn ($d) => [
            'name' => $d['name'], 'description' => $d['description'], 'input_schema' => $d['parameters'],
        ], $toolkit->definitions());

        $actions = [];
        $tokens = 0;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model, 'max_tokens' => self::MAX_TOKENS,
                    'system' => $system, 'messages' => $messages, 'tools' => $tools,
                ]);
            if (!$res->ok()) {
                Log::warning('[AI-CRM] Anthropic non-200', ['status' => $res->status(), 'body' => substr($res->body(), 0, 400)]);
                return ['reply' => 'The AI service returned an error. Please try again.', 'actions' => $actions, 'pending' => null, 'provider' => 'anthropic'];
            }
            $tokens += (int) ($res->json('usage.input_tokens') ?? 0) + (int) ($res->json('usage.output_tokens') ?? 0);
            $content = $res->json('content') ?? [];
            $stop = $res->json('stop_reason');

            if ($stop !== 'tool_use') {
                $text = '';
                foreach ($content as $b) { if (($b['type'] ?? '') === 'text') $text .= $b['text']; }
                $this->meter($ws, 'anthropic', $model, $tokens);
                return ['reply' => trim($text) ?: 'Done.', 'actions' => $actions, 'pending' => null, 'provider' => 'anthropic'];
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];
            foreach ($content as $b) {
                if (($b['type'] ?? '') !== 'tool_use') continue;
                $name = $b['name'] ?? '';
                $args = $b['input'] ?? [];

                if ($toolkit->kindOf($name) === 'write') {
                    $this->meter($ws, 'anthropic', $model, $tokens);
                    return $this->askConfirm($ws, $user, $channel, $name, $args, $toolkit, $actions, 'anthropic');
                }
                $result = $toolkit->execute($name, $args);
                $actions[] = ['tool' => $name, 'summary' => $result['summary'] ?? ''];
                $this->log($ws->id, $user?->id, $channel, $name, 'read', $result['ok'] ? 'ok' : 'error', $args, $result['summary'] ?? '', 'anthropic', $model, 0, $result);
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'] ?? '', 'content' => json_encode($result['data'] ?? $result)];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        $this->meter($ws, 'anthropic', $model, $tokens);
        return ['reply' => 'I gathered the data but ran out of steps — please ask again more specifically.', 'actions' => $actions, 'pending' => null, 'provider' => 'anthropic'];
    }

    private function runGemini(Workspace $ws, ?User $user, string $channel, string $key, string $model, string $system, array $history, string $message, CrmToolkit $toolkit): array
    {
        $contents = [];
        foreach ($history as $h) {
            $contents[] = ['role' => $h['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string) $h['text']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $tools = [['function_declarations' => array_map(fn ($d) => [
            'name' => $d['name'], 'description' => $d['description'], 'parameters' => $d['parameters'],
        ], $toolkit->definitions())]];

        $actions = [];
        $tokens = 0;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $res = Http::timeout(45)->post($url, [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'tools' => $tools,
                'generationConfig' => ['maxOutputTokens' => self::MAX_TOKENS, 'temperature' => 0.2],
            ]);
            if (!$res->ok()) {
                Log::warning('[AI-CRM] Gemini non-200', ['status' => $res->status(), 'body' => substr($res->body(), 0, 300)]);
                return ['reply' => 'The AI service returned an error. Please try again.', 'actions' => $actions, 'pending' => null, 'provider' => 'gemini'];
            }
            $tokens += (int) ($res->json('usageMetadata.totalTokenCount') ?? 0);
            $parts = $res->json('candidates.0.content.parts') ?? [];
            $fnCalls = array_values(array_filter($parts, fn ($p) => isset($p['functionCall'])));

            if (empty($fnCalls)) {
                $text = '';
                foreach ($parts as $p) { if (isset($p['text'])) $text .= $p['text']; }
                $this->meter($ws, 'gemini', $model, $tokens);
                return ['reply' => trim($text) ?: 'Done.', 'actions' => $actions, 'pending' => null, 'provider' => 'gemini'];
            }

            $contents[] = ['role' => 'model', 'parts' => $parts];
            $responseParts = [];
            foreach ($fnCalls as $p) {
                $name = $p['functionCall']['name'] ?? '';
                $args = $p['functionCall']['args'] ?? [];

                if ($toolkit->kindOf($name) === 'write') {
                    $this->meter($ws, 'gemini', $model, $tokens);
                    return $this->askConfirm($ws, $user, $channel, $name, $args, $toolkit, $actions, 'gemini');
                }
                $result = $toolkit->execute($name, $args);
                $actions[] = ['tool' => $name, 'summary' => $result['summary'] ?? ''];
                $this->log($ws->id, $user?->id, $channel, $name, 'read', $result['ok'] ? 'ok' : 'error', $args, $result['summary'] ?? '', 'gemini', $model, 0, $result);
                $responseParts[] = ['functionResponse' => ['name' => $name, 'response' => (array) ($result['data'] ?? $result)]];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }
        $this->meter($ws, 'gemini', $model, $tokens);
        return ['reply' => 'I gathered the data but ran out of steps — please ask again more specifically.', 'actions' => $actions, 'pending' => null, 'provider' => 'gemini'];
    }

    // ---- confirm-before-act -------------------------------------------------

    /** Park a write action and ask the user to confirm. */
    private function askConfirm(Workspace $ws, ?User $user, string $channel, string $tool, array $args, CrmToolkit $toolkit, array $actions, string $provider): array
    {
        $summary = $this->describeWrite($tool, $args);
        $this->setPending($ws->id, $user?->id, $channel, ['tool' => $tool, 'args' => $args, 'summary' => $summary]);
        $this->log($ws->id, $user?->id, $channel, $tool, 'write', 'needs_confirm', $args, $summary, $provider, null, 0);

        return [
            'reply' => $summary . "\n\nReply YES to confirm, or NO to cancel.",
            'actions' => $actions,
            'pending' => ['tool' => $tool, 'summary' => $summary],
            'provider' => $provider,
        ];
    }

    /** Execute a previously-parked write action after the user confirmed. */
    private function runPending(Workspace $ws, ?User $user, string $channel, array $pending): array
    {
        $this->clearPending($ws->id, $user?->id, $channel);
        $toolkit = new CrmToolkit($ws->id, $user?->id);
        $result = $toolkit->execute($pending['tool'], $pending['args']);
        $this->log(
            $ws->id, $user?->id, $channel, $pending['tool'], 'write',
            $result['ok'] ? 'confirmed' : 'error', $pending['args'], $result['summary'] ?? '', null, null, 0, $result
        );

        return [
            'reply' => $result['ok'] ? ('Done. ' . ($result['summary'] ?? '')) : ('That failed: ' . ($result['summary'] ?? 'unknown error')),
            'actions' => [['tool' => $pending['tool'], 'summary' => $result['summary'] ?? '']],
            'pending' => null,
            'provider' => null,
        ];
    }

    private function describeWrite(string $tool, array $args): string
    {
        return match ($tool) {
            'create_contact'  => 'I\'ll create a contact "' . ($args['name'] ?? '?') . '" (' . self::maskPhone((string) ($args['phone'] ?? '')) . ').',
            'create_deal'     => 'I\'ll create a deal "' . ($args['title'] ?? '?') . '"'
                                 . (isset($args['value']) ? ' worth ' . $args['value'] : '')
                                 . (!empty($args['stage']) ? ' in stage "' . $args['stage'] . '"' : '') . '.',
            'move_deal_stage' => 'I\'ll move deal #' . ($args['deal_id'] ?? '?') . ' to stage "' . ($args['stage'] ?? '?') . '".',
            'create_company'  => 'I\'ll create a company "' . ($args['name'] ?? '?') . '"'
                                 . (!empty($args['industry']) ? ' (' . $args['industry'] . ')' : '') . '.',
            'link_contact_company' => 'I\'ll link ' . self::maskPhone((string) ($args['contact_phone'] ?? '')) . ' to company "' . ($args['company_name'] ?? '?') . '".',
            'create_invoice'  => 'I\'ll create an invoice for "' . ($args['buyer_name'] ?? '?') . '" with '
                                 . count((array) ($args['items'] ?? [])) . ' item(s).',
            'record_payment'  => 'I\'ll record a payment of ' . ($args['amount'] ?? '?')
                                 . (!empty($args['invoice_number']) ? ' against invoice ' . $args['invoice_number'] : '') . '.',
            'create_task'     => 'I\'ll create a task "' . ($args['title'] ?? '?') . '"'
                                 . (!empty($args['assignee_name']) ? ' for ' . $args['assignee_name'] : '')
                                 . (!empty($args['due_at']) ? ' due ' . $args['due_at'] : '') . '.',
            'assign_task'     => 'I\'ll assign task #' . ($args['task_id'] ?? '?') . ' to ' . ($args['assignee_name'] ?? '?') . '.',
            'complete_task'   => 'I\'ll mark task #' . ($args['task_id'] ?? '?') . ' as done.',
            'create_project'  => 'I\'ll create a project "' . ($args['name'] ?? '?') . '"'
                                 . (!empty($args['company_name']) ? ' for ' . $args['company_name'] : '')
                                 . (!empty($args['due_date']) ? ' due ' . $args['due_date'] : '') . '.',
            'create_quote'    => 'I\'ll create a ' . (($args['doc_type'] ?? 'proposal') === 'estimate' ? 'estimate' : 'proposal')
                                 . (!empty($args['title']) ? ' "' . $args['title'] . '"' : '')
                                 . ' with ' . count((array) ($args['items'] ?? [])) . ' line item(s).',
            'send_message'    => 'I\'ll send a WhatsApp message to ' . ($args['to'] ?? '?') . '.',
            'send_quote'      => 'I\'ll send ' . ($args['number'] ?? ($args['title'] ?? 'the quote')) . ' to its buyer on WhatsApp.',
            default           => 'I\'ll run ' . $tool . '.',
        };
    }

    // ---- provider resolution + prompt --------------------------------------

    /** @return array{0:string,1:string,2:?string} provider, model, key */
    private function resolveProvider(Workspace $ws): array
    {
        // Pass 1 — prefer a provider the WORKSPACE has its OWN key for (BYOK).
        // Without this, an admin key for a higher-priority provider (e.g. an
        // admin Anthropic key) is returned FIRST and silently SHADOWS the user's
        // correctly-connected OpenAI/Gemini BYOK key.
        foreach (self::PROVIDER_ORDER as $provider) {
            $r = AiKeyResolver::resolve($ws, $provider);
            if (!empty($r['key']) && ($r['source'] ?? '') === 'workspace') {
                $model = $r['model'] ?: $this->defaultModel($provider);
                return [$provider, $model, $r['key']];
            }
        }
        // Pass 2 — no BYOK key: fall back to the first provider that has any
        // (admin/global) key.
        foreach (self::PROVIDER_ORDER as $provider) {
            $r = AiKeyResolver::resolve($ws, $provider);
            if (!empty($r['key'])) {
                $model = $r['model'] ?: $this->defaultModel($provider);
                return [$provider, $model, $r['key']];
            }
        }
        return ['none', '', null];
    }

    private function defaultModel(string $provider): string
    {
        return match ($provider) {
            'openai'    => 'gpt-4o-mini',
            'anthropic' => 'claude-haiku-4-5-20251001',
            'gemini'    => 'gemini-3.5-flash',
            default     => '',
        };
    }

    private function systemPrompt(Workspace $ws): string
    {
        $brand = function_exists('brand_name') ? brand_name() : 'WaDesk';
        return "You are the {$brand} AI CRM Copilot, helping a business staff member manage their CRM by chat. "
            . "Use the provided tools to search and modify contacts, companies and deals, to create invoices, "
            . "record payments, list what is outstanding, to report sales figures, to open and track delivery "
            . "projects, and to create proposals or estimates (priced quotes with a shareable link). "
            . "Always call a tool to get real data — never invent contacts, deals, invoices, or numbers. "
            . "When a tool needs a phone number or a deal id you don't have, first search for it. "
            . "Keep replies short, clear, and business-like. Do not use markdown tables; use short lines. "
            . "Currency and stage names come from the workspace pipeline. Today is " . now()->toDateString() . ".";
    }

    // ---- pending (cache) ----------------------------------------------------

    private function pendingKey(int $wsId, ?int $userId, string $channel): string
    {
        return "aicrm:pending:{$wsId}:" . ($userId ?? 0) . ":{$channel}";
    }
    private function pending(int $wsId, ?int $userId, string $channel): ?array
    {
        return Cache::get($this->pendingKey($wsId, $userId, $channel));
    }
    private function setPending(int $wsId, ?int $userId, string $channel, array $data): void
    {
        Cache::put($this->pendingKey($wsId, $userId, $channel), $data, now()->addMinutes(10));
    }
    private function clearPending(int $wsId, ?int $userId, string $channel): void
    {
        Cache::forget($this->pendingKey($wsId, $userId, $channel));
    }

    // ---- misc ---------------------------------------------------------------

    private function isAffirmative(string $m): bool
    {
        return (bool) preg_match('/^\s*(yes|y|yeah|yep|ok|okay|confirm|confirmed|sure|do it|go|proceed|haan|ha|si|oui)\b/i', $m);
    }
    private function isNegative(string $m): bool
    {
        return (bool) preg_match('/^\s*(no|n|nope|cancel|stop|don\'?t|nahi|nai)\b/i', $m);
    }

    private function meter(Workspace $ws, string $provider, string $model, int $tokens): void
    {
        if ($tokens <= 0) return;
        try {
            $byok = \App\Models\AiProviderKey::keyFor($ws->id, $provider);
            $billed = $byok ? 'workspace' : 'admin';
            // Split ~ 60/40 prompt/completion for the ledger; exact split isn't material.
            AiTokenMeter::record($ws, $provider, $model, (int) round($tokens * 0.6), (int) round($tokens * 0.4), $billed);
        } catch (\Throwable $e) { /* metering best-effort */ }
    }

    private function log(int $wsId, ?int $userId, string $channel, string $tool, string $kind, string $status, array $params, string $summary, ?string $provider, ?string $model, int $tokens, ?array $result = null): void
    {
        try {
            AiCrmAction::create([
                'workspace_id' => $wsId,
                'user_id' => $userId,
                'channel' => $channel,
                'tool' => $tool,
                'kind' => $kind,
                'status' => $status,
                'params' => $this->maskParams($params),
                'result_summary' => mb_substr($summary, 0, 500),
                'provider' => $provider,
                'model' => $model,
                'tokens' => $tokens,
                'subject_type' => $result['subject_type'] ?? null,
                'subject_id' => $result['subject_id'] ?? null,
            ]);
        } catch (\Throwable $e) { /* audit is best-effort, never block a reply */ }
    }

    private function maskParams(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_string($v) && preg_match('/phone|mobile/i', $k)) {
                $params[$k] = self::maskPhone($v);
            }
            if (is_string($v) && strtolower($k) === 'email' && str_contains($v, '@')) {
                [$u, $d] = explode('@', $v, 2);
                $params[$k] = mb_substr($u, 0, 2) . '***@' . $d;
            }
        }
        return $params;
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $len = strlen($digits);
        if ($len <= 4) return $digits;
        return str_repeat('*', max(0, $len - 4)) . substr($digits, -4);
    }
}
