<?php

namespace App\Services\Appointments;

use App\Models\BookingType;
use App\Models\Workspace;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Slot generation for a Booking Type. Deliberately a THIN adapter — it does NOT
 * re-implement the candidate/buffer/busy grid (that lives in
 * GoogleCalendarService::computeFreeSlots(), which we extended to accept
 * per-type opts). This class only adds the three things that method has no
 * concept of: date overrides, live slot_reservations, and capacity-N seats.
 */
class SlotEngine
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(private readonly GoogleCalendarService $gcal) {}

    /**
     * Open slots for a saved Booking Type between $from and $to (invitee sees
     * labels in the type tz). Returns [ ['start','end','label','remaining'], … ].
     */
    public function freeSlots(BookingType $type, CarbonInterface $from, CarbonInterface $to, int $limit = 100): array
    {
        $tz  = $type->effectiveTimezone();
        $cap = max(1, (int) $type->capacity);

        $slots = $this->gcal->computeFreeSlots($type->workspace, $limit, [
            'tz'                   => $tz,
            'windows'              => $this->windowsFromRules($type),
            'duration'             => (int) $type->duration_minutes,
            'increment'            => (int) ($type->increment_minutes ?: $type->duration_minutes),
            'buffer_before'        => (int) $type->buffer_before_minutes,
            'buffer_after'         => (int) $type->buffer_after_minutes,
            'max_per_day'          => (int) ($type->max_per_day ?: 100),
            'min_notice'           => (int) $type->min_notice_minutes,
            'advance_days'         => (int) $type->max_advance_days,
            'booking_type_id'      => $type->id,
            'from'                 => Carbon::instance($from),
            'to'                   => Carbon::instance($to),
            // For capacity-1, let computeFreeSlots subtract booked appointments
            // (with buffers). For capacity-N we count seats ourselves.
            'skip_appointment_busy' => $cap > 1,
            'calendar_id'          => optional($type->integration)->calendar_id,
        ]);

        return $this->applyOverridesReservationsCapacity($type, $slots, $tz, $cap, $from, $to);
    }

    /**
     * Stateless preview from an in-progress wizard draft (no saved type / no id).
     * Resolves the create-time preview problem — runs the same engine purely from
     * the posted config for a sample date range. No reservations/capacity (the
     * type isn't saved yet, so nothing holds its slots).
     */
    public function previewFromDraft(Workspace $ws, array $draft, CarbonInterface $from, CarbonInterface $to, int $limit = 60): array
    {
        $tz = $draft['timezone'] ?? ($ws->timezone ?: config('app.timezone'));
        $tz = function_exists('safe_timezone') ? safe_timezone($tz) : ($tz ?: 'UTC');

        return $this->gcal->computeFreeSlots($ws, $limit, [
            'tz'            => $tz,
            'windows'       => $this->windowsFromDraftRules((array) ($draft['availability'] ?? [])),
            'duration'      => (int) ($draft['duration_minutes'] ?? 30),
            'increment'     => (int) ($draft['increment_minutes'] ?? ($draft['duration_minutes'] ?? 30)),
            'buffer_before' => (int) ($draft['buffer_before_minutes'] ?? 0),
            'buffer_after'  => (int) ($draft['buffer_after_minutes'] ?? 0),
            'max_per_day'   => (int) (($draft['max_per_day'] ?? 0) ?: 100),
            'min_notice'    => (int) ($draft['min_notice_minutes'] ?? 0),
            'advance_days'  => (int) ($draft['max_advance_days'] ?? 30),
            'from'          => Carbon::instance($from),
            'to'            => Carbon::instance($to),
            'skip_appointment_busy' => true, // draft type has no bookings of its own
            'calendar_id'   => $draft['calendar_id'] ?? null,
        ]);
    }

    /** Map a type's availability rules → the weekday-keyed window shape computeFreeSlots consumes. */
    private function windowsFromRules(BookingType $type): array
    {
        $out = [];
        foreach ($type->availabilityRules as $r) {
            $key = self::DAY_KEYS[$r->weekday] ?? null;
            if (! $key) {
                continue;
            }
            $out[$key][] = ['from' => substr((string) $r->start_time, 0, 5), 'to' => substr((string) $r->end_time, 0, 5)];
        }

        return $out;
    }

    /** Draft rules come as [ weekday => [ ['from','to'], … ] ] or [ dayKey => [...] ]. */
    private function windowsFromDraftRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $k => $intervals) {
            $key = is_numeric($k) ? (self::DAY_KEYS[(int) $k] ?? null) : strtolower(substr((string) $k, 0, 3));
            if (! $key || ! is_array($intervals)) {
                continue;
            }
            foreach ($intervals as $iv) {
                $from = $iv['from'] ?? ($iv['start'] ?? null);
                $to   = $iv['to'] ?? ($iv['end'] ?? null);
                if ($from && $to) {
                    $out[$key][] = ['from' => substr((string) $from, 0, 5), 'to' => substr((string) $to, 0, 5)];
                }
            }
        }

        return $out;
    }

    /** The only real post-processing: drop closed dates, subtract reservations + capacity seats. */
    private function applyOverridesReservationsCapacity(BookingType $type, array $slots, string $tz, int $cap, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $slots) {
            return [];
        }

        // Closed override dates (Y-m-d in the type tz).
        $closed = $type->overrides()->where('is_closed', true)
            ->pluck('date')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('Y-m-d'))->all();

        // Live reservation seats overlapping the whole range (preloaded once).
        $reservations = $type->reservations()->live()
            ->where('ends_at', '>', $from)->where('starts_at', '<', $to)
            ->get(['starts_at', 'ends_at', 'seats']);

        // For capacity-N, appointment seats (computeFreeSlots skipped them).
        $appts = $cap > 1
            ? $type->appointments()->whereIn('status', ['pending', 'confirmed'])
                ->where('ends_at', '>', $from)->where('starts_at', '<', $to)
                ->get(['starts_at', 'ends_at', 'capacity_used'])
            : collect();

        $out = [];
        foreach ($slots as $s) {
            $ss = Carbon::parse($s['start']);
            $se = Carbon::parse($s['end']);

            if ($closed && in_array($ss->copy()->setTimezone($tz)->format('Y-m-d'), $closed, true)) {
                continue;
            }

            $reserved = 0;
            foreach ($reservations as $r) {
                if ($r->starts_at->lt($se) && $r->ends_at->gt($ss)) {
                    $reserved += (int) $r->seats;
                }
            }
            $used = 0;
            foreach ($appts as $a) {
                if ($a->starts_at->lt($se) && $a->ends_at->gt($ss)) {
                    $used += (int) ($a->capacity_used ?: 1);
                }
            }

            if ($used + $reserved >= $cap) {
                continue; // slot full
            }
            $out[] = $s + ['remaining' => $cap - $used - $reserved];
        }

        return $out;
    }
}
