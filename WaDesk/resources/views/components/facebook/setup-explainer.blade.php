@props(['what' => '', 'customer' => '', 'how' => ''])

{{-- Plain-English explainer for a Messenger-setup feature: what it is, how the
     customer experiences it, and how the operator sets it up. --}}
<div class="rounded-xl border border-paper-200 bg-paper-50/60 divide-y divide-paper-100 text-[12.5px]">
    <div class="flex gap-3 px-4 py-3">
        <span class="w-6 h-6 rounded-lg grid place-items-center shrink-0 bg-wa-mint text-wa-deep">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2zM8 7v4M8 5h.01"/></svg>
        </span>
        <div class="min-w-0">
            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('What it is') }}</div>
            <p class="text-ink-700 mt-0.5 leading-snug">{{ $what }}</p>
        </div>
    </div>
    <div class="flex gap-3 px-4 py-3">
        <span class="w-6 h-6 rounded-lg grid place-items-center shrink-0 bg-[#1877F2]/10 text-[#1877F2]">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6a2 2 0 1 1 0-0.01M3 13c0-2 1.5-3 3-3s3 1 3 3M11 5.5a2 2 0 1 1 0 4M11.5 13c0-2-1-2.8-1-2.8"/></svg>
        </span>
        <div class="min-w-0">
            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('How your customers use it') }}</div>
            <p class="text-ink-700 mt-0.5 leading-snug">{{ $customer }}</p>
        </div>
    </div>
    <div class="flex gap-3 px-4 py-3">
        <span class="w-6 h-6 rounded-lg grid place-items-center shrink-0 bg-accent-plum/10 text-accent-plum">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 8l1.5 1.5L11 6M2.5 3.5h11v9h-11z"/></svg>
        </span>
        <div class="min-w-0">
            <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">{{ __('How to set it up') }}</div>
            <p class="text-ink-700 mt-0.5 leading-snug">{{ $how }}</p>
        </div>
    </div>
</div>
