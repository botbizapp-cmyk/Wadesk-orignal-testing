@php
    /** @var \App\Models\BookingType|null $type */
    $fin = $type?->financial;
    $intg = $type?->integration;
    $days = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 0 => __('Sunday')];
    // Group existing availability rules by weekday for pre-render.
    $rulesByDay = collect($type?->availabilityRules ?? [])->groupBy('weekday');
    $tmplByEvent = collect($type?->templates ?? [])->keyBy('event');
    $events = ['confirmation' => __('Confirmation'), 'reminder' => __('Reminder'), 'cancellation' => __('Cancellation'), 'reschedule' => __('Reschedule')];
    $curInput = strtoupper(old('currency', $fin->currency ?? $defaultCurrency));
    // Multi-currency list — DYNAMIC from the admin's active currencies (same source
    // as /deals and checkout). Ensure the current value is present even if inactive.
    $currencyList = \App\Models\Currency::active()->orderBy('code')->pluck('code')->map(fn ($c) => strtoupper($c))->all();
    if ($curInput && ! in_array($curInput, $currencyList, true)) { array_unshift($currencyList, $curInput); }
    if (empty($currencyList)) { $currencyList = [$curInput ?: 'USD']; }
    $isEdit = (bool) $type;
@endphp

<form method="POST"
    action="{{ $isEdit ? route('user.appointments.booking-types.update', $type->id) : route('user.appointments.booking-types.store') }}"
    id="bt-wizard-form"
    data-preview-url="{{ route('user.appointments.booking-types.preview-slots') }}"
    class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_342px] gap-5 items-start">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    @if ($errors->any())
        <div class="xl:col-span-2 bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
            <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Wizard card + stepper (left column) --}}
    <div class="min-w-0 bg-white border border-paper-200 rounded-2xl shadow-card overflow-hidden">
        <div class="px-5 py-4 border-b border-paper-200 bg-paper-50/40 overflow-x-auto">
            <div class="flex items-center min-w-[560px]" id="stepper">
                @foreach ([1 => __('Service'), 2 => __('Timing'), 3 => __('Availability'), 4 => __('Pricing'), 5 => __('Messaging')] as $n => $label)
                    <div class="step-node flex items-center gap-2.5 {{ $n < 5 ? 'flex-1' : '' }} cursor-pointer" data-n="{{ $n }}">
                        <span class="dot w-7 h-7 rounded-full grid place-items-center text-[11px] font-semibold font-mono shrink-0 transition border-[1.5px] {{ $n === 1 ? 'bg-paper-0 border-wa-deep text-wa-deep ring-4 ring-wa-deep/10' : 'bg-paper-0 border-paper-200 text-ink-500' }}">{{ $n }}</span>
                        <span class="lab text-[11.5px] whitespace-nowrap {{ $n === 1 ? 'font-semibold text-wa-deep' : 'font-medium text-ink-500' }}">{{ $label }}</span>
                        @if ($n < 5)<span class="bar flex-1 h-[2px] mx-2 rounded bg-paper-200"></span>@endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="p-5">

    {{-- ───────── STEP 1 — Service ───────── --}}
    <section class="step-pane" data-step="1">
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="font-serif text-[18px]">{{ __('Service details') }}</div>
            <p class="text-[12px] text-ink-500 -mt-1">{{ __('Name the service and where it happens.') }}</p>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Name') }} <span class="text-accent-coral">*</span></span>
                    <input name="name" value="{{ old('name', $type->name ?? '') }}" required maxlength="191" placeholder="{{ __('30-min Demo') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Description') }}</span>
                    <textarea name="description" rows="2" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">{{ old('description', $type->description ?? '') }}</textarea></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Location type') }}</span>
                    <select name="location_type" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        @foreach (['address' => __('In person (address)'), 'virtual' => __('Virtual (video)'), 'phone' => __('Phone call')] as $v => $l)
                            <option value="{{ $v }}" @selected(old('location_type', $type->location_type ?? 'address') === $v)>{{ $l }}</option>
                        @endforeach
                    </select></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Location / address') }}</span>
                    <input name="location_value" value="{{ old('location_value', $type->location_value ?? '') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            </div>
        </div>
    </section>

    {{-- ───────── STEP 2 — Timing ───────── --}}
    <section class="step-pane hidden" data-step="2">
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="font-serif text-[18px]">{{ __('Timing') }}</div>
            <p class="text-[12px] text-ink-500 -mt-1">{{ __('How long each booking runs and how far ahead people can book.') }}</p>
            <div class="grid sm:grid-cols-3 gap-3">
                @foreach ([
                    ['duration_minutes', __('Duration (min)'), $type->duration_minutes ?? 30],
                    ['increment_minutes', __('Start step (min)'), $type->increment_minutes ?? 30],
                    ['capacity', __('Capacity (per slot)'), $type->capacity ?? 1],
                    ['buffer_before_minutes', __('Buffer before (min)'), $type->buffer_before_minutes ?? 0],
                    ['buffer_after_minutes', __('Buffer after (min)'), $type->buffer_after_minutes ?? 0],
                    ['min_notice_minutes', __('Min notice (min)'), $type->min_notice_minutes ?? 240],
                    ['max_advance_days', __('Book up to (days)'), $type->max_advance_days ?? 30],
                    ['max_per_day', __('Max per day'), $type->max_per_day ?? ''],
                ] as [$field, $label, $val])
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ $label }}</span>
                        <input type="number" min="0" name="{{ $field }}" value="{{ old($field, $val) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                @endforeach
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Timezone') }}</span>
                    <input name="timezone" value="{{ old('timezone', $type->timezone ?? $timezone) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
            </div>
        </div>
    </section>

    {{-- ───────── STEP 3 — Availability ───────── --}}
    <section class="step-pane hidden" data-step="3" data-refresh-preview>
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="font-serif text-[18px]">{{ __('Weekly hours') }}</div>
            <p class="text-[12px] text-ink-500 -mt-1">{{ __('Add the times you take bookings each day. The right panel previews the real bookable slots.') }}</p>
            @foreach ($days as $wd => $label)
                @php $ivs = $rulesByDay->get($wd, collect()); @endphp
                <div class="border border-paper-100 rounded-xl p-3" data-day="{{ $wd }}">
                    <div class="flex items-center justify-between">
                        <span class="text-[12.5px] font-semibold text-ink-800">{{ $label }}</span>
                        <button type="button" data-add-interval="{{ $wd }}" class="text-[11px] text-wa-deep font-semibold hover:underline">+ {{ __('Add interval') }}</button>
                    </div>
                    <div class="mt-2 space-y-2" data-intervals="{{ $wd }}">
                        @forelse ($ivs as $i => $r)
                            <div class="flex items-center gap-2" data-interval>
                                <input type="time" name="availability[{{ $wd }}][{{ $i }}][from]" value="{{ substr($r->start_time,0,5) }}" class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                                <span class="text-ink-400 text-[12px]">{{ __('to') }}</span>
                                <input type="time" name="availability[{{ $wd }}][{{ $i }}][to]" value="{{ substr($r->end_time,0,5) }}" class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                                <button type="button" data-remove-interval class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
                            </div>
                        @empty @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ───────── STEP 4 — Pricing ───────── --}}
    <section class="step-pane hidden space-y-4" data-step="4">
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="font-serif text-[18px]">{{ __('Pricing') }} <span class="text-[11px] font-normal text-ink-400">{{ __('(optional)') }}</span></div>
            <p class="text-[12px] text-ink-500 -mt-1">{{ __('Leave the fee at 0 for free bookings. Add a gateway to collect a deposit or full payment in chat.') }}</p>
            <div class="grid sm:grid-cols-3 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Currency') }}</span>
                    <select name="currency" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                        @foreach ($currencyList as $cc)
                            <option value="{{ $cc }}" @selected($curInput === $cc)>{{ $cc }}</option>
                        @endforeach
                    </select></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Fee') }} <span class="text-ink-400 font-normal">(<span data-cur-label>{{ $curInput }}</span>, {{ __('minor units') }})</span></span>
                    <input type="number" min="0" name="fee_minor" data-fee value="{{ old('fee_minor', $fin->fee_minor ?? 0) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                    <span class="text-[10px] text-ink-400">{{ __('e.g. 15000 = 150.00') }}</span></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Tax %') }}</span>
                    <input type="number" min="0" max="100" step="0.01" name="tax_pct" data-tax value="{{ old('tax_pct', $fin->tax_pct ?? 0) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Deposit') }}</span>
                    <select name="deposit_mode" data-deposit-mode class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        @foreach (['none' => __('No prepayment'), 'partial' => __('Partial deposit'), 'full' => __('Full amount')] as $v => $l)
                            <option value="{{ $v }}" @selected(old('deposit_mode', $fin->deposit_mode ?? 'none') === $v)>{{ $l }}</option>
                        @endforeach
                    </select></label>
                <label class="block" data-deposit-value><span class="text-[11px] font-semibold text-ink-700">{{ __('Deposit amount (minor)') }}</span>
                    <input type="number" min="0" name="deposit_value_minor" data-deposit value="{{ old('deposit_value_minor', $fin->deposit_value_minor ?? 0) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Payment gateway') }}</span>
                    <select name="gateway_slug" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        <option value="">{{ __('— none —') }}</option>
                        @foreach ($gateways as $g)
                            <option value="{{ $g['slug'] }}" @selected(old('gateway_slug', $fin->gateway_slug ?? '') === $g['slug'])>{{ $g['label'] }}</option>
                        @endforeach
                    </select></label>
            </div>
            <div class="text-[11px] text-ink-400">{{ __('Defaults to your workspace currency — change it per service if needed. Cancel / no-show fees are recorded only.') }}</div>
        </div>

        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="font-serif text-[18px]">{{ __('Google Calendar') }} <span class="text-[11px] font-normal text-ink-400">{{ __('(optional)') }}</span></div>
            <p class="text-[12px] text-ink-500 -mt-1">{{ __('Bookings work without this. Connect only to sync free/busy, add Meet links and log to Sheets.') }}</p>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Calendar') }}</span>
                    @if (count($calendars))
                        <select name="calendar_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('Primary calendar') }}</option>
                            @foreach ($calendars as $c)
                                <option value="{{ $c['id'] ?? '' }}" @selected(old('calendar_id', $intg->calendar_id ?? '') === ($c['id'] ?? ''))>{{ $c['summary'] ?? ($c['id'] ?? '') }}</option>
                            @endforeach
                        </select>
                    @else
                        <input name="calendar_id" value="{{ old('calendar_id', $intg->calendar_id ?? '') }}" placeholder="{{ __('primary') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep">
                    @endif
                </label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Sheet append (optional)') }}</span>
                    <input name="spreadsheet_id" value="{{ old('spreadsheet_id', $intg->spreadsheet_id ?? '') }}" placeholder="{{ __('Spreadsheet ID') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
                <label class="flex items-center gap-2 text-[12.5px] text-ink-700 sm:col-span-2"><input type="checkbox" name="create_meet" value="1" @checked(old('create_meet', $intg->create_meet ?? false)) class="accent-wa-deep"> {{ __('Create a Google Meet link for each booking') }}</label>
            </div>
        </div>
    </section>

    {{-- ───────── STEP 5 — Messaging ───────── --}}
    <section class="step-pane hidden space-y-4" data-step="5">
        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="font-serif text-[18px]">{{ __('Lifecycle messages') }}</div>
            @php $tmplIdx = 0; @endphp
            @foreach ($events as $ev => $evLabel)
                @php $bt = $tmplByEvent->get($ev); @endphp
                <div class="border border-paper-100 rounded-xl p-3 grid sm:grid-cols-[140px_1fr] gap-3 items-start">
                    <div class="text-[12.5px] font-semibold text-ink-800 pt-2">{{ $evLabel }}</div>
                    <div class="space-y-2">
                        <input type="hidden" name="templates[{{ $tmplIdx }}][event]" value="{{ $ev }}">
                        <input type="hidden" name="templates[{{ $tmplIdx }}][channel]" value="whatsapp">
                        <select name="templates[{{ $tmplIdx }}][wa_template_id]" class="w-full rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-2 text-[12px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('— plain text below —') }}</option>
                            @foreach ($templates as $tp)
                                <option value="{{ $tp['id'] }}" @selected(($bt->wa_template_id ?? null) == $tp['id'])>{{ $tp['name'] }}</option>
                            @endforeach
                        </select>
                        <textarea name="templates[{{ $tmplIdx }}][plain_body]" rows="2" placeholder="{{ __('Plain message (used when no template picked)…') }}" class="w-full rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-2 text-[12px] focus:outline-none focus:border-wa-deep">{{ $bt->plain_body ?? '' }}</textarea>
                    </div>
                </div>
                @php $tmplIdx++; @endphp
            @endforeach
        </div>

        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="flex items-center justify-between">
                <div class="font-serif text-[18px]">{{ __('Reminders') }}</div>
                <button type="button" data-add-reminder class="text-[11px] text-wa-deep font-semibold hover:underline">+ {{ __('Add reminder') }}</button>
            </div>
            <div class="text-[11px] text-ink-400">{{ __('Times must be distinct (e.g. 1440 = 24h before, 180 = 3h before). Up to 8.') }}</div>
            <div class="space-y-2" data-reminders>
                @foreach ($type?->reminders ?? [] as $r)
                    <div class="flex items-center gap-2" data-reminder>
                        <input type="number" min="1" name="reminders[{{ $loop->index }}][offset_minutes]" value="{{ $r->offset_minutes }}" placeholder="1440" class="w-32 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                        <span class="text-[11px] text-ink-400">{{ __('min before') }}</span>
                        <input name="reminders[{{ $loop->index }}][label]" value="{{ $r->label }}" placeholder="{{ __('24h') }}" class="w-24 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                        <button type="button" data-remove-reminder class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-3">
            <div class="flex items-center justify-between">
                <div class="font-serif text-[18px]">{{ __('Questionnaire') }}</div>
                <button type="button" data-add-question class="text-[11px] text-wa-deep font-semibold hover:underline">+ {{ __('Add question') }}</button>
            </div>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Intro message') }}</span>
                <textarea name="intro_message" rows="2" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">{{ old('intro_message', $type->intro_message ?? '') }}</textarea></label>
            <div class="space-y-2" data-questions>
                @foreach ($type?->questions ?? [] as $q)
                    <div class="flex items-center gap-2 flex-wrap" data-question>
                        <input name="questions[{{ $loop->index }}][label]" value="{{ $q->label }}" placeholder="{{ __('Question label') }}" class="flex-1 min-w-[140px] rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                        <select name="questions[{{ $loop->index }}][type]" class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                            @foreach (['text','textarea','select','number','email','phone','date'] as $qt)
                                <option value="{{ $qt }}" @selected($q->type === $qt)>{{ $qt }}</option>
                            @endforeach
                        </select>
                        <input name="questions[{{ $loop->index }}][map_to_contact_field]" value="{{ $q->map_to_contact_field }}" placeholder="{{ __('map→ e.g. email') }}" class="w-32 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                        <label class="flex items-center gap-1 text-[11px] text-ink-500"><input type="checkbox" name="questions[{{ $loop->index }}][required]" value="1" @checked($q->required) class="accent-wa-deep">{{ __('req') }}</label>
                        <button type="button" data-remove-question class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

        </div>{{-- /p-5 --}}

        {{-- Footer nav (matches campaigns wizard) --}}
        <div class="px-5 py-4 border-t border-paper-200 bg-paper-50/40 flex items-center justify-between">
            <button type="button" id="prevBtn" disabled class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium disabled:opacity-40 disabled:cursor-not-allowed">{{ __('Back') }}</button>
            <div class="flex items-center gap-2">
                <a href="{{ route('user.appointments.booking-types.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium">{{ __('Cancel') }}</a>
                <button type="button" id="nextBtn" class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ __('Next') }}</button>
                <button type="submit" id="submitBtn" class="hidden px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12.5px] font-semibold hover:bg-wa-teal">{{ $isEdit ? __('Save changes') : __('Create booking type') }}</button>
            </div>
        </div>
    </div>{{-- /card --}}

    {{-- ───────── Persistent right rail (live summary — visible on every step) ───────── --}}
    <aside class="space-y-4">
        <div class="bg-white border border-paper-200 rounded-2xl shadow-card p-4 sticky top-[92px]">
            <div class="flex items-center justify-between mb-3 px-1">
                <span class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500 flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-wa-green animate-pulse"></span>{{ __('Live summary') }}
                </span>
                <span data-sum-currency class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-wa-bubble text-wa-deep">{{ $curInput }}</span>
            </div>

            {{-- WhatsApp-style booking card preview --}}
            <div class="rounded-[20px] border border-paper-200 bg-paper-50/60 overflow-hidden">
                <div class="bg-wa-deep text-paper-0 px-4 py-3">
                    <div class="font-serif text-[15px] leading-tight truncate" data-sum-name>{{ __('New service') }}</div>
                    <div class="text-[10.5px] opacity-80 mt-0.5 flex items-center gap-1.5">
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 1"/></svg>
                        <span data-sum-duration>30</span> {{ __('min') }} · <span data-sum-location>{{ __('In person') }}</span>
                    </div>
                </div>
                <div class="p-4 space-y-2.5">
                    <div class="text-[12px] text-ink-600 flex justify-between"><span>{{ __('Fee') }}</span><span data-total-fee class="font-mono">—</span></div>
                    <div class="text-[12px] text-ink-600 flex justify-between"><span>{{ __('Tax') }}</span><span data-total-tax class="font-mono">—</span></div>
                    <div class="text-[14px] font-semibold text-ink-900 flex justify-between pt-2 border-t border-paper-100"><span>{{ __('Total') }}</span><span data-total-sum class="font-mono">—</span></div>
                    <div class="text-[12px] text-wa-deep flex justify-between"><span>{{ __('Due now') }}</span><span data-total-due class="font-mono">—</span></div>
                </div>
            </div>

            {{-- Slot preview (refreshes on the Availability step) --}}
            <div class="mt-3 pt-3 border-t border-paper-100">
                <div class="flex items-center justify-between mb-2 px-1">
                    <div class="font-mono text-[9.5px] uppercase tracking-[0.16em] text-ink-500">{{ __('Slot preview') }}</div>
                    <button type="button" data-preview-btn class="text-[11px] text-wa-deep font-semibold hover:underline">{{ __('Refresh') }}</button>
                </div>
                <div data-preview-out class="text-[12px] text-ink-500 px-1">{{ __('Set your weekly hours to preview real bookable times.') }}</div>
            </div>
        </div>
    </aside>

    {{-- Empty-row templates for the JS to clone (kept out of the form via <template>) --}}
    <template data-tpl="interval">
        <div class="flex items-center gap-2" data-interval>
            <input type="time" data-name-from class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            <span class="text-ink-400 text-[12px]">{{ __('to') }}</span>
            <input type="time" data-name-to class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            <button type="button" data-remove-interval class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
        </div>
    </template>
    <template data-tpl="reminder">
        <div class="flex items-center gap-2" data-reminder>
            <input type="number" min="1" data-name-min placeholder="1440" class="w-32 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            <span class="text-[11px] text-ink-400">{{ __('min before') }}</span>
            <input data-name-label placeholder="{{ __('24h') }}" class="w-24 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            <button type="button" data-remove-reminder class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
        </div>
    </template>
    <template data-tpl="question">
        <div class="flex items-center gap-2 flex-wrap" data-question>
            <input data-name-label placeholder="{{ __('Question label') }}" class="flex-1 min-w-[140px] rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            <select data-name-type class="rounded-lg border border-paper-200 bg-paper-0 px-2 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
                @foreach (['text','textarea','select','number','email','phone','date'] as $qt)<option value="{{ $qt }}">{{ $qt }}</option>@endforeach
            </select>
            <input data-name-map placeholder="{{ __('map→ e.g. email') }}" class="w-32 rounded-lg border border-paper-200 bg-paper-0 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">
            <label class="flex items-center gap-1 text-[11px] text-ink-500"><input type="checkbox" data-name-req value="1" checked class="accent-wa-deep">{{ __('req') }}</label>
            <button type="button" data-remove-question class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>
        </div>
    </template>
</form>
