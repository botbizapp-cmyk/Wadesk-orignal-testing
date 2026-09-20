<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\SlotReservation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Queue-free appointment lifecycle maintenance, run inline off the Node 30s
 * heartbeat (WaConnectController) beside the other sweepers — never a cron.
 * Does three things, all cache-gated so only one worker runs at a time:
 *   1) free expired slot-reservation soft-locks (so held seats re-open);
 *   2) flag past-confirmed appointments with NO attendance signal as
 *      `needs_review` (an explicit human then decides completed vs no_show —
 *      we NEVER guess no_show from the clock);
 *   3) auto-COMPLETE appointments that were explicitly checked in.
 */
class AppointmentLifecycleSweeper
{
    public function sweep(): void
    {
        $lock = Cache::lock('appointment-lifecycle-sweeper', 25);
        if (! $lock->get()) {
            return;
        }
        try {
            // 1) Expired soft-locks.
            SlotReservation::where('expires_at', '<', now())->limit(200)->delete();

            $cutoff = now()->subMinutes(15);

            // 2) Past-confirmed, never checked in → flag for a human decision.
            Appointment::where('status', 'confirmed')
                ->where('ends_at', '<', $cutoff)
                ->whereNull('checked_in_at')
                ->whereNull('meta->needs_review')
                ->limit(100)
                ->get()
                ->each(function (Appointment $a) {
                    $meta = (array) $a->meta;
                    $meta['needs_review'] = true;
                    $a->forceFill(['meta' => $meta])->saveQuietly();
                });

            // 3) Past-confirmed AND checked in → attendance known → complete.
            Appointment::where('status', 'confirmed')
                ->where('ends_at', '<', $cutoff)
                ->whereNotNull('checked_in_at')
                ->limit(100)
                ->update(['status' => 'completed']);
        } catch (\Throwable $e) {
            Log::warning('[APPT-LIFECYCLE] sweep failed: '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
