@props(['page' => null])

{{-- A compact Messenger phone-frame preview. The slot renders the screen body
     (welcome screen, greeting, menu or ice-breaker chips) so each setup tab can
     show the customer exactly how the feature will look. --}}
<div class="xl:sticky xl:top-6 self-start">
    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-2 flex items-center gap-1.5">
        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 2.5h8v11H4zM6.5 12.5h3"/></svg>{{ __('Preview') }}
    </div>
    <div class="mx-auto w-full max-w-[300px] rounded-[26px] border-[6px] border-ink-900/90 bg-[#f5f6f7] shadow-card overflow-hidden">
        {{-- Header bar --}}
        <div class="flex items-center gap-2.5 px-3 py-2.5 bg-paper-0 border-b border-paper-200">
            <span class="w-8 h-8 rounded-full grid place-items-center text-white text-[13px] font-semibold shrink-0" style="background:#1877F2">{{ strtoupper(mb_substr(optional($page)->name ?: 'FB', 0, 1)) }}</span>
            <div class="min-w-0">
                <div class="text-[12px] font-semibold text-ink-800 truncate leading-tight">{{ optional($page)->name ?: __('Your Page') }}</div>
                <div class="text-[9.5px] text-wa-green font-medium">{{ __('Active now') }}</div>
            </div>
            <svg viewBox="0 0 16 16" class="w-4 h-4 text-[#0084FF] ml-auto shrink-0" fill="currentColor"><circle cx="4" cy="8" r="1.3"/><circle cx="8" cy="8" r="1.3"/><circle cx="12" cy="8" r="1.3"/></svg>
        </div>
        {{-- Screen body --}}
        <div class="px-3 py-3 h-[300px] flex flex-col">
            {{ $slot }}
        </div>
    </div>
</div>
