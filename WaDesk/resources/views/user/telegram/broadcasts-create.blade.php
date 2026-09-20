@php
    /** @var \Illuminate\Support\Collection $bots */
    /** @var array $chats */
    $firstBot = $bots->first();
@endphp

<x-layouts.user :title="__('New Telegram broadcast')" nav-key="telegram" page="user-telegram-broadcasts-create">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-center gap-3">
            <a href="{{ route('user.telegram.broadcasts') }}" class="w-9 h-9 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-600" title="{{ __('Back') }}">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3l-5 5 5 5" /></svg>
            </a>
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('Telegram') }} · {{ __('New broadcast') }}</div>
                <h1 class="font-serif text-[26px] sm:text-[32px] leading-none mt-1">{{ __('Compose a') }} <span class="italic text-wa-deep">{{ __('broadcast') }}</span></h1>
            </div>
        </div>

        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('user.telegram.broadcasts.store') }}" enctype="multipart/form-data"
            id="tg-broadcasts" data-chats='@json($chats)'
            class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">
            @csrf

            {{-- ── Composer ── --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-4 border-b border-paper-200 bg-paper-50/40 flex items-center gap-2.5">
                    <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-0 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">01</span>
                    <span class="font-serif text-[18px] leading-none flex-1">{{ __('Broadcast setup') }}</span>
                </div>
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Broadcast name') }} <span class="text-accent-coral">*</span></span>
                            <input name="name" id="tg-bcast-name" type="text" required value="{{ old('name') }}" placeholder="{{ __('e.g. Weekend sale') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        </label>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Bot') }}</span>
                            <select name="telegram_bot_id" id="tg-bcast-bot" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-semibold focus:outline-none focus:border-wa-deep">
                                @foreach ($bots as $bot)
                                    <option value="{{ $bot->id }}">{{ $bot->bot_name ?: ('@' . ltrim((string) $bot->bot_username, '@')) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    @if (($templates ?? collect())->count())
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Template (optional)') }}</span>
                            <select name="template_id" id="tg-bcast-template" data-templates='@json($templates)'
                                class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                                <option value="">{{ __('— none (write your own) —') }}</option>
                                @foreach ($templates as $t)
                                    <option value="{{ $t['id'] }}">{{ $t['name'] }}@if (count($t['buttons'])) · {{ count($t['buttons']) }} {{ __('button(s)') }}@endif</option>
                                @endforeach
                            </select>
                            <span class="text-[10.5px] text-ink-400">{{ __('Prefills the message and sends the template’s buttons.') }}</span>
                        </label>
                    @endif

                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Message') }} <span class="text-accent-coral">*</span></span>
                        <textarea name="body" id="tg-bcast-body" rows="5" maxlength="4096" placeholder="{{ __('Hi {name}! …') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[13px] resize-y focus:outline-none focus:border-wa-deep">{{ old('body') }}</textarea>
                        <span class="text-[10.5px] text-ink-400">{{ __('Use {name} to personalise.') }}</span>
                        <div id="tg-bcast-btn-preview" class="mt-1.5 flex flex-wrap gap-1.5"></div>
                    </label>

                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Attachment (optional)') }}</span>
                        <input name="media" type="file" accept="image/*,video/mp4,video/quicktime,application/pdf,audio/*" class="mt-1 w-full text-[12px] file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[12px] file:font-semibold">
                    </label>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Recipients') }} <span class="text-accent-coral">*</span></span>
                            <button type="button" id="tg-bcast-all" class="text-[11px] text-wa-deep font-semibold hover:underline">{{ __('Select all') }}</button>
                        </div>
                        <div id="tg-bcast-recipients" class="max-h-72 overflow-y-auto border border-paper-200 rounded-xl divide-y divide-paper-100">
                            {{-- populated by JS from data-chats for the selected bot --}}
                        </div>
                        <div class="text-[10.5px] text-ink-400 mt-1"><span id="tg-bcast-count">0</span> {{ __('selected') }}</div>
                    </div>

                    <div class="flex justify-end pt-1">
                        <button type="submit" class="px-6 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#229ED9">{{ __('Create broadcast') }}</button>
                    </div>
                </div>
            </section>

            {{-- ── Live preview ── --}}
            <aside class="xl:sticky xl:top-6">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                        <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Live preview') }}</span>
                        <span class="inline-flex items-center gap-1.5 text-[10.5px] font-mono" style="color:#1B7CB0"><span class="w-1.5 h-1.5 rounded-full" style="background:#229ED9"></span>Telegram</span>
                    </div>
                    {{-- Telegram-style chat backdrop --}}
                    <div class="p-4" style="background:#cfe0ec">
                        <div class="max-w-[280px] ml-auto">
                            <div class="rounded-2xl rounded-tr-md px-3.5 py-2.5 shadow-sm" style="background:#eeffde">
                                <div id="tg-prev-media" class="hidden mb-1.5 text-[11px] font-mono text-ink-600"></div>
                                <div id="tg-prev-body" class="text-[13px] leading-snug text-ink-900 whitespace-pre-wrap break-words text-ink-400">{{ __('Your message preview…') }}</div>
                                <div id="tg-prev-buttons" class="mt-2 flex flex-wrap gap-1.5"></div>
                                <div class="text-right text-[9.5px] text-ink-500 mt-1 font-mono">12:00 ✓✓</div>
                            </div>
                        </div>
                    </div>
                    <div class="px-5 py-3 border-t border-paper-200 text-[11px] text-ink-500">
                        {{ __('This is how your broadcast will look in each recipient’s chat.') }}
                    </div>
                </div>
            </aside>
        </form>
    </main>
</x-layouts.user>
