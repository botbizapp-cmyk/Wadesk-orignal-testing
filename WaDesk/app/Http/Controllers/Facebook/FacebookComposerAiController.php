<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\AdminAiKey;
use App\Services\AiAgentService;
use App\Services\AiKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AI composer tools for the native WaDesk Facebook post composer
 * (/facebook/posts). A faithful port of the Instagram ComposerAiController —
 * same generic engines, Facebook-flavoured prompts.
 *
 * Five JSON endpoints backing the "AI composer tools" card. All are web+auth
 * gated and same-origin CSRF-protected. Each degrades gracefully to
 * { ok:false, error } when no AI key is configured.
 *
 * TEXT tools (caption / repurpose / review) route through AiAgentService (the
 * same engine the auto-reply agent + AI-suggest use); the key resolves
 * workspace-BYOK → admin via AiKeyResolver. IMAGE generation calls OpenAI's
 * Images API directly (gpt-image-1 → dall-e-3 fallback) and stores the bytes on
 * the public disk so Facebook can fetch the URL when publishing. BEST TIME is a
 * pure heuristic over the workspace's real Facebook inbound-message activity
 * (no AI, no key needed).
 */
class FacebookComposerAiController extends Controller
{
    public function __construct(private AiAgentService $ai)
    {
    }

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function workspace(): ?\App\Models\Workspace
    {
        return Auth::user()?->currentWorkspace;
    }

    /** Plan gate — same feature that governs the AI agent / AI-suggest. */
    private function ensureAi(): ?JsonResponse
    {
        $ws = $this->workspace();
        if (! $ws || ! \App\Services\PlanLimitGuard::hasFeature($ws, 'access_ai_agents')) {
            return response()->json([
                'ok'     => false,
                'locked' => true,
                'error'  => __('AI tools are not included in your plan. Upgrade to use the AI composer.'),
            ], 403);
        }

        return null;
    }

    /* ================= Key / model resolution ================= */

    /** Infer the provider from a model id so a Claude/Gemini/Mistral default routes correctly. */
    private function providerForModel(string $model): string
    {
        $m = strtolower($model);
        if (str_contains($m, 'claude')) {
            return 'anthropic';
        }
        if (str_contains($m, 'gemini')) {
            return 'gemini';
        }
        if (str_contains($m, 'mistral')) {
            return 'mistral';
        }

        return 'openai';
    }

    /** The text model to use: admin openai default_model → a sensible fallback. */
    private function textModel(): string
    {
        $admin = AdminAiKey::activeFor('openai');

        return ($admin && ! empty($admin->default_model)) ? $admin->default_model : 'gpt-4o-mini';
    }

    /** True when a usable key exists for the provider (workspace BYOK → admin). */
    private function hasKeyFor(string $provider): bool
    {
        return ! empty(AiKeyResolver::keyFor($this->workspace(), $provider));
    }

    /** The raw OpenAI key (workspace BYOK → admin) for the direct Images API call. */
    private function openAiKey(): ?string
    {
        return AiKeyResolver::keyFor($this->workspace(), 'openai');
    }

    private const NO_KEY = 'No AI key configured. Add one in Admin → API keys (or your own key under your AI keys).';

    /** Run a text generation through AiAgentService, returning the reply or a JSON error array. */
    private function generate(string $system, string $user, int $maxTokens, float $temp = 0.7): array
    {
        $model    = $this->textModel();
        $provider = $this->providerForModel($model);

        if (! $this->hasKeyFor($provider)) {
            return ['ok' => false, 'error' => setup_hint(self::NO_KEY, 'AI assist is unavailable — add your own key under AI Keys, or contact support.')];
        }

        $text = $this->ai->callProvider($provider, $model, $this->wsId(), $system, $user, $maxTokens, $temp);
        if ($text === null || trim($text) === '') {
            return ['ok' => false, 'error' => 'The AI service did not return a result. Check the API key and try again.'];
        }

        return ['ok' => true, 'text' => trim($text)];
    }

