<?php

/*
|--------------------------------------------------------------------------
| SMS channel routes — part of WaDesk core (Twilio / MSG91).
|--------------------------------------------------------------------------
|
| SMS is a non-WhatsApp channel that REUSES the existing systems: numbers are
| WaProviderConfig provider='sms' rows connected through the normal /connect
| flow (routes/web.php → WaConnectController@saveSms), campaigns/templates are
| the existing builders with an SMS option, and inbound lands in the unified
| team inbox. This file carries the SMS settings page + the raw provider
| webhooks (inbound / delivery-status), declaring its own middleware groups.
| Loaded from bootstrap/app.php in the same slot as facebook/telegram.
|
*/

use App\Http\Controllers\Sms\SmsSettingsController;
use App\Http\Controllers\Sms\SmsStatusController;
use App\Http\Controllers\Sms\SmsWebhookController;
use Illuminate\Support\Facades\Route;

// ── Settings — session + workspace. Plan-gated: SMS is a paid channel, so the
//    whole settings surface requires access_sms (mirrors facebook/telegram/tiktok).
//    The provider webhooks below stay un-gated — Twilio/MSG91 must keep POSTing. ──
Route::middleware(['web', 'auth', 'plan:access_sms'])
    ->prefix('sms')
    ->name('user.sms.')
    ->group(function () {
        Route::get('/', [SmsSettingsController::class, 'index'])->name('index');
        Route::post('/lookup', [SmsSettingsController::class, 'lookup'])->name('lookup');
        Route::post('/{id}/toggle', [SmsSettingsController::class, 'toggle'])->whereNumber('id')->name('toggle');
        Route::delete('/{id}', [SmsSettingsController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });

// ── Provider webhooks — raw (no session, no CSRF). Twilio/MSG91 POST here.
//    CSRF-exemption for api/sms/* is registered in bootstrap/app.php. ──
Route::middleware(['api', 'throttle:240,1'])->group(function () {
    Route::post('/api/sms/inbound', [SmsWebhookController::class, 'ingest'])->name('sms.inbound');
    Route::post('/api/sms/status', [SmsStatusController::class, 'handle'])->name('sms.status');
});
