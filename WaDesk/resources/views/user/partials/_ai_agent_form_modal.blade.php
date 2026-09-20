{{--
    Create / edit AI agent — THE canonical form.

    Extracted from the team inbox so /devices can open the exact same modal when
    assigning an agent to a number. A trimmed second copy would drift: this form
    carries the knowledge base, canned answers, human-handoff rules and voice
    settings, and an agent created without them behaves differently from one made
    in the inbox.

    Requires the page to load the agent-form JS (see resources/js/charts).
--}}
    <div id="ai-agent-form-modal" class="hidden fixed inset-0 z-[60] grid place-items-center p-4">
        <div class="absolute inset-0 bg-ink-900/50" data-close-agent-form></div>
        <div class="relative bg-paper-0 rounded-2xl w-full max-w-lg p-5 shadow-2xl overflow-y-auto max-h-[90vh]">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('AI Agent') }}
                    </div>
                    <h3 class="font-serif text-[20px] leading-tight" id="agent-form-title">
                        {{ __('Create agent') }}</h3>
                </div>
                <button data-close-agent-form
                    class="w-8 h-8 rounded-full hover:bg-paper-50 grid place-items-center text-ink-700">×</button>
            </div>
            <details class="mb-4 border border-paper-200 rounded-xl bg-paper-50 overflow-hidden">
                <summary
                    class="px-3 py-2 text-[11.5px] font-mono uppercase tracking-[0.14em] text-ink-700 cursor-pointer hover:bg-paper-100 select-none flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor"
                        stroke-width="1.8">
                        <path d="M6 4l4 4-4 4" />
                    </svg>
                    How the AI agent will work
                </summary>
                <pre
                    class="px-4 py-3 text-[11.5px] leading-relaxed text-ink-700 font-mono whitespace-pre overflow-x-auto bg-paper-0 border-t border-paper-200">
You give the agent a name, picture, and a "personality" prompt
 │
 ▼
Operator opens a chat → clicks "AI Agent" → picks this agent
 │
 ▼
Every inbound message in that chat → this agent replies
 │
 ├── Reads the last 20 messages for context
 ├── Sends to your chosen model (ChatGPT / Gemini / etc.)
 ├── Sends the reply back as WhatsApp message
 └── Rates its own reply 1-10 (shown as ★ badge for managers)
 │
 ▼
