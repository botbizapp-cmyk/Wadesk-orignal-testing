@php
    /** @var \Illuminate\Support\Collection $channels */
    /** @var array $chats */
@endphp

<x-layouts.user :title="__('New WeChat broadcast')" nav-key="more" page="user-wechat-broadcasts-create">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex items-center gap-3">
            <a href="{{ route('user.wechat.broadcasts.index') }}" class="w-9 h-9 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center text-ink-600" title="{{ __('Back') }}">
                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3l-5 5 5 5" /></svg>
            </a>
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('WeChat') }} · {{ __('New broadcast') }}</div>
                <h1 class="font-serif text-[26px] sm:text-[32px] leading-none mt-1">{{ __('Compose a') }} <span class="italic" style="color:#07C160">{{ __('broadcast') }}</span></h1>
            </div>
        </div>

        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('user.wechat.broadcasts.store') }}" id="wechat-broadcast" data-chats='@json($chats)'
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
                        <select name="wechat_channel_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-semibold focus:outline-none focus:border-wa-deep">
                            @foreach ($channels as $ch)
                                <option value="{{ $ch->id }}">{{ $ch->account_name ?: ('WeChat ' . $ch->id) }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div>
                    <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Audience') }}</span>
                    <div class="mt-1.5 flex flex-wrap gap-2" id="wc-aud">
                        <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-paper-200 text-[12px] cursor-pointer"><input type="radio" name="audience" value="all" checked> {{ __('All followers') }}</label>
                        <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-paper-200 text-[12px] cursor-pointer"><input type="radio" name="audience" value="tag"> {{ __('By tag') }}</label>
                        <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-paper-200 text-[12px] cursor-pointer"><input type="radio" name="audience" value="list"> {{ __('Pick from inbox') }}</label>
                    </div>
                    <input name="tag_id" id="wc-tag" type="text" placeholder="{{ __('Tag ID (number)') }}" class="mt-2 w-full sm:w-52 rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12px] font-mono focus:outline-none focus:border-wa-deep hidden">
                    <div id="wc-recipients" class="mt-2 max-h-56 overflow-y-auto border border-paper-200 rounded-xl divide-y divide-paper-100 hidden"></div>
                    <div id="wc-recip-count" class="text-[10.5px] text-ink-400 mt-1 hidden"><span>0</span> {{ __('selected (min 2)') }}</div>
                </div>

                @if (($templates ?? collect())->count())
                    <label class="block">
                        <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Template (optional)') }}</span>
                        <select id="wc-template" data-templates='@json($templates)' class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('— none (write your own) —') }}</option>
                            @foreach ($templates as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}</option>@endforeach
                        </select>
                    </label>
                @endif

                <label class="block">
                    <span class="text-[11.5px] font-semibold text-ink-700">{{ __('Message') }} <span class="text-accent-coral">*</span></span>
                    <textarea name="body" id="wc-body" rows="5" required maxlength="2000" placeholder="{{ __('Your announcement…') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[13px] resize-y focus:outline-none focus:border-wa-deep">{{ old('body') }}</textarea>
                    <span class="text-[10.5px] text-ink-400">{{ __('Mass-send supports plain text. WeChat limits Service Accounts to about 4 sends per month.') }}</span>
                </label>

                <div class="flex justify-end pt-1">
                    <button type="submit" class="px-6 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#07C160">{{ __('Create broadcast') }}</button>
                </div>
            </div>
        </form>
    </main>
</x-layouts.user>
