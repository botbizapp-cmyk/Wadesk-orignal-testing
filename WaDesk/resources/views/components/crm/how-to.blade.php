@props(['title' => null, 'steps' => []])
{{-- Collapsible per-page "How to use" help. Native <details> — no JS. --}}
<details {{ $attributes->merge(['class' => 'group bg-wa-mint/40 border border-wa-green/30 rounded-[12px] overflow-hidden']) }}>
    <summary class="list-none cursor-pointer select-none px-4 py-3 flex items-center gap-2.5 text-[12.5px] font-semibold text-wa-deep hover:bg-wa-mint/60">
        <svg viewBox="0 0 16 16" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="8" cy="8" r="6.5" /><path d="M8 7.5v3M8 5.2v.2" stroke-linecap="round" />
        </svg>
        <span class="flex-1">{{ __('How to use') }}{{ $title ? ' — ' . $title : '' }}</span>
        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 6l4 4 4-4" /></svg>
    </summary>
    <div class="px-4 pb-4 pt-1">
        <ol class="space-y-2">
            @foreach ($steps as $i => $step)
                <li class="flex gap-2.5 text-[12.5px] text-ink-700 leading-snug">
                    <span class="shrink-0 grid place-items-center rounded-full bg-wa-deep text-paper-0 text-[10px] font-mono" style="width:18px;height:18px">{{ $i + 1 }}</span>
                    <span>{!! $step !!}</span>
                </li>
            @endforeach
        </ol>
        {{ $slot }}
    </div>
</details>
