@php
    $history = $history ?? [];
    $recent  = $recent ?? collect();
    // Quick chips above the composer (always visible).
    $suggestions = [
        __('Show my open deals'),
        __('Revenue report this month'),
        __('List my tasks'),
        __("What's outstanding?"),
    ];
    // Rich, categorised examples for the empty state — showcase the range so a
    // new user immediately sees the copilot can find, create, send + report.
    $exampleGroups = [
        [
            'label' => __('Look things up'),
            'tint'  => 'bg-[#E0F2FE] text-[#0369A1]',
            'items' => [__('Show my open deals'), __("What's outstanding?"), __('Find the contact Priya')],
        ],
        [
            'label' => __('Create & do'),
            'tint'  => 'bg-wa-mint text-wa-deep',
            'items' => [__('Create a deal for Acme worth 50000 in proposal stage'), __('Create an estimate for Priya: 2 hours consulting at 80'), __('Start a project for the Acme website, due next Friday')],
        ],
        [
            'label' => __('Money & reports'),
            'tint'  => 'bg-[#FEF3C7] text-[#B45309]',
            'items' => [__('Revenue report this month'), __('Record a payment of 5000 for invoice INV-0007')],
        ],
        [
            'label' => __('Send & summarise'),
            'tint'  => 'bg-[#EDE9FE] text-[#6D28D9]',
            'items' => [__('Send Priya the menu photography estimate'), __('Generate a client brief for Acme')],
        ],
    ];
@endphp

