<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiKey;
use Illuminate\Http\Request;

/**
 * Admin /api-keys — global AI provider keys. SnapNest-style UX:
 * every provider pre-seeded, admin types key + saves + toggles
 * Active. No install / destroy step.
 *
 *   GET   /admin/api-keys              → list providers
 *   PATCH /admin/api-keys/{id}         → save key + default_model + extra_config
 *   POST  /admin/api-keys/{id}/toggle  → activate / deactivate
 */
class AdminAiKeyController extends Controller
{
    /**
     * Default model dropdowns per provider.
     *
     * VERIFIED 2026-05-23 against each provider's public model catalog:
     *   OpenAI:    developers.openai.com/api/docs/models
     *   Anthropic: docs.anthropic.com/en/docs/about-claude/models
     *   Gemini:    ai.google.dev/gemini-api/docs/models
     *   Mistral:   docs.mistral.ai/models/overview
     *   ElevenLabs: elevenlabs.io/docs/overview/models
     *
     * Stale entries (deprecated / retired) removed. Latest flagship +
     * cost-tier variants kept. A small number of legacy aliases retained
     * where the provider still accepts them — they're below the dividing
     * "// legacy" comment so admin can spot which to migrate off.
     */
    public const MODELS = [
        'openai' => [
            // GPT-5.x line — current flagships (newest first = default choice)
            'gpt-5.6', 'gpt-5.6-pro', 'gpt-5.6-mini',
            'gpt-5.5', 'gpt-5.5-pro', 'gpt-5.4', 'gpt-5.4-pro',
            'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5-mini', 'gpt-5-nano',
            // GPT-4.1 — still recommended for tooling / function-calling
            'gpt-4.1', 'gpt-4.1-mini',
            // legacy (still callable but receiving fewer updates)
            'gpt-4o', 'gpt-4o-mini',
        ],
        'anthropic' => [
            // Current flagships (newest first). Opus 5 is the default choice;
            // Fable 5 is Anthropic's most capable model overall (premium pricing).
            'claude-opus-5', 'claude-fable-5', 'claude-sonnet-5', 'claude-haiku-4-5',
            // Opus 4.x family + previous-gen Sonnet — still fully supported.
            'claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6',
        ],
        'gemini' => [
            // Gemini 3.x — current
            'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-3.1-flash-lite',
            // Gemini 2.5 — still maintained
            'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite',
        ],
        'mistral' => [
            // Aliases — resolve to current generation automatically
            'mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest',
            'codestral-latest',
            // Specific dated builds
            'ministral-3-14b-25-12', 'ministral-3-8b-25-12',
            'devstral-2-25-12', 'magistral-medium-1-2-25-09',
        ],
        'elevenlabs' => [
            // Eleven v3 = current flagship (70+ languages)
            'eleven_v3',
            // Still active
            'eleven_multilingual_v2', 'eleven_turbo_v2_5', 'eleven_flash_v2_5',
        ],
        'deepgram' => [
            // Nova = Deepgram's realtime STT family; the AI voice-call bridge
            // streams to nova-2. (Model is informational here — the call bridge
            // reads only the key; it pins nova-2 in its own request.)
            'nova-3', 'nova-2',
        ],
        // OpenRouter — ONE key, 400+ models across every major lab, all through
        // the OpenAI-compatible /chat/completions API. This is a curated top-20;
        // operators can paste ANY id from openrouter.ai/models (the model field
        // accepts a stored value that isn't in this list). IDs follow the
        // provider/model convention — verify exact strings at openrouter.ai/models.
        'openrouter' => [
            // Anthropic (Claude)
            'anthropic/claude-opus-5', 'anthropic/claude-sonnet-5', 'anthropic/claude-haiku-4.5',
            // OpenAI (GPT)
            'openai/gpt-5.6', 'openai/gpt-5.6-mini', 'openai/gpt-5.5', 'openai/gpt-4.1', 'openai/gpt-4o-mini',
            // Google (Gemini)
            'google/gemini-3.5-flash', 'google/gemini-2.5-pro', 'google/gemini-2.5-flash',
            // Meta (Llama)
            'meta-llama/llama-4-maverick', 'meta-llama/llama-3.3-70b-instruct',
            // DeepSeek
            'deepseek/deepseek-chat', 'deepseek/deepseek-r1',
            // Others — Qwen, Mistral, xAI, Z.ai
            'qwen/qwen-2.5-72b-instruct', 'mistralai/mistral-large', 'mistralai/mistral-small',
            'x-ai/grok-4', 'z-ai/glm-4.6',
        ],
        // ── Native AI brands (each = its own API + key). All below are
        //    OpenAI-compatible (/chat/completions), routed via AiAgentService::OAI_BASE.
        'deepseek'   => ['deepseek-chat', 'deepseek-reasoner'],
        'xai'        => ['grok-4', 'grok-4-fast', 'grok-3'],
        'perplexity' => ['sonar-pro', 'sonar', 'sonar-reasoning-pro'],
        'groq'       => ['llama-3.3-70b-versatile', 'llama-4-maverick-17b-128e-instruct', 'moonshotai/kimi-k2-instruct'],
        'together'   => ['meta-llama/Llama-3.3-70B-Instruct-Turbo', 'deepseek-ai/DeepSeek-V3', 'Qwen/Qwen2.5-72B-Instruct-Turbo'],
        'fireworks'  => ['accounts/fireworks/models/llama-v3p3-70b-instruct', 'accounts/fireworks/models/deepseek-v3'],
        'qwen'       => ['qwen-max', 'qwen-plus', 'qwen-turbo'],
        'moonshot'   => ['kimi-k2-0905-preview', 'moonshot-v1-128k', 'moonshot-v1-32k'],
        'zai'        => ['glm-4.6', 'glm-4.5', 'glm-4.5-air'],
        'cohere'     => ['command-a-03-2025', 'command-r-plus', 'command-r'],
        'nvidia'     => ['meta/llama-3.3-70b-instruct', 'deepseek-ai/deepseek-r1'],
        'llama'      => ['Llama-4-Maverick-17B-128E-Instruct-FP8', 'Llama-3.3-70B-Instruct'],
        'huggingface'=> ['meta-llama/Llama-3.3-70B-Instruct', 'deepseek-ai/DeepSeek-V3'],
        'baidu'      => ['ernie-4.5-turbo-128k', 'ernie-4.5-8k'],
        'ai21'       => ['jamba-large', 'jamba-mini'],
        'reka'       => ['reka-core', 'reka-flash'],
        'yi'         => ['yi-lightning', 'yi-large'],
    ];

