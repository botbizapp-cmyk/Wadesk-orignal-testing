<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\SlotReservation;
use App\Models\Workspace;
use App\Services\Appointments\AppointmentReminderScheduler;
use App\Services\Appointments\SlotEngine;
use App\Services\PlanLimitGuard;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Node → Laravel API for the multi-service booking flow (X-Node-Token guarded).
 * Isolated from the legacy AppointmentController so the existing single-config
 * flow stays untouched. Endpoints: types, slots (days→times drill-down),
 * reserve (soft-lock), book (the HARD active_slot_key UNIQUE commit).
 */
class BookingApiController extends Controller
{
    public function __construct(private readonly SlotEngine $slots) {}

    private function guard(Request $request): bool
    {
        $expected = node_token();
        $token    = (string) $request->header('X-Node-Token', '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    /** Active booking types for a workspace + the offer-time plan gate. */
    public function types(Request $request): JsonResponse
    {
        if (! $this->guard($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ws = Workspace::find((int) $request->input('workspace_id'));
        if (! $ws) {
            return response()->json(['ok' => false, 'error' => 'no_workspace'], 404);
        }

        // Offer-time gate: feature + monthly quota — never enter the funnel over-limit.
        if ($block = $this->offerGate($ws)) {
            return response()->json($block);
        }

        $types = BookingType::forWorkspace($ws->id)->active()->with('questions', 'financial')
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($t) => [
                'id'       => $t->id,
                'name'     => $t->name,
                'duration' => $t->duration_minutes,
                'description' => $t->description,
                'location_type' => $t->location_type,
                'intro_message' => $t->intro_message,
                // Payment: amount due to book (deposit or full), minor units.
                'due_now'  => $t->financial ? $t->financial->dueNowMinor() : 0,
                'currency' => $t->financial?->currency,
                'has_gateway' => (bool) ($t->financial?->gateway_slug),
                'questions' => $t->questions->map(fn ($q) => [
                    'label' => $q->label, 'type' => $q->type, 'required' => (bool) $q->required,
                    'options' => is_array($q->options) ? $q->options : [], 'map' => $q->map_to_contact_field,
                ])->values(),
            ])->values();

        return response()->json(['ok' => true, 'available' => true, 'types' => $types]);
    }

    /**
     * Slots for a type. Without `day` → the next available DAYS (drill-down step 1).
     * With `day=YYYY-MM-DD` → the open TIMES for that day (step 2). Both paginate
     * with a 9-row page + a cursor (`offset`), so no channel list exceeds 10 rows.
     */
    public function slots(Request $request): JsonResponse
    {
        if (! $this->guard($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ws   = Workspace::find((int) $request->input('workspace_id'));
        $type = $ws ? BookingType::forWorkspace($ws->id)->active()->find((int) $request->input('type_id')) : null;
        if (! $type) {
            return response()->json(['ok' => false, 'error' => 'no_type'], 404);
        }

        $tz     = $type->effectiveTimezone();
        $offset = max(0, (int) $request->input('offset', 0));
        $day    = (string) $request->input('day', '');

        if ($day === '') {
            // ── Day list ── generate slots across the advance window, group by date.
            $from = Carbon::now($tz)->startOfDay();
            $to   = (clone $from)->addDays(max(1, (int) $type->max_advance_days));
            $all  = $this->slots->freeSlots($type->fresh(['availabilityRules', 'overrides', 'reservations', 'integration']), $from, $to, 500);

            $days = [];
            foreach ($all as $s) {
                $d = Carbon::parse($s['start'])->setTimezone($tz);
                $key = $d->format('Y-m-d');
                if (! isset($days[$key])) {
                    $days[$key] = ['day' => $key, 'label' => $d->format('D, j M'), 'count' => 0];
                }
                $days[$key]['count']++;
            }
            $days = array_values($days);
            $page = array_slice($days, $offset, 9);

            return response()->json([
                'ok' => true, 'mode' => 'days', 'tz' => $tz,
                'days' => $page, 'has_more' => count($days) > $offset + 9,
                'next_offset' => $offset + 9,
            ]);
        }

        // ── Time list for a specific day ──
        $from = Carbon::parse($day, $tz)->startOfDay();
        $to   = (clone $from)->endOfDay();
        $all  = $this->slots->freeSlots($type->fresh(['availabilityRules', 'overrides', 'reservations', 'integration']), $from, $to, 200);
        $times = array_map(fn ($s) => [
            'start' => $s['start'], 'end' => $s['end'],
            'label' => Carbon::parse($s['start'])->setTimezone($tz)->format('g:i A'),
        ], $all);
        $page = array_slice($times, $offset, 9);

        return response()->json([
            'ok' => true, 'mode' => 'times', 'tz' => $tz, 'day' => $day,
            'times' => $page, 'has_more' => count($times) > $offset + 9,
            'next_offset' => $offset + 9,
        ]);
    }

    /** Soft-lock a slot for ~10 min while the customer answers/pays. */
    public function reserve(Request $request): JsonResponse
    {
        if (! $this->guard($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ws   = Workspace::find((int) $request->input('workspace_id'));
        $type = $ws ? BookingType::forWorkspace($ws->id)->active()->find((int) $request->input('type_id')) : null;
        if (! $type) {
            return response()->json(['ok' => false, 'error' => 'no_type'], 404);
        }
        $data = $request->validate([
            'starts_at'   => ['required', 'date'],
            'session_ref' => ['nullable', 'string', 'max:191'],
            'channel'     => ['nullable', 'string', 'max:32'],
        ]);

        $start = Carbon::parse($data['starts_at'])->utc();
        $end   = $start->copy()->addMinutes((int) $type->duration_minutes);

        // Verify the slot still has a seat (soft check; the hard guard is at book()).
        $reserved = SlotReservation::where('booking_type_id', $type->id)->live()
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->sum('seats');
        $booked = Appointment::where('booking_type_id', $type->id)->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->sum('capacity_used');
        if ($reserved + $booked >= max(1, (int) $type->capacity)) {
            return response()->json(['ok' => false, 'error' => 'slot_taken']);
        }

        $res = SlotReservation::create([
            'workspace_id'    => $ws->id,
            'booking_type_id' => $type->id,
            'starts_at'       => $start,
            'ends_at'         => $end,
            'session_ref'     => $data['session_ref'] ?? '',
            'channel'         => $data['channel'] ?? 'whatsapp',
            'seats'           => 1,
            'expires_at'      => now()->addMinutes(10),
        ]);

        return response()->json(['ok' => true, 'reservation_id' => $res->id, 'expires_at' => $res->expires_at->toIso8601String()]);
    }

    /**
     * Commit the booking — the HARD double-book guard. Creates a confirmed
     * appointment carrying active_slot_key; the UNIQUE constraint makes a
     * concurrent second commit for the same seat fail. (Google Calendar write,
     * reminders and the confirmation template are wired in P4.)
     */
    public function book(Request $request): JsonResponse
    {
        if (! $this->guard($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ws   = Workspace::find((int) $request->input('workspace_id'));
        $type = $ws ? BookingType::forWorkspace($ws->id)->active()->find((int) $request->input('type_id')) : null;
        if (! $type) {
            return response()->json(['ok' => false, 'error' => 'no_type'], 404);
        }

        // Offer-gate again at book time (usage may have raced past the limit).
        if ($this->offerGate($ws)) {
            return response()->json(['ok' => false, 'error' => 'plan_limit']);
        }

        $data = $request->validate([
            'starts_at'       => ['required', 'date'],
            'contact_id'      => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'customer_name'   => ['nullable', 'string', 'max:191'],
            'customer_phone'  => ['nullable', 'string', 'max:32'],
            'customer_email'  => ['nullable', 'email', 'max:191'],
            'answers'         => ['nullable', 'array'],
            'reservation_id'  => ['nullable', 'integer'],
        ]);

        $tz    = $type->effectiveTimezone();
        // Normalize to UTC before persisting: Eloquent stores a Carbon in its own
        // tz's wall-clock, so a 09:00-IST time would otherwise be re-read as 09:00
        // UTC (a real offset shift). Store the true instant; render in $tz later.
        $start = Carbon::parse($data['starts_at'])->utc();
        $end   = $start->copy()->addMinutes((int) $type->duration_minutes);
        $cap   = max(1, (int) $type->capacity);

        $contact = ! empty($data['contact_id'])
            ? Contact::where('workspace_id', $ws->id)->find($data['contact_id']) : null;
        $conversation = ! empty($data['conversation_id'])
            ? Conversation::where('workspace_id', $ws->id)->find($data['conversation_id']) : null;

        // Request-level idempotency — ONLY when the SAME conversation re-taps the
        // same slot (a double-tap). A different/absent conversation is a different
        // customer and must fall through to the hard UNIQUE guard below.
        if ($conversation) {
            $dupe = Appointment::forWorkspace($ws->id)->where('booking_type_id', $type->id)
                ->where('starts_at', $start)->where('conversation_id', $conversation->id)
                ->whereIn('status', ['pending', 'confirmed'])->first();
            if ($dupe) {
                return response()->json(['ok' => true, 'appointment_id' => $dupe->id, 'duplicate' => true]);
            }
        }

        try {
            $appt = DB::transaction(function () use ($ws, $type, $contact, $conversation, $start, $end, $tz, $cap, $data) {
                // Assign the lowest free seat ordinal under a short lock (capacity-N).
                $ordinal = 1;
                $lock = null;
                if ($cap > 1) {
                    $lock = Cache::lock('book:'.$type->id.':'.$start->clone()->utc()->timestamp, 10);
                    $lock->block(5);
                    $used = Appointment::where('booking_type_id', $type->id)->whereIn('status', ['pending', 'confirmed'])
                        ->where('starts_at', $start)->pluck('active_slot_key')
                        ->map(fn ($k) => (int) last(explode(':', (string) $k)))->all();
                    for ($ordinal = 1; $ordinal <= $cap; $ordinal++) {
                        if (! in_array($ordinal, $used, true)) {
                            break;
                        }
                    }
                }

                try {
                // All seats taken — reject rather than assigning an out-of-range
                // ordinal (which would silently overbook the slot). Inside the try so
                // the finally still releases the seat lock.
                if ($cap > 1 && $ordinal > $cap) {
                    throw new \RuntimeException('slot_full');
                }
                $appt = new Appointment([
                    'workspace_id'    => $ws->id,
                    'user_id'         => $ws->owner_user_id,
                    'booking_type_id' => $type->id,
                    'contact_id'      => $contact?->id,
                    'conversation_id' => $conversation?->id,
                    'title'           => $type->name.' — '.($data['customer_name'] ?: 'Booking'),
                    'description'     => $type->description,
                    'location'        => $type->location_value,
                    'starts_at'       => $start,
                    'ends_at'         => $end,
                    'timezone'        => $tz,
                    'status'          => 'confirmed',
                    'source'          => 'chat',
                    'capacity_used'   => 1,
                    'answers'         => $data['answers'] ?? null,
                    'meta'            => array_filter([
                        'customer_name'  => $data['customer_name'] ?? null,
                        'customer_phone' => $data['customer_phone'] ?? null,
                        'customer_email' => $data['customer_email'] ?? null,
                    ]),
                ]);
                $appt->active_slot_key = $appt->computeSlotKey($ordinal); // UNIQUE guard
                $appt->ensureManageToken();
                $appt->save();

                if (! empty($data['reservation_id'])) {
                    SlotReservation::where('id', $data['reservation_id'])->delete();
                }

                return $appt;
                } finally {
                    // Release the seat lock as soon as the seat is committed — never
                    // hold it for the full TTL, or a second concurrent booking for the
                    // same slot would needlessly time out.
                    optional($lock)->release();
                }
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Someone else is mid-commit on this exact slot — treat as taken.
            return response()->json(['ok' => false, 'error' => 'slot_taken']);
        } catch (\Illuminate\Database\QueryException $e) {
            // NOTE: QueryException extends RuntimeException, so this MUST come before
            // the \RuntimeException catch below or the unique-violation leaks past it.
            if (str_contains(strtolower($e->getMessage()), 'active_slot_key') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return response()->json(['ok' => false, 'error' => 'slot_taken']);
            }
            Log::error('[BOOKING] commit failed: '.$e->getMessage());

            return response()->json(['ok' => false, 'error' => 'commit_failed'], 500);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'slot_full') {
                return response()->json(['ok' => false, 'error' => 'slot_taken']);
            }
            throw $e;
        }

        // Map answers → contact fields.
        $this->applyAnswers($type, $appt, $contact);

        // Confirmation write (Google Calendar / Meet / Sheets) + multi-offset
        // reminders — AFTER the response is sent, so no queue and no held request.
        $apptId = $appt->id;
        $wsId   = $ws->id;
        $typeId = $type->id;
        $phone  = (string) ($data['customer_phone'] ?? '');
        app()->terminating(function () use ($apptId, $wsId, $typeId, $phone) {
            try {
                @set_time_limit(0);
                $ws   = Workspace::find($wsId);
                $type = BookingType::with('integration', 'reminders', 'templates')->find($typeId);
                $appt = Appointment::find($apptId);
                if ($ws && $type && $appt) {
                    $this->afterConfirm($ws, $type, $appt, $phone);
                }
            } catch (\Throwable $e) {
                Log::warning('[BOOKING] afterConfirm failed: '.$e->getMessage());
            }
        });

        return response()->json([
            'ok' => true,
            'appointment_id' => $appt->id,
            'status' => $appt->status,
            'manage_token' => $appt->manage_token,
        ]);
    }

    /**
     * Post-commit side effects: write the Google Calendar event (+ Meet link for
     * virtual), append a Sheets row, and register each reminder offset on the
     * Node scheduler with a widened synthetic id so a reschedule replaces them
     * all. Google is optional — the booking stands without it. No queue/cron.
     */
    private function afterConfirm(Workspace $ws, BookingType $type, Appointment $appt, string $phone): void
    {
        $gcal = app(\App\Services\GoogleCalendar\GoogleCalendarService::class);
        $tz   = $type->effectiveTimezone();
        $intg = $type->integration;

        // ── Calendar event (+ Meet) ──
        try {
            $calendarId = $gcal->resolveCalendarId($ws);
            if ($calendarId && $gcal->isEnabled()) {
                $email = (string) data_get($appt->meta, 'customer_email', '');
                $name  = (string) data_get($appt->meta, 'customer_name', '');
                $attendees = $email ? [['email' => $email, 'displayName' => $name ?: null]] : [];
                $wantMeet = ($intg && $intg->create_meet) || $type->location_type === 'virtual';

                if ($wantMeet) {
                    $event = $gcal->createMeetEvent($ws, $intg->calendar_id ?: $calendarId, $type->name, $appt->starts_at, $appt->ends_at, $attendees, $appt->description, $tz, (bool) $email);
                    $meet  = (string) data_get($event, 'conferenceData.entryPoints.0.uri', '');
                    $appt->forceFill(array_filter([
                        'google_event_id'    => (string) data_get($event, 'id', '') ?: null,
                        'google_calendar_id' => $intg->calendar_id ?: $calendarId,
                        'meet_url'           => $meet ?: null,
                    ]))->save();
                } else {
                    $payload = [
                        'summary'     => $type->name,
                        'description' => (string) $appt->description,
                        'location'    => (string) $type->location_value,
                        'start'       => ['dateTime' => $appt->starts_at->toRfc3339String(), 'timeZone' => $tz],
                        'end'         => ['dateTime' => $appt->ends_at->toRfc3339String(), 'timeZone' => $tz],
                        'reminders'   => ['useDefault' => true],
                    ];
                    if ($attendees) {
                        $payload['attendees'] = $attendees;
                    }
                    $event = $gcal->createEvent($ws, $intg->calendar_id ?: $calendarId, $payload, (bool) $email);
                    if ($event) {
                        $appt->forceFill(['google_event_id' => (string) ($event['id'] ?? ''), 'google_calendar_id' => $intg->calendar_id ?: $calendarId])->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[BOOKING] calendar write failed appt='.$appt->id.': '.$e->getMessage());
        }

        // ── Sheets append (append-only log row) ──
        try {
            if ($intg && $intg->spreadsheet_id) {
                [$tab] = array_pad(explode('!', $intg->sheet_range ?: 'Sheet1!A1'), 1, 'Sheet1');
                app(\App\Services\Google\GoogleApiService::class)->appendSheetRow($ws, $intg->spreadsheet_id, $tab, [
                    $appt->starts_at->copy()->setTimezone($tz)->format('Y-m-d'),
                    $appt->starts_at->copy()->setTimezone($tz)->format('H:i'),
                    (string) data_get($appt->meta, 'customer_name', ''),
                    (string) data_get($appt->meta, 'customer_phone', ''),
                    $type->name,
                    $appt->status,
                    (string) ($appt->meet_url ?? ''),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[BOOKING] sheets append failed appt='.$appt->id.': '.$e->getMessage());
        }

        // ── Multi-offset reminders (widened synthetic id per §7.1) ──
        if ($phone === '') {
            return;
        }
        $scheduler   = app(AppointmentReminderScheduler::class);
        $reminderTpl = optional($type->templates->firstWhere('event', 'reminder'))->plain_body;
        foreach ($type->reminders as $rem) {
            try {
                if (! $rem->is_active) {
                    continue;
                }
                $remindAt = $appt->starts_at->copy()->subMinutes((int) $rem->offset_minutes);
                if ($remindAt->isPast()) {
                    continue;
                }
                $scheduleId = -2_000_000_000 - ($appt->id * 100) - (int) $rem->offset_index;
                $body = $reminderTpl
                    ? strtr($reminderTpl, [
                        '{{type}}' => $type->name,
                        '{{slot}}' => $appt->starts_at->copy()->setTimezone($tz)->format('D j M · g:i A'),
                    ])
                    : null; // null → scheduler renders its default reminder text
                $scheduler->schedule($ws, $appt, $phone, $remindAt, $scheduleId, $body);
            } catch (\Throwable $e) {
                Log::warning('[BOOKING] reminder schedule failed appt='.$appt->id.' offset='.$rem->offset_index.': '.$e->getMessage());
            }
        }
    }

    /**
     * Create a pay-link for a booking deposit/fee. Creates an Order carrying the
     * booking context in gateway_payload, initiates the gateway, and returns the
     * pay URL. The booking is committed later by BookingPaymentController on the
     * payment callback (self-contained — does not touch package checkout).
     */
    public function payLink(Request $request): JsonResponse
    {
        if (! $this->guard($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ws   = Workspace::find((int) $request->input('workspace_id'));
        $type = $ws ? BookingType::forWorkspace($ws->id)->active()->with('financial')->find((int) $request->input('type_id')) : null;
        if (! $type || ! $type->financial) {
            return response()->json(['ok' => false, 'error' => 'no_type'], 404);
        }
        $fin = $type->financial;
        $due = $fin->dueNowMinor();
        $slug = (string) $fin->gateway_slug;
        if ($due <= 0) {
            return response()->json(['ok' => false, 'error' => 'no_payment']);
        }
        if ($slug === '') {
            return response()->json(['ok' => false, 'error' => 'no_gateway']);
        }

        $data = $request->validate([
            'starts_at'       => ['required', 'date'],
            'reservation_id'  => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'customer_name'   => ['nullable', 'string', 'max:191'],
            'customer_phone'  => ['nullable', 'string', 'max:32'],
            'customer_email'  => ['nullable', 'email', 'max:191'],
            'answers'         => ['nullable', 'array'],
        ]);

        $currency = strtoupper($fin->currency ?: ($ws->currency ?: 'USD'));
        $zero = in_array($currency, ['JPY', 'KRW', 'VND', 'CLP', 'PYG', 'UGX', 'RWF', 'XOF', 'XAF', 'IDR'], true);
        $amount = $zero ? $due : round($due / 100, 2);

        try {
            $manager = app(\App\Services\Payment\PaymentGatewayManager::class);
            $driver  = $manager->driver($slug);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'gateway_unavailable']);
        }
        $gwId = optional(\App\Models\PaymentGateway::where('slug', $slug)->first())->id;

        $order = \App\Models\Order::create([
            'order_number'  => 'BK-'.strtoupper(\Illuminate\Support\Str::random(10)),
            'workspace_id'  => $ws->id,
            'user_id'       => $ws->owner_user_id,
            'gateway_id'    => $gwId,
            'gateway_slug'  => $slug,
            'currency'      => $currency,
            'amount'        => $amount,
            'total_amount'  => $amount,
            'status'        => 'pending',
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'gateway_payload' => ['booking' => [
                'workspace_id'    => $ws->id,
                'type_id'         => $type->id,
                'starts_at'       => Carbon::parse($data['starts_at'])->utc()->toIso8601String(),
                'reservation_id'  => $data['reservation_id'] ?? null,
                'conversation_id' => $data['conversation_id'] ?? null,
                'customer_name'   => $data['customer_name'] ?? null,
                'customer_phone'  => $data['customer_phone'] ?? null,
                'customer_email'  => $data['customer_email'] ?? null,
                'answers'         => $data['answers'] ?? null,
                'due_minor'       => $due,
            ]],
        ]);

        $callbackUrl = route('booking.pay.callback', ['gateway' => $slug]).'?bo='.$order->id;

        try {
            $result = $driver->initiate($order, $callbackUrl);
        } catch (\Throwable $e) {
            Log::warning('[BOOKING-PAY] initiate failed: '.$e->getMessage());

            return response()->json(['ok' => false, 'error' => 'initiate_failed']);
        }

        if ($result->gatewayOrderId) {
            $order->update([
                'gateway_order_id' => $result->gatewayOrderId,
                'gateway_payload'  => array_merge((array) $order->gateway_payload, (array) $result->payload),
            ]);
        }
        if ($result->redirectUrl) {
            return response()->json(['ok' => true, 'pay_url' => $result->redirectUrl, 'order_id' => $order->id]);
        }

        return response()->json(['ok' => false, 'error' => 'gateway_no_url']);
    }

    /**
     * Commit a booking AFTER its deposit was paid (called by
     * BookingPaymentController on a verified paid callback). Same hard
     * active_slot_key UNIQUE commit as book(); on a paid-but-slot-gone race it
     * enters the refund/relocate branch instead of silently keeping the money.
     * Returns ['ok'=>bool, 'appointment_id'?, 'manage_token'?, 'error'?, 'refunded'?].
     */
    public function confirmFromPayment(\App\Models\Order $order, ?\App\Services\Payment\AbstractGatewayDriver $driver = null): array
    {
        $b = (array) data_get($order->gateway_payload, 'booking', []);
        if (! $b) {
            return ['ok' => false, 'error' => 'no_booking_context'];
        }
        $ws   = Workspace::find((int) ($b['workspace_id'] ?? 0));
        $type = $ws ? BookingType::forWorkspace($ws->id)->with('financial')->find((int) ($b['type_id'] ?? 0)) : null;
        if (! $ws || ! $type) {
            return ['ok' => false, 'error' => 'no_type'];
        }

        // Already committed for this order (idempotent on re-hit of the callback).
        $existing = Appointment::where('order_id', $order->id)->whereIn('status', ['pending', 'confirmed'])->first();
        if ($existing) {
            return ['ok' => true, 'appointment_id' => $existing->id, 'manage_token' => $existing->manage_token, 'duplicate' => true];
        }

        $tz    = $type->effectiveTimezone();
        $start = Carbon::parse($b['starts_at'])->utc();
        $end   = $start->copy()->addMinutes((int) $type->duration_minutes);
        $cap   = max(1, (int) $type->capacity);
        $contact = ! empty($b['contact_id']) ? Contact::where('workspace_id', $ws->id)->find($b['contact_id']) : null;

        try {
            $appt = DB::transaction(function () use ($ws, $type, $contact, $start, $end, $tz, $cap, $b, $order) {
                $ordinal = 1;
                if ($cap > 1) {
                    $lock = Cache::lock('book:'.$type->id.':'.$start->clone()->utc()->timestamp, 10);
                    $lock->block(5);
                    $used = Appointment::where('booking_type_id', $type->id)->whereIn('status', ['pending', 'confirmed'])
                        ->where('starts_at', $start)->pluck('active_slot_key')
                        ->map(fn ($k) => (int) last(explode(':', (string) $k)))->all();
                    for ($ordinal = 1; $ordinal <= $cap; $ordinal++) {
                        if (! in_array($ordinal, $used, true)) {
                            break;
                        }
                    }
                }
                $appt = new Appointment([
                    'workspace_id'    => $ws->id,
                    'user_id'         => $ws->owner_user_id,
                    'booking_type_id' => $type->id,
                    'contact_id'      => $contact?->id,
                    'conversation_id' => $b['conversation_id'] ?? null,
                    'title'           => $type->name.' — '.($b['customer_name'] ?? 'Booking'),
                    'description'     => $type->description,
                    'location'        => $type->location_value,
                    'starts_at'       => $start,
                    'ends_at'         => $end,
                    'timezone'        => $tz,
                    'status'          => 'confirmed',
                    'payment_status'  => 'paid',
                    'deposit_paid_minor' => (int) ($b['due_minor'] ?? 0),
                    'order_id'        => $order->id,
                    'source'          => 'chat',
                    'capacity_used'   => 1,
                    'answers'         => $b['answers'] ?? null,
                    'meta'            => array_filter([
                        'customer_name'  => $b['customer_name'] ?? null,
                        'customer_phone' => $b['customer_phone'] ?? null,
                        'customer_email' => $b['customer_email'] ?? null,
                    ]),
                ]);
                $appt->active_slot_key = $appt->computeSlotKey($ordinal);
                $appt->ensureManageToken();
                $appt->save();
                if (! empty($b['reservation_id'])) {
                    SlotReservation::where('id', $b['reservation_id'])->delete();
                }

                return $appt;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'active_slot_key') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                // Paid but the slot was taken meanwhile → refund/relocate branch.
                $refunded = false;
                try {
                    if ($driver && method_exists($driver, 'refund')) {
                        $driver->refund($order); // best-effort; drivers that support it
                        $refunded = true;
                    }
                } catch (\Throwable $ex) {
                    Log::warning('[BOOKING-PAY] auto-refund failed order='.$order->id.': '.$ex->getMessage());
                }
                $order->forceFill(['status' => $refunded ? 'refunded' : 'paid', 'gateway_payload' => array_merge((array) $order->gateway_payload, ['refund_required' => ! $refunded])])->save();

                return ['ok' => false, 'error' => 'slot_taken', 'refunded' => $refunded];
            }
            Log::error('[BOOKING-PAY] commit failed order='.$order->id.': '.$e->getMessage());

            return ['ok' => false, 'error' => 'commit_failed'];
        }

        $this->applyAnswers($type, $appt, $contact);

        // Calendar/Meet/Sheets + reminders (queue-free), same as the free path.
        $phone = (string) ($b['customer_phone'] ?? '');
        $apptId = $appt->id; $wsId = $ws->id; $typeId = $type->id;
        app()->terminating(function () use ($apptId, $wsId, $typeId, $phone) {
            try {
                @set_time_limit(0);
                $ws = Workspace::find($wsId);
                $type = BookingType::with('integration', 'reminders', 'templates')->find($typeId);
                $appt = Appointment::find($apptId);
                if ($ws && $type && $appt) {
                    $this->afterConfirm($ws, $type, $appt, $phone);
                }
            } catch (\Throwable $e) {
                Log::warning('[BOOKING-PAY] afterConfirm failed: '.$e->getMessage());
            }
        });

        return ['ok' => true, 'appointment_id' => $appt->id, 'manage_token' => $appt->manage_token];
    }

    /** Feature + monthly-quota check; returns a block payload or null. */
    private function offerGate(Workspace $ws): ?array
    {
        if (! PlanLimitGuard::hasFeature($ws, 'access_appointment_booking')) {
            return ['ok' => true, 'available' => false, 'reason' => 'feature_off'];
        }
        try {
            $used  = Appointment::forWorkspace($ws->id)->whereIn('status', ['pending', 'confirmed'])
                ->where('starts_at', '>=', now()->startOfMonth())->count();
            $limit = (int) (optional($ws->currentPackage ?? null)->appointments_limit ?? 0);
            // limit 0/null = unlimited in this codebase's convention; guard via PlanLimitGuard when it exposes it.
            if ($limit > 0 && $used >= $limit) {
                return ['ok' => true, 'available' => false, 'reason' => 'plan_limit'];
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** Persist questionnaire answers that map to a contact field. */
    private function applyAnswers(BookingType $type, Appointment $appt, ?Contact $contact): void
    {
        $answers = (array) $appt->answers;
        if (! $answers || ! $contact) {
            return;
        }
        try {
            foreach ($type->questions as $q) {
                $field = $q->map_to_contact_field;
                if ($field && isset($answers[$q->label]) && in_array($field, ['email', 'name', 'mobile'], true)) {
                    if (empty($contact->{$field})) {
                        $contact->{$field} = $answers[$q->label];
                    }
                }
            }
            $contact->save();
        } catch (\Throwable $e) {
        }
    }
}