<x-layouts.user :title="__('AI CRM')" nav-key="ai-crm" page="user-ai-crm">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7"
          data-ai-crm
          data-message-url="{{ route('user.ai-crm.message') }}"
          data-reset-url="{{ route('user.ai-crm.reset') }}"
          data-csrf="{{ csrf_token() }}">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('AI · CRM Copilot') }}</div>
                <h1 class="font-serif text-[26px] leading-tight">{{ __('AI CRM') }} <span class="italic text-wa-deep">{{ __('copilot') }}</span></h1>
                <p class="mt-1.5 text-[13px] text-ink-500 max-w-xl">{{ __('Chat to run your CRM — find and create contacts and deals, move stages, and get sales numbers. Changes ask you to confirm first.') }}</p>
            </div>
            <button type="button" data-ai-reset
                class="self-start inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-paper-200 text-[12px] text-ink-500 hover:border-wa-deep hover:text-wa-deep transition">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 3v4h4"/><path d="M3.5 7a6 6 0 1 1 .3 2.4"/></svg>
                {{ __('New chat') }}
            </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-6">
            {{-- Chat column --}}
            <section class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card flex flex-col min-h-[62vh]">
                <div data-ai-messages class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    @if (empty($history))
                        <div data-ai-empty class="py-4">
                            <div class="text-center mb-5">
                                <div class="w-12 h-12 mx-auto rounded-2xl bg-[#EEF2FF] text-[#4F46E5] grid place-items-center mb-3">
                                    <svg viewBox="0 0 16 16" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="4" width="10" height="8" rx="2"/><path d="M6 13l2-2h3"/><circle cx="6.2" cy="7.5" r="0.9"/><circle cx="9.8" cy="7.5" r="0.9"/><path d="M8 2v2"/></svg>
                                </div>
                                <p class="text-[15px] font-serif text-ink-900">{{ __('Run your CRM by chat') }}</p>
                                <p class="text-[12.5px] text-ink-500 mt-1">{{ __('Tap an example to try it — I find, create, send and report across your CRM. I ask before changing anything.') }}</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-2xl mx-auto">
                                @foreach ($exampleGroups as $group)
                                    <div class="rounded-xl border border-paper-200 p-3">
                                        <div class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $group['tint'] }} mb-2">{{ $group['label'] }}</div>
                                        <div class="space-y-1.5">
                                            @foreach ($group['items'] as $ex)
                                                <button type="button" data-ai-suggestion data-prompt="{{ $ex }}"
                                                    class="w-full text-left px-2.5 py-1.5 rounded-lg text-[12px] text-ink-700 hover:bg-paper-100 hover:text-wa-deep transition leading-snug">“{{ $ex }}”</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        @foreach ($history as $turn)
                            <div class="flex {{ ($turn['role'] ?? '') === 'user' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] rounded-2xl px-4 py-2.5 text-[13.5px] whitespace-pre-line {{ ($turn['role'] ?? '') === 'user' ? 'bg-wa-deep text-white' : 'bg-paper-100 text-ink-800' }}">{{ $turn['text'] ?? '' }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>

                {{-- Suggestions --}}
                <div data-ai-suggestions class="px-4 sm:px-6 pt-1 pb-2 flex flex-wrap gap-2">
                    @foreach ($suggestions as $sug)
                        <button type="button" data-ai-suggestion class="px-3 py-1.5 rounded-full border border-paper-200 text-[12px] text-ink-600 hover:border-wa-deep hover:text-wa-deep transition">{{ $sug }}</button>
                    @endforeach
                </div>

                {{-- Composer --}}
                <form data-ai-form class="border-t border-paper-200 p-3 flex items-end gap-2">
                    <textarea data-ai-input rows="1" maxlength="2000"
                        placeholder="{{ __('Message the CRM copilot…') }}"
                        class="flex-1 resize-none max-h-32 rounded-xl border border-paper-200 px-3.5 py-2.5 text-[13.5px] focus:outline-none focus:border-wa-deep"></textarea>
                    <button type="submit" data-ai-send
                        class="shrink-0 h-[42px] px-4 rounded-xl bg-wa-deep text-white text-[13px] font-semibold hover:opacity-90 disabled:opacity-50 transition inline-flex items-center gap-2">
                        <span data-ai-send-label>{{ __('Send') }}</span>
                        <svg viewBox="0 0 16 16" class="w-4 h-4" fill="currentColor"><path d="M1.5 8 14 2l-3.2 12-3-4.3L1.5 8Z"/></svg>
                    </button>
                </form>
            </section>

            <div class="space-y-6 h-max">
            {{-- WhatsApp staff channel --}}
            <aside class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4"
                   data-wa-settings data-url="{{ route('user.ai-crm.settings') }}" data-csrf="{{ csrf_token() }}">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-3">{{ __('WhatsApp channel') }}</div>
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[12px] text-ink-600 leading-snug">
                        {{ __('Let managers & admins run the CRM by texting this workspace\'s WhatsApp number from their own registered mobile.') }}
                    </p>
                    @if ($canToggleWa ?? false)
                        <button type="button" role="switch" data-wa-toggle aria-checked="{{ ($waEnabled ?? false) ? 'true' : 'false' }}"
                            class="shrink-0 mt-0.5 w-11 h-6 rounded-full transition relative {{ ($waEnabled ?? false) ? 'bg-wa-deep' : 'bg-paper-300' }}">
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform {{ ($waEnabled ?? false) ? 'translate-x-5' : '' }}"></span>
                        </button>
                    @else
                        <span class="shrink-0 text-[11px] font-mono {{ ($waEnabled ?? false) ? 'text-wa-deep' : 'text-ink-400' }}">{{ ($waEnabled ?? false) ? __('ON') : __('OFF') }}</span>
                    @endif
                </div>
                <p data-wa-status class="mt-2 text-[11px] text-ink-400">
                    {{ ($waEnabled ?? false) ? __('Enabled — only managers/admins on their own number can command.') : __('Disabled.') }}
                </p>
            </aside>

            {{-- Recent AI actions (audit) --}}
            <aside class="bg-paper-0 border border-paper-200 rounded-[16px] shadow-card p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-3">{{ __('Recent AI actions') }}</div>
                <div data-ai-recent class="space-y-3">
                    @forelse ($recent as $a)
                        <div class="text-[12px]">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-wa-deep"></span>
                                <span class="font-mono text-ink-500">{{ $a->tool }}</span>
                            </div>
                            <p class="text-ink-600 mt-0.5 leading-snug">{{ $a->result_summary }}</p>
                        </div>
                    @empty
                        <p class="text-[12px] text-ink-400">{{ __('No actions yet.') }}</p>
                    @endforelse
                </div>
            </aside>
            </div>
        </div>
    </main>
</x-layouts.user>
