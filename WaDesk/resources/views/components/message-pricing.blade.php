@props(['compact' => false])
@php
    use App\Services\MessageCreditRate;
    $__pricing = MessageCreditRate::displayTable();
    $__labels = [
        'marketing'      => __('Marketing'),
        'utility'        => __('Utility'),
        'authentication' => __('Authentication'),
        'service'        => __('Service'),
    ];
    $__tints = [
        'marketing'      => 'bg-[#FDE8E4] text-[#B3402E]',
        'utility'        => 'bg-[#E0F2FE] text-[#0369A1]',
        'authentication' => 'bg-[#EDE9FE] text-[#6D28D9]',
        'service'        => 'bg-wa-mint text-wa-deep',
    ];
    $__money = fn ($minor) => \App\Support\FormatSettings::display($minor / 100);
@endphp
<div {{ $attributes->merge(['class' => 'bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-4']) }}>
    <div class="flex items-center justify-between gap-2 mb-3">
        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Message pricing') }}</div>
        <span class="text-[10.5px] text-ink-400">{{ __('charged only on delivery') }}</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
        @foreach ($__pricing as $cat => $p)
            <div class="rounded-xl border border-paper-200 px-3 py-2.5">
                <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $__tints[$cat] ?? 'bg-paper-100 text-ink-600' }}">{{ $__labels[$cat] ?? ucfirst($cat) }}</span>
                <div class="mt-1.5 text-[15px] font-semibold text-ink-900 leading-none">
                    @if ($p['free'])
                        {{ __('Free') }}
                    @else
                        {!! $__money($p['minor']) !!}
                    @endif
                </div>
                @unless ($p['free'])
                    <div class="text-[10.5px] text-ink-500 mt-0.5">{{ $p['credits'] }} {{ trans_choice('credit|credits', $p['credits']) }} / {{ __('msg') }}</div>
                @else
                    <div class="text-[10.5px] text-ink-500 mt-0.5">{{ __('no charge') }}</div>
                @endunless
            </div>
        @endforeach
    </div>
    @unless ($compact)
        <p class="text-[10.5px] text-ink-400 mt-2.5 leading-snug">{{ __('Per delivered WhatsApp message. The exact charge can vary by the recipient\'s country; these are your workspace\'s base rates.') }}</p>
    @endunless
</div>