    /**
     * The credential field schema each provider's edit form renders.
     * Mirrors the PaymentGateway driver pattern so the UX is uniform.
     */
    private const FIELD_SCHEMA = [
        'openai' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Find at platform.openai.com/api-keys',
                'required' => true,
            ],
            'organization' => [
                'label' => 'Organization ID',
                'type' => 'text',
                'hint' => 'Optional. e.g. org-xxxxxxxxxxxx',
            ],
            'max_tokens' => [
                'label' => 'Max tokens per request',
                'type' => 'text',
                'placeholder' => 'e.g. 4096',
                'hint' => 'Optional ceiling — a single AI request can never burn more than this many output tokens. Leave blank for the model default.',
            ],
        ],
        'anthropic' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Find at console.anthropic.com/settings/keys',
                'required' => true,
            ],
            'max_tokens' => [
                'label' => 'Max tokens per request',
                'type' => 'text',
                'placeholder' => 'e.g. 4096',
                'hint' => 'Optional ceiling — a single AI request can never burn more than this many output tokens. Leave blank for the model default.',
            ],
        ],
        'gemini' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Generate at aistudio.google.com/app/apikey',
                'required' => true,
            ],
            'project_id' => [
                'label' => 'Project ID',
                'type' => 'text',
                'hint' => 'Optional. For Vertex AI billing.',
            ],
            'max_tokens' => [
                'label' => 'Max tokens per request',
                'type' => 'text',
                'placeholder' => 'e.g. 4096',
                'hint' => 'Optional ceiling — a single AI request can never burn more than this many output tokens. Leave blank for the model default.',
            ],
        ],
        'mistral' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Generate at console.mistral.ai/api-keys',
                'required' => true,
            ],
            'max_tokens' => [
                'label' => 'Max tokens per request',
                'type' => 'text',
                'placeholder' => 'e.g. 4096',
                'hint' => 'Optional ceiling — a single AI request can never burn more than this many output tokens. Leave blank for the model default.',
            ],
        ],
        'openrouter' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Create at openrouter.ai/keys — one key unlocks all 20 models (Claude, GPT, Gemini, Llama, DeepSeek, Mistral, Grok…).',
                'required' => true,
            ],
            'max_tokens' => [
                'label' => 'Max tokens per request',
                'type' => 'text',
                'placeholder' => 'e.g. 4096',
                'hint' => 'Optional ceiling — a single AI request can never burn more than this many output tokens. Leave blank for the model default.',
            ],
        ],
        // ── Native AI brands — each an OpenAI-compatible endpoint with its own key.
        'deepseek'   => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Create at platform.deepseek.com/api_keys', 'required' => true]],
        'xai'        => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Create at console.x.ai (xAI / Grok)', 'required' => true]],
        'perplexity' => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Create at perplexity.ai/settings/api', 'required' => true]],
        'groq'       => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Create at console.groq.com/keys (fastest inference)', 'required' => true]],
        'qwen'       => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Alibaba DashScope — create at dashscope.console.aliyun.com', 'required' => true]],
        'moonshot'   => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Moonshot / Kimi — platform.moonshot.ai/console/api-keys', 'required' => true]],
        'zai'        => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Z.ai / GLM — z.ai/manage-apikey/apikey-list', 'required' => true]],
        'cohere'     => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Create at dashboard.cohere.com/api-keys', 'required' => true]],
        'nvidia'     => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'NVIDIA NIM — build.nvidia.com', 'required' => true]],
        'llama'      => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Meta Llama API — llama.developer.meta.com', 'required' => true]],
        'huggingface'=> ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Hugging Face — huggingface.co/settings/tokens', 'required' => true]],
        'baidu'      => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Baidu Ernie (Qianfan) — console.bce.baidu.com/qianfan', 'required' => true]],
        'ai21'       => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'AI21 (Jamba) — studio.ai21.com/account/api-key', 'required' => true]],
        'reka'       => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => 'Reka — platform.reka.ai', 'required' => true]],
        'yi'         => ['api_key' => ['label' => 'API key', 'type' => 'password', 'hint' => '01.AI (Yi) — platform.01.ai', 'required' => true]],
        'elevenlabs' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Find at elevenlabs.io/app/settings/api-keys',
                'required' => true,
            ],
            'voice_id' => [
                'label' => 'Default voice ID',
                'type' => 'text',
                'hint' => 'Optional. Voice used when caller hasn\'t picked one.',
            ],
        ],
        'deepgram' => [
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'hint' => 'Create at console.deepgram.com → API Keys. Powers AI voice-call speech-to-text (so the assistant can hear the caller).',
                'required' => true,
            ],
        ],
    ];

    public function index()
    {
        // Ensure every supported provider has a row so the page lists ALL of them
        // (not only the ones that already have a saved key). A provider newly
        // added to FIELD_SCHEMA appears immediately in an empty "add key" state,
        // inactive until a key is saved. Existing rows keep their state.
        $names = [
            'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Google Gemini',
            'mistral' => 'Mistral', 'deepseek' => 'DeepSeek', 'xai' => 'xAI (Grok)',
            'perplexity' => 'Perplexity', 'groq' => 'Groq', 'qwen' => 'Alibaba Qwen',
            'moonshot' => 'Moonshot (Kimi)', 'zai' => 'Z.ai (GLM)', 'cohere' => 'Cohere',
            'nvidia' => 'NVIDIA', 'llama' => 'Meta Llama', 'huggingface' => 'Hugging Face',
            'baidu' => 'Baidu (Ernie)', 'ai21' => 'AI21 (Jamba)', 'reka' => 'Reka',
            'yi' => '01.AI (Yi)', 'openrouter' => 'OpenRouter',
            'elevenlabs' => 'ElevenLabs', 'deepgram' => 'Deepgram',
        ];
        foreach (array_keys(self::FIELD_SCHEMA) as $i => $slug) {
            AdminAiKey::firstOrCreate(
                ['provider' => $slug],
                ['name' => $names[$slug] ?? ucfirst($slug), 'is_active' => false, 'sort_order' => $i],
            );
        }

        $providers = AdminAiKey::orderBy('sort_order')->get()->map(function (AdminAiKey $k) {
            $k->fields_schema        = self::FIELD_SCHEMA[$k->provider] ?? [];
            $k->extra_config_decoded = $k->extra_config_array;
            $k->model_choices        = self::MODELS[$k->provider] ?? [];
            return $k;
        });

        $stats = [
            'total'    => $providers->count(),
            'active'   => $providers->where('is_active', true)->count(),
            'ready'    => $providers->filter(fn ($p) => $p->is_active && !empty($p->api_key))->count(),
            'no_key'   => $providers->filter(fn ($p) => empty($p->api_key))->count(),
        ];

        return view('admin.api-keys.index', compact('providers', 'stats'));
    }

    public function update(Request $request, int $id)
    {
        $row    = AdminAiKey::findOrFail($id);
        $fields = self::FIELD_SCHEMA[$row->provider] ?? [];

        $data = $request->validate([
            'default_model' => ['nullable', 'string', 'max:80'],
            'api_key'       => ['nullable', 'string', 'max:1024'],
            'sort_order'    => ['nullable', 'integer', 'min:0'],
            'extra'         => ['nullable', 'array'],
            'extra.*'       => ['nullable', 'string', 'max:500'],
        ]);

        // API key — only overwrite if a non-empty value was submitted.
        // Lets the password placeholder ("leave blank to keep") work.
        if (!empty($data['api_key'])) {
            $row->api_key = $data['api_key'];
        }

        $row->default_model = $data['default_model'] ?? $row->default_model;
        $row->sort_order    = $data['sort_order'] ?? $row->sort_order;

        // Merge extra_config — only overwrite a key when the form
        // submitted a non-empty value (so blank fields don't wipe).
        $existing = $row->extra_config_array;
        $incoming = $data['extra'] ?? [];
        foreach ($fields as $key => $spec) {
            if ($key === 'api_key') continue;
            if (array_key_exists($key, $incoming) && $incoming[$key] !== '') {
                $existing[$key] = $incoming[$key];
            }
        }
        $row->extra_config = json_encode($existing, JSON_UNESCAPED_UNICODE);

        // Auto-activate the moment a usable key is on the row. Admins kept
        // hitting Save expecting the provider to turn ON, but it stayed DISABLED
        // (Activate was a separate click), so every AI picker showed "no
        // providers enabled". Saving with a key now enables it. (Toggle still
        // lets them deactivate.)
        if (!empty($row->api_key)) {
            $row->is_active = true;
        }
        $row->save();

        return back()->with('success', $row->name . ($row->is_active ? ' saved + activated.' : ' settings saved.'));
    }

    public function toggle(int $id)
    {
        $row = AdminAiKey::findOrFail($id);

        if (!$row->is_active && empty($row->api_key)) {
            return back()->with('error', 'Add an API key before activating.');
        }
        $row->update(['is_active' => !$row->is_active]);

        return back()->with('success', $row->is_active ? 'Activated.' : 'Deactivated.');
    }

    public static function fieldSchemaFor(string $provider): array
    {
        return self::FIELD_SCHEMA[$provider] ?? [];
    }
}
