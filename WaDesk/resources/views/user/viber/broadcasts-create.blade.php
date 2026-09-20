@php
    /** @var \Illuminate\Support\Collection $channels */
    /** @var array $chats */
@endphp

<x-layouts.user :title="__('New Viber broadcast')" nav-key="more" page="user-viber-broadcasts-create">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-center gap-3">
            <a href="{{ route('user.viber.broadcasts.index') }}" class="w-9 h-9 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-600" title="{{ __('Back') }}">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3l-5 5 5 5" /></svg>
            </a>
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('Viber') }} · {{ __('New broadcast') }}</div>
                <h1 class="font-serif text-[26px] sm:text-[32px] leading-none mt-1">{{ __('Compose a') }} <span class="italic" style="color:#7360F2">{{ __('broadcast') }}</span></h1>
            </div>
        </div>

        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('user.viber.broadcasts.store') }}" enctype="multipart/form-data"
            id="viber-broadcasts" data-chats='@json($chats)'
            class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden max-w-2xl">
            @csrf
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Broadcast name') }} <span class="text-accent-coral">*</span></span>
                        <input name="name" type="text" required value="{{ old('name') }}" placeholder="{{ __('e.g. Weekend sale') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                    </label>
                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Channel') }}</span>
                        <select name="viber_channel_id" id="viber-bcast-channel" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-semibold focus:outline-none focus:border-wa-deep">
                            @foreach ($channels as $ch)<option value="{{ $ch->id }}">{{ $ch->bot_name ?: ('Viber ' . $ch->id) }}</option>@endforeach
                        </select>
                    </label>
                </div>

                @if (($templates ?? collect())->count())
                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Template (optional)') }}</span>
                        <select name="template_id" id="viber-bcast-template" data-templates='@json($templates)' class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('— none (write your own) —') }}</option>
                            @foreach ($templates as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}@if (count($t['buttons'])) · {{ count($t['buttons']) }} {{ __('button(s)') }}@endif</option>@endforeach
                        </select>
                    </label>
                @endif

                <label class="block">
                    <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Message') }}</span>
                    <textarea name="body" id="viber-bcast-body" rows="4" maxlength="7000" placeholder="{{ __('Your announcement…') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[13px] resize-y focus:outline-none focus:border-wa-deep">{{ old('body') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Attachment (optional)') }}</span>
                    <input name="media" type="file" accept="image/*,video/mp4" class="mt-1 w-full text-[12px] file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-paper-100 file:text-ink-700 file:text-[12px] file:font-semibold">
                </label>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Recipients') }} <span class="text-accent-coral">*</span></span>
                        <button type="button" id="viber-bcast-all" class="text-[11px] text-wa-deep font-semibold hover:underline">{{ __('Select all') }}</button>
                    </div>
                    <div id="viber-bcast-recipients" class="max-h-72 overflow-y-auto border border-paper-200 rounded-xl divide-y divide-paper-100"></div>
                    <div class="text-[10.5px] text-ink-400 mt-1"><span id="viber-bcast-count">0</span> {{ __('selected') }}</div>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="submit" class="px-6 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#7360F2">{{ __('Create broadcast') }}</button>
                </div>
            </div>
        </form>
    </main>
</x-layouts.user>
