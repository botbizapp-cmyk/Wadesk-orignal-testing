@php
    $ok = false;
    $color = match ($state) {
        'pending' => '#7B5A14',
        default   => '#A1431F', // error / taken / failed
    };
@endphp

<x-layouts.guest :title="__('Payment')">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md text-center">
            <span class="inline-flex w-14 h-14 rounded-2xl items-center justify-center text-white mb-4" style="background:{{ $color }}">
                @if ($state === 'pending')
                    <svg viewBox="0 0 16 16" class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 1.5"/></svg>
                @else
                    <svg viewBox="0 0 16 16" class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 1.5 1.5 13h13L8 1.5Z"/><path d="M8 6v3.5M8 11.5h.01"/></svg>
                @endif
            </span>
            <div class="font-serif text-[22px] leading-tight mb-2">
                {{ $state === 'pending' ? __('Payment processing') : ($state === 'failed' ? __('Payment failed') : __('Couldn’t confirm')) }}
            </div>
            <p class="text-[13px] text-ink-600 max-w-sm mx-auto">{{ $message }}</p>
            <div class="text-[11px] text-ink-400 mt-6">{{ __('Powered by') }} {{ function_exists('brand_name') ? brand_name() : 'WaDesk' }}</div>
        </div>
    </div>
</x-layouts.guest>