    /* ================= 1. AI Caption ================= */
    public function caption(Request $request): JsonResponse
    {
        if ($r = $this->ensureAi()) {
            return $r;
        }

        $data = $request->validate([
            'notes'      => 'nullable|string|max:2000',
            'media_type' => 'nullable|string|max:20',
            'tone'       => 'nullable|string|max:40',
        ]);

        $notes = trim((string) ($data['notes'] ?? ''));
        $type  = (string) ($data['media_type'] ?? 'post');
        $tone  = trim((string) ($data['tone'] ?? ''));

        $system = 'You are an expert Facebook Page copywriter. Write a single scroll-stopping post for a '
            . $type . '. Keep it native to Facebook: a strong hook on the first line, a short, conversational body, '
            . 'one clear call-to-action, and 2-4 relevant hashtags on the final line (Facebook uses fewer hashtags '
            . 'than Instagram). Use tasteful line breaks. Do NOT wrap the post in quotes and do NOT add any '
            . 'commentary — output only the post text.';
        if ($tone !== '') {
            $system .= ' Tone: ' . $tone . '.';
        }

        $user = $notes !== ''
            ? "Topic / notes for the post:\n" . $notes
            : 'Write an engaging general post suitable for a friendly business Page. Invent a plausible, upbeat topic.';

        return response()->json($this->generate($system, $user, 320, 0.8));
    }

    /* ================= 2. Repurpose ================= */
    public function repurpose(Request $request): JsonResponse
    {
        if ($r = $this->ensureAi()) {
            return $r;
        }

        $data = $request->validate([
            'caption' => 'required|string|max:60000',
            'style'   => 'nullable|string|max:40',
        ]);

        $caption = trim((string) $data['caption']);
        if ($caption === '') {
            return response()->json(['ok' => false, 'error' => 'Write a post first, then Repurpose rewrites it.']);
        }

        $style = trim((string) ($data['style'] ?? ''));
        $ask   = $style !== ''
            ? 'Rewrite it in this style: ' . $style . '.'
            : 'Rewrite it: keep the core message but make it fresh — tighter, a different angle or tone, '
                . 'and a strong hook. Keep it Facebook-native with a CTA and 2-4 hashtags.';

        $system = 'You are an expert Facebook Page copywriter repurposing an existing post. ' . $ask
            . ' Output ONLY the rewritten post text — no quotes, no commentary.';

        return response()->json($this->generate($system, "Original post:\n" . $caption, 320, 0.85));
    }

    /* ================= 3. Review ================= */
    public function review(Request $request): JsonResponse
    {
        if ($r = $this->ensureAi()) {
            return $r;
        }

        $data = $request->validate(['caption' => 'required|string|max:60000']);

        $caption = trim((string) $data['caption']);
        if ($caption === '') {
            return response()->json(['ok' => false, 'error' => 'Write a post first, then Review gives feedback.']);
        }

        $system = 'You are a Facebook growth strategist reviewing a draft post. Give concise, actionable '
            . 'feedback in 3-5 short bullet points covering: the hook, length/readability, call-to-action, '
            . 'and hashtag choice (count + relevance for Facebook). End with one quick "Try this" suggestion. '
            . 'Use plain hyphen bullets, no markdown headers. Be direct and specific.';

        return response()->json($this->generate($system, "Post to review:\n" . $caption, 380, 0.5));
    }

