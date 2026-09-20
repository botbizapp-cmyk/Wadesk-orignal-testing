@php
    /** @var \Illuminate\Support\Collection $channels */
    /** @var \Illuminate\Support\Collection $menus */
@endphp

<x-layouts.user :title="__('LINE rich menus')" nav-key="more" page="user-line-rich-menus">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('LINE') }} · {{ __('Rich menus') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Rich') }} <span class="italic" style="color:#06C755">{{ __('menus') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('The tappable image panel pinned to the bottom of every chat. Pick a layout, upload an image and assign an action per cell.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.line.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Channels') }}</a>
                @unless ($channels->isEmpty())
                    <a href="{{ route('user.line.rich-menus.create') }}" class="px-4 py-2 rounded-full text-white text-[12px] font-semibold inline-flex items-center gap-2" style="background:#06C755">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10" /></svg>
                        {{ __('New rich menu') }}
                    </a>
                @endunless
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
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#06C755">L</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a LINE channel first.') }}</p>
                <a href="{{ route('user.line.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#06C755">{{ __('Connect a channel') }}</a>
            </div>
        @elseif ($menus->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-12 text-center shadow-card text-ink-400 text-[13px]">
                {{ __('No rich menus yet.') }} <a href="{{ route('user.line.rich-menus.create') }}" class="text-wa-deep font-semibold hover:underline">{{ __('Create your first one') }}</a>.
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($menus as $m)
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden flex flex-col">
                        <div class="aspect-[2500/1000] bg-paper-100 overflow-hidden">
                            @if ($m->image_path)
                                <img src="{{ media_url($m->image_path) }}" alt="{{ $m->name }}" class="w-full h-full object-cover">
                            @endif
                        </div>
                        <div class="p-4 flex-1 flex flex-col">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-semibold text-ink-900 truncate">{{ $m->name }}</div>
                                    <div class="text-[11px] text-ink-500 mt-0.5">{{ $m->channel?->display_name ?: ($m->channel?->basic_id ?: 'LINE') }} · <span class="font-mono">{{ $m->chat_bar_text }}</span></div>
                                </div>
                                @if ($m->is_default)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-mono bg-wa-mint text-wa-deep shrink-0">{{ __('Default') }}</span>
                                @endif
                            </div>
                            <div class="mt-3 pt-3 border-t border-paper-100 flex items-center gap-1.5">
                                @if ($m->is_default)
                                    <form method="POST" action="{{ route('user.line.rich-menus.clear', $m->id) }}">@csrf
                                        <button class="px-3 py-1 rounded-full border border-paper-200 text-[11px] font-medium hover:bg-paper-50">{{ __('Unset default') }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('user.line.rich-menus.default', $m->id) }}">@csrf
                                        <button class="px-3 py-1 rounded-full text-white text-[11px] font-semibold" style="background:#06C755">{{ __('Set as default') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('user.line.rich-menus.destroy', $m->id) }}" class="ml-auto" onsubmit="return confirm('{{ __('Delete this rich menu?') }}')">@csrf @method('DELETE')
                                    <button class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg></button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </main>
</x-layouts.user>