Want to take over? Just type a message yourself → agent stops automatically
Tips:
 • Tone setting changes how casual or formal the bot sounds
 • Lower temperature = same answers each time, higher = more creative
 • Be specific in the prompt — "Reply briefly. Never quote prices."</pre>
            </details>
            <form id="ai-agent-form" class="space-y-4">
                <input type="hidden" id="agent-form-id" value="">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Agent name') }}</label>
                        <input type="text" name="name" required maxlength="191"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep"
                            placeholder="{{ __('e.g. Sales Bot, Support AI') }}" />
                    </div>
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Provider') }}</label>
                        <select name="provider" id="agent-provider"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                            <option value="openai">{{ __('OpenAI (GPT)') }}</option>
                            <option value="anthropic">{{ __('Anthropic (Claude)') }}</option>
                            <option value="gemini">{{ __('Google (Gemini)') }}</option>
                            <option value="mistral">{{ __('Mistral') }}</option>
                            <option value="deepseek">{{ __('DeepSeek') }}</option>
                            <option value="xai">{{ __('xAI (Grok)') }}</option>
                            <option value="perplexity">{{ __('Perplexity') }}</option>
                            <option value="groq">{{ __('Groq (fastest)') }}</option>
                            <option value="qwen">{{ __('Alibaba Qwen') }}</option>
                            <option value="moonshot">{{ __('Moonshot (Kimi)') }}</option>
                            <option value="zai">{{ __('Z.ai (GLM)') }}</option>
                            <option value="cohere">{{ __('Cohere') }}</option>
                            <option value="nvidia">{{ __('NVIDIA') }}</option>
                            <option value="llama">{{ __('Meta Llama') }}</option>
                            <option value="huggingface">{{ __('Hugging Face') }}</option>
                            <option value="baidu">{{ __('Baidu (Ernie)') }}</option>
                            <option value="ai21">{{ __('AI21 (Jamba)') }}</option>
                            <option value="reka">{{ __('Reka') }}</option>
                            <option value="yi">{{ __('01.AI (Yi)') }}</option>
                            <option value="openrouter">{{ __('OpenRouter (multi)') }}</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Model') }}</label>
                        <select name="model" id="agent-model"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                            @foreach (\App\Http\Controllers\Admin\AdminAiKeyController::MODELS as $providerKey => $modelList)
                                <optgroup label="{{ ucfirst($providerKey) }}">
                                    @foreach ($modelList as $m)
                                        <option value="{{ $m }}">{{ $m }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    {{-- Knowledge base — link this inbox agent to a trained
                         /ai-training assistant so its answers use that knowledge
                         (URLs / text / Q&A). Optional; blank = prompt only. --}}
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Knowledge base') }}
                            <span class="text-ink-400 font-normal">{{ __('(optional)') }}</span></label>
                        @php
                            $__kbAssistants = \App\Models\AiChatAssistant::query()
                                ->where('workspace_id', auth()->user()->current_workspace_id ?? 0)
                                ->orderBy('name')->get(['id', 'name']);
                        @endphp
                        <select name="knowledge_assistant_id" id="agent-knowledge-assistant"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('None — use the prompt only') }}</option>
                            @foreach ($__kbAssistants as $__a)
                                <option value="{{ $__a->id }}">{{ $__a->name }}</option>
                            @endforeach
                        </select>
                        <div class="text-[10.5px] text-ink-500 mt-1">
                            {{ __('Folds a trained AI-Training agent’s knowledge into this agent’s answers.') }}</div>
                    </div>
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Tone') }}</label>
                        <select name="tone"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep">
                            <option value="professional">{{ __('Professional') }}</option>
                            <option value="friendly">{{ __('Friendly') }}</option>
                            <option value="concise">{{ __('Concise') }}</option>
                            <option value="empathetic">{{ __('Empathetic') }}</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Avatar color') }}</label>
                        <div class="flex items-center gap-2 flex-wrap" id="agent-color-picker">
                            <input type="hidden" name="avatar_color" value="#6366f1" />
                            <button type="button" data-color="#6366f1"
                                class="w-7 h-7 rounded-full ring-2 ring-offset-2 ring-[#6366f1]"
                                style="background:#6366f1"></button>
                            <button type="button" data-color="#075E54" class="w-7 h-7 rounded-full"
                                style="background:#075E54"></button>
                            <button type="button" data-color="#0ea5e9" class="w-7 h-7 rounded-full"
                                style="background:#0ea5e9"></button>
                            <button type="button" data-color="#f59e0b" class="w-7 h-7 rounded-full"
                                style="background:#f59e0b"></button>
                            <button type="button" data-color="#ef4444" class="w-7 h-7 rounded-full"
                                style="background:#ef4444"></button>
                            <button type="button" data-color="#8b5cf6" class="w-7 h-7 rounded-full"
                                style="background:#8b5cf6"></button>
                        </div>
                    </div>
                </div>
                <div>
                    <label
                        class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('System prompt') }}</label>
                    <textarea name="system_prompt" rows="4" maxlength="4000"
                        class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep resize-y"
                        placeholder="{{ __("You are a helpful WhatsApp assistant for Acme Corp. You help customers with orders, returns, and product questions. Always be polite and offer to escalate to a human agent when you're unsure.") }}"></textarea>
                    <div class="text-[10.5px] text-ink-500 mt-1">
                        {{ __("Describe the agent's role, knowledge, and behaviour. Leave blank for a generic assistant.") }}
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Max tokens') }}</label>
                        <input type="number" name="max_tokens" min="64" max="4096" value="512"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep" />
                    </div>
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Temperature (0–10)') }}</label>
                        <input type="number" name="temperature" min="0" max="10" value="7"
                            class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[13px] focus:outline-none focus:border-wa-deep" />
                        <div class="text-[10px] text-ink-500 mt-1">0=focused 10=creative</div>
                    </div>
                    <div>
                        <label
                            class="text-[11.5px] font-semibold text-ink-700 mb-1.5 block">{{ __('Auto-respond') }}</label>
                        <label class="flex items-center gap-2 mt-2 cursor-pointer">
                            <input type="checkbox" name="auto_respond" checked
                                class="w-4 h-4 rounded border-paper-200 accent-wa-deep" />
                            <span class="text-[12px] text-ink-700">{{ __('Enabled') }}</span>
                        </label>
                    </div>
                </div>
                {{-- Saved replies as guidance — when on, the LLM is fed the
 workspace's top 15 saved replies (by used_count) as a "canned
 responses you can use" block. Keeps the AI on-brand without
 rewriting the system prompt for every FAQ. --}}
                <div class="border border-paper-200 rounded-xl p-3 bg-wa-mint/10">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="use_saved_replies"
                            class="w-4 h-4 rounded border-paper-200 accent-wa-deep" />
                        <span
                            class="text-[12px] text-ink-700 font-semibold">{{ __('Use saved replies as canned answers') }}</span>
                    </label>
                    <p class="text-[10.5px] text-ink-500 mt-1.5 ml-6">
                        The AI sees your workspace's <a href="#" data-open-quick-replies
                            class="text-wa-deep font-semibold hover:underline">{{ __('Quick Replies') }}</a> and
                        uses them verbatim (or paraphrased) when a customer's question matches.
                        Top 15 by usage are sent on every reply.
                    </p>
                </div>
                {{-- Human handoff — stop the AI from looping forever in a support
 chat. When any trigger fires, the agent unassigns itself,
 tags the convo "Needs human", bumps priority to high, and
 pings every workspace member's notification bell. --}}
                <div class="border border-paper-200 rounded-xl p-3 bg-accent-amber/5">
                    <div class="flex items-center gap-2 mb-2">
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Human handoff') }}</span>
                        <label class="ml-auto flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="handoff_enabled" checked
                                class="w-4 h-4 rounded border-paper-200 accent-wa-deep" />
                            <span class="text-[11.5px] text-ink-700 font-semibold">{{ __('Enabled') }}</span>
                        </label>
                    </div>
                    <p class="text-[10.5px] text-ink-500 mb-3">
                        {{ __('Stops the AI from running indefinitely. When triggered, the conversation flips to "Needs human" and every team member gets a notification.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Max replies per chat') }}</label>
                            <input type="number" name="max_replies_per_conversation" min="0"
                                max="200" value="10"
                                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            <div class="text-[10px] text-ink-500 mt-1">0 = no limit</div>
                        </div>
                        <div>
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Low-score threshold') }}</label>
                            <div class="flex gap-2">
                                <input type="number" name="handoff_low_score_threshold" min="0"
                                    max="10" value="0"
                                    class="w-16 px-2 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                                <span class="text-[10.5px] text-ink-500 self-center">≤ score, for</span>
                                <input type="number" name="handoff_low_score_window" min="1"
                                    max="10" value="3"
                                    class="w-14 px-2 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                                <span class="text-[10.5px] text-ink-500 self-center">{{ __('replies') }}</span>
                            </div>
                            <div class="text-[10px] text-ink-500 mt-1">0 = off. 1–10 = hand off if last N self-scores
                                ≤ this</div>
                        </div>
                        <div class="col-span-2">
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Customer keywords') }}
                                <span class="font-normal text-ink-400">(comma-separated,
                                    case-insensitive)</span></label>
                            <input type="text" name="handoff_keywords_csv"
                                placeholder="{{ __('human, real person, speak to agent, manager') }}"
                                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            <div class="text-[10px] text-ink-500 mt-1">
                                {{ __("If the customer's message contains any of these phrases, hand off immediately. Leave blank for sensible defaults.") }}
                            </div>
                        </div>
                    </div>
                </div>
                {{-- Voice-AI channels.
 Voice-note replies work on both Baileys and WABA — when
 this agent is assigned to a conversation and the customer
 sends a voice message, we transcribe it, run the same
 prompt the text path uses, synthesise a reply, and send
 it back as a WhatsApp PTT voice note.
 Voice-call answering is WABA-only and unlocks once the
 workspace's WABA number has calling enabled. --}}
                <div class="border border-paper-200 rounded-xl p-3 bg-wa-deep/5">
                    <div class="flex items-center gap-2 mb-2">
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Voice AI') }}</span>
                    </div>
                    <p class="text-[10.5px] text-ink-500 mb-3">
                        {{ __('When the customer sends a voice note, the agent transcribes it, generates a reply with the same system prompt, and sends a voice note back. Works on both Unofficial API and WABA.') }}
                    </p>
                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                        <input type="checkbox" name="voice_note_enabled"
                            class="w-4 h-4 rounded border-paper-200 accent-wa-deep" />
                        <span class="text-[12px] text-ink-700 font-semibold">{{ __('Reply to voice notes') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer mb-3"
                        title="{{ __('Answers inbound WABA calls with the AI voice agent. Requires WABA calling enabled on a workspace number.') }}">
                        <input type="checkbox" name="voice_call_enabled"
                            class="w-4 h-4 rounded border-paper-200 accent-wa-deep" />
                        <span class="text-[12px] text-ink-700">{{ __('Answer voice calls') }} <span
                                class="text-[10px] text-ink-500 font-mono">{{ __('WABA') }}</span></span>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Voice provider') }}</label>
                            <select name="voice_provider"
                                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                <option value="openai">{{ __('OpenAI TTS') }}</option>
                                <option value="elevenlabs">{{ __('ElevenLabs') }}</option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Voice id / name') }}</label>
                            <input type="text" name="voice_id" maxlength="96"
                                placeholder="{{ __('alloy · nova · or ElevenLabs voice id') }}"
                                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            <div class="text-[10px] text-ink-500 mt-1">
                                {{ __('OpenAI: alloy, echo, fable, onyx, nova, shimmer, ash, sage, coral. ElevenLabs: paste the voice id from your library.') }}
                            </div>
                        </div>
                        <div>
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Voice language') }}</label>
                            <select name="voice_language"
                                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                <option value="en">{{ __('English') }}</option>
                                <option value="hi">{{ __('Hindi') }}</option>
                                <option value="es">{{ __('Spanish') }}</option>
                                <option value="fr">{{ __('French') }}</option>
                                <option value="de">{{ __('German') }}</option>
                                <option value="pt">{{ __('Portuguese') }}</option>
                                <option value="it">{{ __('Italian') }}</option>
                                <option value="ar">{{ __('Arabic') }}</option>
                                <option value="ja">{{ __('Japanese') }}</option>
                                <option value="ko">{{ __('Korean') }}</option>
                                <option value="zh">{{ __('Chinese') }}</option>
                                <option value="tr">{{ __('Turkish') }}</option>
                                <option value="id">{{ __('Indonesian') }}</option>
                                <option value="nl">{{ __('Dutch') }}</option>
                                <option value="ru">{{ __('Russian') }}</option>
                            </select>
                        </div>
                        <div>
                            <label
                                class="text-[11.5px] font-semibold text-ink-700 mb-1 block">{{ __('Daily cap') }}</label>
                            <input type="number" name="max_voice_notes_per_day" min="0" max="10000"
                                value="200"
                                class="w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep" />
                            <div class="text-[10px] text-ink-500 mt-1">
                                {{ __('Voice replies per day safety cap. 0 = block all.') }}</div>
                        </div>
                    </div>
                </div>
                {{-- Multi-device scoping — only rendered into when the workspace
 has 2+ paired devices. The JS in openAgentForm() populates
 this slot with one checkbox per paired device. With no
 boxes ticked the agent handles every device (preserves
 single-device behavior). --}}
                <div id="agent-device-scope-wrap"
                    class="hidden border border-paper-200 rounded-xl p-3 bg-paper-50/60">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                        {{ __('Limit to devices') }}</div>
                    <p class="text-[11.5px] text-ink-500 mb-2 leading-snug">
                        {{ __('Tick one or more paired numbers to restrict this agent to conversations on those devices only. Leave all unticked to handle every device.') }}
                    </p>
                    <div id="agent-device-scope-list" class="grid grid-cols-1 gap-1.5"></div>
                </div>
                {{-- Test panel --}}
                <div class="border border-paper-200 rounded-xl p-3 bg-paper-50/60">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2">
                        {{ __('Test this agent') }}</div>
                    <div class="flex gap-2">
                        <input type="text" id="agent-test-input"
                            class="flex-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] focus:outline-none focus:border-wa-deep"
                            placeholder="{{ __('Type a test message…') }}" />
                        <button type="button" id="agent-test-btn"
                            class="px-3 py-2 rounded-full bg-paper-200 hover:bg-paper-300 text-[12px] font-semibold shrink-0">{{ __('Send') }}</button>
                    </div>
                    <div id="agent-test-result"
                        class="hidden mt-2 p-2.5 bg-wa-bubble rounded-lg text-[12.5px] text-ink-900 whitespace-pre-wrap">
                    </div>
                </div>
                <div id="agent-form-error"
                    class="hidden rounded-lg p-3 bg-accent-coral/10 text-accent-coral text-[12px]"></div>
                <div class="flex items-center gap-2 pt-1">
                    <button type="button" data-close-agent-form
                        class="px-3 py-1.5 rounded-full border border-paper-200 hover:bg-paper-50 text-[12px]">{{ __('Cancel') }}</button>
                    <button type="submit" id="agent-form-submit"
                        class="ml-auto px-4 py-1.5 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save agent') }}</button>
                </div>
            </form>
        </div>
    </div>
