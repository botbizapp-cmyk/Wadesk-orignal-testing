@php
    /** @var \App\Models\Appointment $appt */
    $type = $appt->bookingType;
@endphp

<x-layouts.guest :title="__('Reschedule booking')">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md"
            data-reschedule
            data-slots-url="{{ route('booking.manage.slots', $token) }}"
            data-post-url="{{ route('booking.manage.reschedule', $token) }}"
            data-csrf="{{ csrf_token() }}">
            <div class="flex items-center gap-3 mb-4">
                <a href="{{ route('booking.manage.show', $token) }}" class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3L5 8l5 5"/></svg>
                </a>
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Reschedule') }}</div>
                    <div class="font-serif text-[20px] leading-tight">{{ $type?->name ?? __('Your booking') }}</div>
                </div>
            </div>

            @if (session('error'))
                <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-2.5 text-[12.5px] text-accent-coral mb-4">{{ session('error') }}</div>
            @endif

            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-4">
                <div>
                    <div class="text-[11px] font-semibold text-ink-700 mb-2">{{ __('Pick a day') }}</div>
                    <div data-days class="flex flex-wrap gap-2"><span class="text-[12px] text-ink-400">{{ __('Loading…') }}</span></div>
                </div>
                <div data-times-wrap class="hidden">
                    <div class="text-[11px] font-semibold text-ink-700 mb-2">{{ __('Pick a time') }}</div>
                    <div data-times class="flex flex-wrap gap-2"></div>
                </div>
                <form method="POST" action="{{ route('booking.manage.reschedule', $token) }}" data-form class="hidden">
                    @csrf
                    <input type="hidden" name="starts_at" data-starts>
                    <button type="submit" class="w-full py-2.5 rounded-full bg-wa-deep text-paper-0 text-[13px] font-semibold hover:bg-wa-teal">
                        {{ __('Confirm new time') }} · <span data-chosen></span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var root = document.querySelector('[data-reschedule]');
        if (!root) return;
        var slotsUrl = root.dataset.slotsUrl;
        var daysBox = root.querySelector('[data-days]');
        var timesWrap = root.querySelector('[data-times-wrap]');
        var timesBox = root.querySelector('[data-times]');
        var form = root.querySelector('[data-form]');
        var startsInput = root.querySelector('[data-starts]');
        var chosen = root.querySelector('[data-chosen]');
        var chip = 'px-3 py-1.5 rounded-full border border-paper-200 bg-paper-0 hover:border-wa-deep text-[12.5px] cursor-pointer transition';
        var chipOn = 'px-3 py-1.5 rounded-full border border-wa-deep bg-wa-mint text-wa-deep text-[12.5px] cursor-pointer';

        function get(params) {
            var qs = new URLSearchParams(params || {}).toString();
            return fetch(slotsUrl + (qs ? '?' + qs : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); });
        }

        get({}).then(function (d) {
            daysBox.innerHTML = '';
            if (!d.ok || !(d.days || []).length) { daysBox.innerHTML = '<span class="text-[12px] text-ink-400">No open times available.</span>'; return; }
            d.days.forEach(function (day) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = chip; b.textContent = day.label;
                b.addEventListener('click', function () {
                    daysBox.querySelectorAll('button').forEach(function (x) { x.className = chip; });
                    b.className = chipOn;
                    loadTimes(day.day);
                });
                daysBox.appendChild(b);
            });
        });

        function loadTimes(day) {
            timesWrap.classList.remove('hidden');
            timesBox.innerHTML = '<span class="text-[12px] text-ink-400">Loading…</span>';
            form.classList.add('hidden');
            get({ day: day }).then(function (d) {
                timesBox.innerHTML = '';
                if (!d.ok || !(d.times || []).length) { timesBox.innerHTML = '<span class="text-[12px] text-ink-400">No times this day.</span>'; return; }
                d.times.forEach(function (t) {
                    var b = document.createElement('button');
                    b.type = 'button'; b.className = chip; b.textContent = t.label;
                    b.addEventListener('click', function () {
                        timesBox.querySelectorAll('button').forEach(function (x) { x.className = chip; });
                        b.className = chipOn;
                        startsInput.value = t.start;
                        chosen.textContent = t.label;
                        form.classList.remove('hidden');
                    });
                    timesBox.appendChild(b);
                });
            });
        }
    })();
    </script>
</x-layouts.guest>
