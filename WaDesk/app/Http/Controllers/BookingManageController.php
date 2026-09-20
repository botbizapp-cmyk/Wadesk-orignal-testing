<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\Appointments\AppointmentReminderScheduler;
use App\Services\Appointments\SlotEngine;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public, capability-token booking management (reschedule / cancel). The
 * manage_token is an UNGUESSABLE random capability — possession authorises
 * managing that one booking; there is no login. Safe HTTP semantics: GET only
 * RENDERS (so a WhatsApp link-preview prefetch can't cancel anything), every
 * mutation is POST. Fully self-contained — nothing re-enters the Node session.
 */
class BookingManageController extends Controller
{
    public function __construct(private readonly SlotEngine $slots, private readonly GoogleCalendarService $gcal) {}

    private function resolve(string $token): Appointment
    {
        $appt = Appointment::with('bookingType')->where('manage_token', $token)->first();
        abort_unless($appt, 404);

        return $appt;
    }

    /** GET /b/manage/{token} — render the booking + Reschedule/Cancel (no mutation). */
    public function show(string $token): View
    {
        $appt = $this->resolve($token);

        return view('public.booking-manage.show', ['appt' => $appt, 'token' => $token]);
    }

    /** GET /b/manage/{token}/reschedule — the self-contained slot picker page. */
    public function rescheduleForm(string $token): View
    {
        $appt = $this->resolve($token);
        abort_if(in_array($appt->status, ['cancelled', 'completed', 'no_show'], true), 410);

        return view('public.booking-manage.reschedule', ['appt' => $appt, 'token' => $token]);
    }

    /** GET /b/manage/{token}/slots — day/time slots for THIS booking's type (JSON). */
    public function slots(string $token, Request $request): JsonResponse
    {
        $appt = $this->resolve($token);
        $type = $appt->bookingType;
        if (! $type) {
            return response()->json(['ok' => false, 'error' => 'no_type'], 404);
        }
        $type->loadMissing('availabilityRules', 'overrides', 'reservations', 'integration');
        $tz  = $type->effectiveTimezone();
        $day = (string) $request->query('day', '');

        if ($day === '') {
            $from = Carbon::now($tz)->startOfDay();
            $to   = (clone $from)->addDays(max(1, (int) $type->max_advance_days));
            $all  = $this->slots->freeSlots($type, $from, $to, 500);
            $days = [];
            foreach ($all as $s) {
                $d = Carbon::parse($s['start'])->setTimezone($tz);
                $k = $d->format('Y-m-d');
                $days[$k] = $days[$k] ?? ['day' => $k, 'label' => $d->format('D, j M')];
            }

            return response()->json(['ok' => true, 'mode' => 'days', 'days' => array_values($days)]);
        }

        $from  = Carbon::parse($day, $tz)->startOfDay();
        $to    = (clone $from)->endOfDay();
        $all   = $this->slots->freeSlots($type, $from, $to, 200);
        $times = array_map(fn ($s) => ['start' => $s['start'], 'label' => Carbon::parse($s['start'])->setTimezone($tz)->format('g:i A')], $all);

        return response()->json(['ok' => true, 'mode' => 'times', 'times' => $times]);
    }

    /** POST /b/manage/{token}/reschedule — move the booking to a new slot. */
    public function reschedule(string $token, Request $request): RedirectResponse
    {
        $appt = $this->resolve($token);
        $type = $appt->bookingType;
        abort_if(! $type || in_array($appt->status, ['cancelled', 'completed', 'no_show'], true), 410);

        $data = $request->validate(['starts_at' => ['required', 'date']]);
        $newStart = Carbon::parse($data['starts_at'])->utc();
        $newEnd   = $newStart->copy()->addMinutes((int) $type->duration_minutes);
        $oldEventId = $appt->google_event_id;

        try {
            DB::transaction(function () use ($appt, $newStart, $newEnd) {
                $appt->starts_at = $newStart;
                $appt->ends_at   = $newEnd;
                $appt->active_slot_key = $appt->computeSlotKey(); // recompute → UNIQUE guard on the new slot
                $appt->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', __('That time was just taken — please pick another.'));
        }

        // Move the calendar event + re-register reminders (same synthetic ids → replace).
        $this->syncCalendarAndReminders($appt, $type, $oldEventId);

        return redirect()->route('booking.manage.show', $token)->with('success', __('Your booking has been rescheduled.'));
    }

    /** POST /b/manage/{token}/cancel — cancel the booking, free the slot. */
    public function cancel(string $token): RedirectResponse
    {
        $appt = $this->resolve($token);
        abort_if(in_array($appt->status, ['cancelled', 'completed', 'no_show'], true), 410);
        $type = $appt->bookingType;

        // Cancel-fee: recorded only in this release (no card-on-file charging).
        $feeRecorded = 0;
        if ($type && $type->financial && $type->financial->cancel_fee_minor > 0) {
            $windowMin = (int) $type->financial->cancel_window_minutes;
            if ($appt->starts_at->diffInMinutes(now()) <= $windowMin) {
                $feeRecorded = (int) $type->financial->cancel_fee_minor;
            }
        }

        $meta = (array) $appt->meta;
        if ($feeRecorded > 0) {
            $meta['cancel_fee_recorded_minor'] = $feeRecorded;
        }
        $meta['cancelled_via'] = 'manage_link';

        $appt->forceFill([
            'status'         => 'cancelled',
            'active_slot_key' => null,   // free the slot (multiple NULLs don't conflict)
            'meta'           => $meta,
        ])->save();

        // Remove the calendar event.
        try {
            if ($appt->google_event_id && $appt->google_calendar_id) {
                $this->gcal->deleteEvent($appt->workspace, $appt->google_calendar_id, $appt->google_event_id);
            }
        } catch (\Throwable $e) {
            Log::warning('[BOOKING-MANAGE] calendar delete failed: '.$e->getMessage());
        }

        return redirect()->route('booking.manage.show', $token)->with('success', __('Your booking has been cancelled.'));
    }

    /** Update the Google event to the new time + re-register all reminder offsets. */
    private function syncCalendarAndReminders(Appointment $appt, $type, ?string $oldEventId): void
    {
        $tz = $type->effectiveTimezone();
        try {
            if ($oldEventId && $appt->google_calendar_id && $this->gcal->isEnabled()) {
                // Simplest robust move: delete the old event, create a fresh one.
                try { $this->gcal->deleteEvent($appt->workspace, $appt->google_calendar_id, $oldEventId); } catch (\Throwable $e) {}
                $appt->forceFill(['google_event_id' => null])->save();
                $payload = [
                    'summary' => $type->name,
                    'start'   => ['dateTime' => $appt->starts_at->toRfc3339String(), 'timeZone' => $tz],
                    'end'     => ['dateTime' => $appt->ends_at->toRfc3339String(), 'timeZone' => $tz],
                    'reminders' => ['useDefault' => true],
                ];
                $event = $this->gcal->createEvent($appt->workspace, $appt->google_calendar_id, $payload, false);
                if ($event) {
                    $appt->forceFill(['google_event_id' => (string) ($event['id'] ?? '')])->save();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[BOOKING-MANAGE] calendar move failed: '.$e->getMessage());
        }

        // Re-register reminders on the NEW time (same synthetic ids → Node replaces).
        $phone = (string) data_get($appt->meta, 'customer_phone', '');
        if ($phone === '' || ! $type) {
            return;
        }
        $scheduler = app(AppointmentReminderScheduler::class);
        $reminderTpl = optional($type->templates->firstWhere('event', 'reminder'))->plain_body ?? null;
        $type->loadMissing('reminders');
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
                $body = $reminderTpl ? strtr($reminderTpl, ['{{type}}' => $type->name, '{{slot}}' => $appt->starts_at->copy()->setTimezone($tz)->format('D j M · g:i A')]) : null;
                $scheduler->schedule($appt->workspace, $appt, $phone, $remindAt, $scheduleId, $body);
            } catch (\Throwable $e) {
            }
        }
    }
}
