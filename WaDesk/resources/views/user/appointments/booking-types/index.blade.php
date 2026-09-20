<x-layouts.user :title="__('Booking Types')" nav-key="appointments" page="user-booking-types-index">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Appointments') }} · {{ __('Services') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('Booking') }} <span class="italic text-wa-deep">{{ __('types') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">{{ __('Each service has its own duration, availability, pricing, questionnaire and reminders. Customers book it entirely inside chat.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.appointments.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All bookings') }}</a>
                <a href="{{ route('user.appointments.settings') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Settings') }}</a>
                <a href="{{ route('user.appointments.booking-types.create') }}" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10"/></svg>
                    {{ __('New booking type') }}
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>
        @endif

        @forelse ($types as $t)
            @php $fin = $t->financial; @endphp
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-4 flex items-center gap-4">
                <span class="w-11 h-11 rounded-2xl grid place-items-center shrink-0 text-white" style="background:{{ $t->color ?: '#075E54' }}">
                    <svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 2v2M11 2v2"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-[15px] font-semibold text-ink-900 truncate">{{ $t->name }}</span>
                        @if ($t->is_active)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Active') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-paper-100 text-ink-600">{{ __('Paused') }}</span>
                        @endif
                    </div>
                    <div class="text-[12px] text-ink-500 mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5">
                        <span>{{ $t->duration_minutes }} {{ __('min') }}</span>
                        <span>{{ ucfirst($t->location_type) }}</span>
                        @if ($t->capacity > 1)<span>{{ __('capacity') }} {{ $t->capacity }}</span>@endif
                        @if ($fin && $fin->fee_minor > 0)<span class="text-ink-700">{{ $fin->currency }} {{ number_format($fin->fee_minor / 100, 2) }}</span>@endif
                        <span>{{ $t->appointments_count }} {{ __('booked') }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <form method="POST" action="{{ route('user.appointments.booking-types.toggle', $t->id) }}">@csrf
                        <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500" title="{{ $t->is_active ? __('Pause') : __('Activate') }}">
                            @if ($t->is_active)<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 4v8M10 4v8"/></svg>@else<svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="currentColor"><path d="M5 3l8 5-8 5z"/></svg>@endif
                        </button>
                    </form>
                    <a href="{{ route('user.appointments.booking-types.edit', $t->id) }}" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500" title="{{ __('Edit') }}">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 2.5l2 2L6 12l-3 1 1-3z"/></svg>
                    </a>
                    <form method="POST" action="{{ route('user.appointments.booking-types.destroy', $t->id) }}" data-confirm="{{ __('Delete this booking type? Existing bookings stay.') }}">@csrf @method('DELETE')
                        <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10" title="{{ __('Delete') }}">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-wa-mint text-wa-deep"><svg viewBox="0 0 16 16" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 2v2M11 2v2"/></svg></span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('No booking types yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 max-w-md mx-auto">{{ __('Create your first service — set its duration, hours and price, and customers can book it from chat.') }}</p>
                <a href="{{ route('user.appointments.booking-types.create') }}" class="inline-flex mt-4 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold">{{ __('New booking type') }}</a>
            </div>
        @endforelse
    </main>
</x-layouts.user>
