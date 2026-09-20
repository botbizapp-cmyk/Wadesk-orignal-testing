@php
    /** @var \App\Models\Appointment $appt */
    $type = $appt->bookingType;
    $tz = $type?->effectiveTimezone() ?? ($appt->timezone ?: 'UTC');
    $start = $appt->starts_at->copy()->setTimezone($tz);
    $cancelled = in_array($appt->status, ['cancelled', 'no_show'], true);
    $done = $appt->status === 'completed';
@endphp

<x-layouts.guest :title="__('Manage booking')">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-5">
                <span class="inline-flex w-12 h-12 rounded-2xl items-center justify-center text-white mb-2" style="background:#075E54">
                    <svg viewBox="0 0 16 16" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 2v2M11 2v2"/></svg>
                </span>
                <div class="font-serif text-[24px] leading-tight">{{ __('Your booking') }}</div>
            </div>

            @if (session('success'))
                <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono mb-4 text-center">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-2.5 text-[12.5px] text-accent-coral mb-4 text-center">{{ session('error') }}</div>
            @endif

            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="p-5 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-[16px] font-semibold text-ink-900">{{ $type?->name ?? $appt->title }}</div>
                        @if ($cancelled)
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-mono bg-accent-coral/10 text-accent-coral border border-accent-coral/30">{{ __('Cancelled') }}</span>
                        @elseif ($done)
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-mono bg-paper-100 text-ink-600">{{ __('Completed') }}</span>
                        @else
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-mono bg-wa-mint text-wa-deep border border-wa-green/40">{{ __('Confirmed') }}</span>
                        @endif
                    </div>

                    <div class="space-y-2 text-[13px]">
                        <div class="flex items-center gap-2 text-ink-700">
                            <svg viewBox="0 0 16 16" class="w-4 h-4 text-ink-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 2v2M11 2v2"/></svg>
                            <span class="font-medium">{{ $start->format('l, j F Y') }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-ink-700">
                            <svg viewBox="0 0 16 16" class="w-4 h-4 text-ink-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
                            <span class="font-medium">{{ $start->format('g:i A') }}</span>
                            <span class="text-ink-400 text-[11px] font-mono">{{ $tz }}</span>
                        </div>
                        @if ($appt->meet_url)
                            <div class="flex items-center gap-2 text-ink-700">
                                <svg viewBox="0 0 16 16" class="w-4 h-4 text-ink-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2" y="4" width="9" height="8" rx="1.5"/><path d="M11 7l3-2v6l-3-2"/></svg>
                                <a href="{{ $appt->meet_url }}" target="_blank" rel="noopener" class="text-wa-deep font-medium hover:underline truncate">{{ __('Join video call') }}</a>
                            </div>
                        @elseif ($type && $type->location_type === 'address' && $type->location_value)
                            <div class="flex items-center gap-2 text-ink-700">
                                <svg viewBox="0 0 16 16" class="w-4 h-4 text-ink-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M8 1.5a4.5 4.5 0 0 1 4.5 4.5c0 3-4.5 8-4.5 8s-4.5-5-4.5-8A4.5 4.5 0 0 1 8 1.5Z"/><circle cx="8" cy="6" r="1.5"/></svg>
                                <span>{{ $type->location_value }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                @unless ($cancelled || $done)
                    <div class="px-5 py-4 border-t border-paper-100 flex items-center gap-2">
                        <a href="{{ route('booking.manage.reschedule.form', $token) }}" class="flex-1 text-center py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">{{ __('Reschedule') }}</a>
                        <form method="POST" action="{{ route('booking.manage.cancel', $token) }}" class="flex-1" onsubmit="return confirm('{{ __('Cancel this booking?') }}');">
                            @csrf
                            <button type="submit" class="w-full py-2.5 rounded-full border border-accent-coral/40 text-accent-coral text-[13px] font-semibold hover:bg-accent-coral/10">{{ __('Cancel') }}</button>
                        </form>
                    </div>
                @endunless
            </div>

            <div class="text-center text-[11px] text-ink-400 mt-4">{{ __('Powered by') }} {{ function_exists('brand_name') ? brand_name() : 'WaDesk' }}</div>
        </div>
    </div>
</x-layouts.guest>