    /* ================= 4. Best time (heuristic, no AI) ================= */
    public function bestTime(Request $request): JsonResponse
    {
        if ($r = $this->ensureAi()) {
            return $r;
        }

        $wsId = $this->wsId();

        // Inbound Facebook messages by hour-of-day over the last 90 days, scoped
        // to this workspace's Facebook (Messenger) conversations.
        $rows = DB::table('inbox_messages')
            ->join('conversations', 'conversations.id', '=', 'inbox_messages.conversation_id')
            ->where('conversations.workspace_id', $wsId)
            ->where('conversations.channel', 'facebook')
            ->where('inbox_messages.direction', 'in')
            ->where('inbox_messages.created_at', '>=', now()->subDays(90))
            ->selectRaw('HOUR(inbox_messages.created_at) as h, COUNT(*) as c')
            ->groupBy('h')->pluck('c', 'h')->all();

        $total = array_sum($rows);

        if ($total < 20) {
            return response()->json([
                'ok'      => true,
                'basis'   => 'general',
                'note'    => 'Based on general Facebook best practices — not enough of your own audience data yet.',
                'windows' => [
                    ['label' => 'Weekdays', 'time' => '09:00 – 11:00', 'why' => 'Morning check-in'],
                    ['label' => 'Weekdays', 'time' => '13:00 – 15:00', 'why' => 'Early-afternoon lull'],
                    ['label' => 'Weekends', 'time' => '12:00 – 14:00', 'why' => 'Midday browsing'],
                ],
            ]);
        }

        arsort($rows);
        $topHours = array_slice(array_keys($rows), 0, 3, true);
        $windows  = [];
        foreach ($topHours as $h) {
            $h   = (int) $h;
            $end = ($h + 2) % 24;
            $pct = $total > 0 ? round(($rows[$h] / $total) * 100) : 0;
            $windows[] = [
                'label' => 'Your audience',
                'time'  => sprintf('%02d:00 – %02d:00', $h, $end),
                'why'   => $pct . '% of inbound messages land around here',
            ];
        }

        return response()->json([
            'ok'      => true,
            'basis'   => 'audience',
            'note'    => 'Suggested from when your audience actually messages you (last 90 days of inbound Facebook messages). A signal, not a guarantee.',
            'windows' => $windows,
        ]);
    }

    /* ================= 5. AI Image ================= */
    public function image(Request $request): JsonResponse
    {
        if ($r = $this->ensureAi()) {
            return $r;
        }

        $data = $request->validate([
            'prompt' => 'required|string|max:1000',
            'size'   => 'nullable|in:1024x1024,1024x1536,1536x1024',
        ]);

        $prompt = trim((string) $data['prompt']);
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'Describe the image you want to generate.']);
        }

        $key = $this->openAiKey();
        if (! $key) {
            return response()->json(['ok' => false, 'error' => setup_hint('Image generation needs an OpenAI key. Add one in Admin → API keys.', 'Image generation is temporarily unavailable. Please contact support.')]);
        }

        $size = (string) ($data['size'] ?? '1024x1024');

        $b64 = $this->tryOpenAiImage($key, 'gpt-image-1', $prompt, $size, false);
        if ($b64 === null) {
            $dalleSize = $size === '1024x1024' ? '1024x1024'
                : ($size === '1536x1024' ? '1792x1024' : '1024x1792');
            $b64 = $this->tryOpenAiImage($key, 'dall-e-3', $prompt, $dalleSize, true);
        }

        if ($b64 === null) {
            return response()->json(['ok' => false, 'error' => 'The image service refused the request. Check that the OpenAI key has image access and try again.']);
        }

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            return response()->json(['ok' => false, 'error' => 'The generated image was empty. Try a different prompt.']);
        }

        // Store on the public disk so the published post references it exactly
        // like an uploaded photo (public URL Facebook can fetch server-side).
        $path = 'facebook/' . $this->wsId() . '/ai-' . now()->format('Ymd') . '-' . bin2hex(random_bytes(6)) . '.png';

        try {
            Storage::disk('public')->put($path, $bytes, 'public');
        } catch (\Throwable $e) {
            Log::error('[FB-COMPOSER-AI] image store failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Could not save the generated image.']);
        }

        $url = url(Storage::url($path));

        return response()->json(['ok' => true, 'url' => $url]);
    }

    /** One OpenAI Images API attempt → base64 payload or null. */
    private function tryOpenAiImage(string $key, string $model, string $prompt, string $size, bool $withFormat): ?string
    {
        $payload = ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1];
        if ($withFormat) {
            $payload['response_format'] = 'b64_json';
        }

        try {
            $res = Http::withToken($key)->timeout(120)
                ->post('https://api.openai.com/v1/images/generations', $payload);
        } catch (\Throwable $e) {
            Log::warning('[FB-COMPOSER-AI] images threw', ['model' => $model, 'err' => $e->getMessage()]);

            return null;
        }

        if (! $res->ok()) {
            Log::warning('[FB-COMPOSER-AI] images non-200', ['model' => $model, 'status' => $res->status(), 'body' => substr($res->body(), 0, 300)]);

            return null;
        }

        $b64 = (string) ($res->json('data.0.b64_json') ?? '');

        return $b64 !== '' ? $b64 : null;
    }
}
