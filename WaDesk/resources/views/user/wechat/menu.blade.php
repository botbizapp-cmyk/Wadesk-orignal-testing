@php
    /** @var \Illuminate\Support\Collection $channels */
    /** @var array|null $current */
    $ch = $channel ?? null;
@endphp

<x-layouts.user :title="__('WeChat menu')" nav-key="more" page="user-wechat-menu">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('WeChat') }} · {{ __('Custom menu') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Custom') }} <span class="italic" style="color:#07C160">{{ __('menu') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('The persistent bar at the bottom of every chat. Up to 3 top buttons — each opens a link, fires a keyword, or holds up to 5 sub-items.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.wechat.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Channels') }}</a>
                @if ($channels->count() > 1)
                    <form method="GET">
                        <select name="channel" onchange="this.form.submit()" class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] font-semibold focus:outline-none focus:border-wa-deep">
                            @foreach ($channels as $c)<option value="{{ $c->id }}" @selected($ch && $ch->id === $c->id)>{{ $c->account_name ?: ('WeChat ' . $c->id) }}</option>@endforeach
                        </select>
                    </form>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>
        @endif
        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        @if ($channels->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#07C160">W</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a WeChat channel first.') }}</p>
                <a href="{{ route('user.wechat.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#07C160">{{ __('Connect a channel') }}</a>
            </div>
        @else
            <form method="POST" action="{{ route('user.wechat.menu.save') }}" id="wechat-menu"
                data-current='@json($current ?? [])'
                class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 max-w-3xl space-y-4">
                @csrf
                <input type="hidden" name="wechat_channel_id" value="{{ $ch?->id }}">
                <input type="hidden" name="menu" id="wc-menu-json">

                <div id="wc-menu-tops" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    {{-- 3 top-button editors injected by JS --}}
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <form method="POST" action="{{ route('user.wechat.menu.clear') }}" onsubmit="return confirm('{{ __('Remove the menu from WeChat?') }}')">@csrf
                        <input type="hidden" name="wechat_channel_id" value="{{ $ch?->id }}">
                        <button type="submit" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] text-accent-coral hover:bg-accent-coral/10">{{ __('Clear menu') }}</button>
                    </form>
                    <button type="submit" class="px-6 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#07C160">{{ __('Publish menu') }}</button>
                </div>
                <p class="text-[10.5px] text-ink-400">{{ __('“Keyword” buttons fire an event your keyword rules / flows can answer. Menu changes can take a few minutes and a re-follow to appear in WeChat.') }}</p>
            </form>
        @endif
    </main>
</x-layouts.user>
