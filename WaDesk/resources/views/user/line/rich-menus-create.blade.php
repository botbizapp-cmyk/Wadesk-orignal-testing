@php
    /** @var \Illuminate\Support\Collection $channels */
    /** @var array $layouts */
@endphp

<x-layouts.user :title="__('New LINE rich menu')" nav-key="more" page="user-line-rich-menus-create">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-center gap-3">
            <a href="{{ route('user.line.rich-menus.index') }}" class="w-9 h-9 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-600" title="{{ __('Back') }}">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3l-5 5 5 5" /></svg>
            </a>
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('LINE') }} · {{ __('New rich menu') }}</div>
                <h1 class="font-serif text-[26px] sm:text-[32px] leading-none mt-1">{{ __('Build a') }} <span class="italic" style="color:#06C755">{{ __('rich menu') }}</span></h1>
            </div>
        </div>

        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('user.line.rich-menus.store') }}" enctype="multipart/form-data"
            id="line-rich-menu" data-layouts='@json($layouts)'
            class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">
            @csrf

            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-4 border-b border-paper-200 bg-paper-50/40 flex items-center gap-2.5">
                    <span class="w-[23px] h-[23px] rounded-[7px] bg-paper-0 text-wa-deep inline-flex items-center justify-center text-[10px] font-semibold font-mono shrink-0">01</span>
                    <span class="font-serif text-[18px] leading-none flex-1">{{ __('Menu setup') }}</span>
                </div>
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Menu name') }} <span class="text-accent-coral">*</span></span>
                            <input name="name" type="text" required value="{{ old('name') }}" placeholder="{{ __('e.g. Main menu') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        </label>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Channel') }}</span>
                            <select name="line_channel_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-semibold focus:outline-none focus:border-wa-deep">
                                @foreach ($channels as $ch)
                                    <option value="{{ $ch->id }}">{{ $ch->display_name ?: ($ch->basic_id ?: ('LINE ' . $ch->id)) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Chat bar text') }} <span class="text-accent-coral">*</span></span>
                            <input name="chat_bar_text" type="text" required maxlength="14" value="{{ old('chat_bar_text', 'Menu') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <span class="text-[10.5px] text-ink-400">{{ __('The label users tap to open the menu (max 14 chars).') }}</span>
                        </label>
                        <label class="block">
                            <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Layout') }}</span>
                            <select name="layout" id="line-rm-layout" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-semibold focus:outline-none focus:border-wa-deep">
                                @foreach ($layouts as $key => $l)
                                    <option value="{{ $key }}" data-w="{{ $l['w'] }}" data-h="{{ $l['h'] }}">{{ $l['label'] }} — {{ $l['w'] }}×{{ $l['h'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Menu image') }} <span class="text-accent-coral">*</span></span>
                        <input name="image" type="file" accept="image/png,image/jpeg" required class="mt-1 w-full text-[12px] file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[12px] file:font-semibold">
                        <span class="text-[10.5px] text-ink-400" id="line-rm-size-hint">{{ __('PNG or JPEG, max 1MB. Must match the layout size exactly.') }}</span>
                    </label>

                    <div>
                        <div class="text-[11.5px] font-semibold text-ink-700 mb-1.5">{{ __('Tap areas') }}</div>
                        <div id="line-rm-cells" class="space-y-2.5">
                            {{-- populated by JS from the selected layout --}}
                        </div>
                    </div>

                    <label class="flex items-center gap-2.5 pt-1">
                        <input type="checkbox" name="set_default" value="1" class="shrink-0">
                        <span class="text-[12px] text-ink-700">{{ __('Set as the default menu (shows in every chat)') }}</span>
                    </label>

                    <div class="flex justify-end pt-1">
                        <button type="submit" class="px-6 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#06C755">{{ __('Create rich menu') }}</button>
                    </div>
                </div>
            </section>

            {{-- Layout preview --}}
            <aside class="xl:sticky xl:top-6">
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                        <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Layout preview') }}</span>
                        <span class="inline-flex items-center gap-1.5 text-[10.5px] font-mono" style="color:#0B8043"><span class="w-1.5 h-1.5 rounded-full" style="background:#06C755"></span>LINE</span>
                    </div>
                    <div class="p-4">
                        <div id="line-rm-preview" class="w-full rounded-xl border-2 border-dashed border-paper-300 bg-paper-50 grid gap-0.5 overflow-hidden" style="aspect-ratio:2500/1686"></div>
                        <div class="mt-3 rounded-full bg-paper-100 text-center text-[11px] text-ink-600 py-1.5 font-mono" id="line-rm-bar">Menu</div>
                    </div>
                    <div class="px-5 py-3 border-t border-paper-200 text-[11px] text-ink-500">
                        {{ __('Each numbered cell maps to a tap area above.') }}
                    </div>
                </div>
            </aside>
        </form>
    </main>
</x-layouts.user>
