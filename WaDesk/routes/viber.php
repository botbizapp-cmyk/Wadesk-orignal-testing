<?php

/*
|--------------------------------------------------------------------------
| Viber channel routes — WaDesk core (REST Bot API). Phase 1.
|--------------------------------------------------------------------------
|
| A workspace pastes its Viber Public Account auth token; each channel gets its
| own webhook at /api/viber/inbound/{token} (raw, X-Viber-Content-Signature
| verified), auto-registered with Viber via set_webhook on connect. Loaded from
| bootstrap/app.php in the same slot as line/wechat.
|
*/

use App\Http\Controllers\Viber\ViberBroadcastController;
use App\Http\Controllers\Viber\ViberConnectController;
use App\Http\Controllers\Viber\ViberFlowNodeController;
use App\Http\Controllers\Viber\ViberInsightsController;
use App\Http\Controllers\Viber\ViberWebhookController;
use Illuminate\Support\Facades\Route;

// ── Channel connect + manage — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_viber'])
    ->name('user.viber.')
    ->group(function () {
        Route::get('/viber',                  [ViberConnectController::class, 'index'])->name('index');
        Route::post('/viber/connect',         [ViberConnectController::class, 'connect'])->name('connect');
        Route::post('/viber/{channel}/retry', [ViberConnectController::class, 'retry'])->whereNumber('channel')->name('retry');
        Route::post('/viber/{channel}/toggle',[ViberConnectController::class, 'toggle'])->whereNumber('channel')->name('toggle');
        Route::delete('/viber/{channel}',     [ViberConnectController::class, 'destroy'])->whereNumber('channel')->name('destroy');

        // Insights — engagement + read-receipt analytics (read-only).
        Route::get('/viber/insights',         [ViberInsightsController::class, 'index'])->name('insights');
    });

// ── Broadcasts — Viber broadcast_message (≤300/batch), plan:viber_broadcasts. ──
Route::middleware(['web', 'auth', 'plan:viber_broadcasts'])
    ->name('user.viber.broadcasts.')
    ->group(function () {
        Route::get('/viber/broadcasts',                    [ViberBroadcastController::class, 'index'])->name('index');
        Route::get('/viber/broadcasts/create',             [ViberBroadcastController::class, 'create'])->name('create');
        Route::post('/viber/broadcasts',                   [ViberBroadcastController::class, 'store'])->name('store');
        Route::post('/viber/broadcasts/{broadcast}/start', [ViberBroadcastController::class, 'start'])->whereNumber('broadcast')->name('start');
        Route::post('/viber/broadcasts/{broadcast}/pause', [ViberBroadcastController::class, 'pause'])->whereNumber('broadcast')->name('pause');
        Route::post('/viber/broadcasts/{broadcast}/retry', [ViberBroadcastController::class, 'retry'])->whereNumber('broadcast')->name('retry');
        Route::post('/viber/broadcasts/{broadcast}/batch', [ViberBroadcastController::class, 'sendBatch'])->whereNumber('broadcast')->name('batch');
        Route::delete('/viber/broadcasts/{broadcast}',     [ViberBroadcastController::class, 'destroy'])->whereNumber('broadcast')->name('destroy');
    });

// ── Inbound — one signed callback URL per channel. Raw: no session/CSRF. ──
Route::middleware(['api', 'throttle:240,1'])
    ->post('/api/viber/inbound/{token}', [ViberWebhookController::class, 'ingest'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('viber.inbound');

// ── Node flow-engine callbacks — X-Node-Token guarded, CSRF-exempt. ──
Route::middleware('api')->group(function () {
    Route::post('/api/viber/flow-log',  [ViberFlowNodeController::class, 'log']);
    Route::post('/api/viber/flow-node', [ViberFlowNodeController::class, 'node']);
});
