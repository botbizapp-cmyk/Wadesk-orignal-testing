<?php

namespace App\Http\Controllers\Api\App\Concerns;

use Illuminate\Http\Request;

/**
 * Scope a workspace query to the sender the mobile app currently has selected.
 *
 * Every table that records "which number did this belong to" uses the same
 * POLYMORPHIC pair: a `device_id` integer plus a `provider` string. The id
 * alone is meaningless — `device_id = 6` is Unofficial device 6 on a
 * provider='baileys' row but WABA account 6 on a provider='waba' row, and the
 * `devices` / `wa_provider_configs` / per-channel tables all number from 1, so
 * ids genuinely collide. Filtering on the id by itself both MISSES the selected
 * account (when the id belongs to a different table) and can SHOW another
 * channel's rows that happen to share the number.
 *
 * ResolveAppWorkspace resolves the selection once per request into either:
 *   - app_sender_engine + app_sender_id — a channel account (waba, twilio,
 *     telegram, line, wechat, viber, instagram, facebook, sms, email), or
 *   - app_device_id — a plain Unofficial device.
 *
 * Nothing selected → no filter, which keeps older app builds working.
 */
trait ScopesToSelectedSender
{
    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function scopeToSelectedSender($query, Request $request): void
    {
        $senderId     = (int) $request->attributes->get('app_sender_id', 0);
        $senderEngine = (string) $request->attributes->get('app_sender_engine', '');

        if ($senderId > 0 && $senderEngine !== '') {
            $query->where('device_id', $senderId)->where('provider', $senderEngine);

            return;
        }

        $deviceId = (int) $request->attributes->get('app_device_id', 0);
        if ($deviceId > 0) {
            // Rows written before the `provider` column existed left it NULL.
            // Those are Unofficial by definition, so keep them visible rather
            // than hiding an operator's older records behind a stricter filter.
            $query->where('device_id', $deviceId)
                ->where(fn ($w) => $w->whereNull('provider')->orWhere('provider', 'baileys'));
        }
    }

    /**
     * True when `$row` belongs to a DIFFERENT sender than the one selected.
     * Detail/mutation endpoints 404 on true so a record the list filtered out
     * is not still reachable by id.
     *
     * Absent selection → never blocks.
     */
    protected function rowOffSelectedSender(Request $request, $row): bool
    {
        if (! $row) {
            return false;
        }

        $senderId     = (int) $request->attributes->get('app_sender_id', 0);
        $senderEngine = (string) $request->attributes->get('app_sender_engine', '');
        if ($senderId > 0 && $senderEngine !== '') {
            return (int) $row->device_id !== $senderId
                || (string) $row->provider !== $senderEngine;
        }

        $deviceId = (int) $request->attributes->get('app_device_id', 0);
        if ($deviceId > 0) {
            $provider = (string) ($row->provider ?? '');

            return (int) $row->device_id !== $deviceId
                || ($provider !== '' && $provider !== 'baileys');
        }

        return false;
    }
}
