<x-layouts.user :title="__('Edit Booking Type')" nav-key="appointments" page="user-booking-type-wizard">

    {{-- Sticky top header bar — same shell as /wa-campaigns/create --}}
    <div class="border-b border-paper-200 bg-paper-0 sticky top-0 z-20">
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-3 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('user.appointments.booking-types.index') }}"
                    class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 flex items-center justify-center shrink-0"
                    title="{{ __('Back to booking types') }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M10 4l-4 4 4 4" />
                    </svg>
                </a>
                <div class="min-w-0">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">
                        {{ __('Appointments / Edit') }}</div>
                    <div class="font-serif text-[20px] leading-tight truncate">{{ $type->name }}</div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium {{ $type->is_active ? 'bg-wa-mint text-wa-deep' : 'bg-paper-50 text-ink-700' }} font-mono">{{ $type->is_active ? __('Active') : __('Inactive') }}</span>
            </div>
        </div>
    </div>

    <section class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-6">
        @include('user.appointments.booking-types._form')
    </section>
</x-layouts.user>
