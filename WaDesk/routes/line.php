<?php

/*
|--------------------------------------------------------------------------
| LINE channel routes — WaDesk core (LINE Messaging API). Phase 1.
|--------------------------------------------------------------------------
|
| A workspace pastes a Channel access token + Channel secret; each channel has
| its own webhook at /api/line/inbound/{token} (raw, X-Line-Signature-verified,
| no session). Loaded from bootstrap/app.php in the same slot as telegram.
|
*/

use App\Http\Controllers\Line\LineBroadcastController;
use App\Http\Controllers\Line\LineConnectController;
use App\Http\Controllers\Line\LineFlowNodeController;
use App\Http\Controllers\Line\LineInsightsController;
use App\Http\Controllers\Line\LineRichMenuController;
use App\Http\Controllers\Line\LineWebhookController;
use Illuminate\Support\Facades\Route;

// ── Channel connect + manage — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_line'])
    ->name('user.line.')
    ->group(function () {
        Route::get('/line',                  [LineConnectController::class, 'index'])->name('index');
        Route::post('/line/connect',         [LineConnectController::class, 'connect'])->name('connect');
        Route::post('/line/{channel}/retry', [LineConnectController::class, 'retry'])->whereNumber('channel')->name('retry');
        Route::post('/line/{channel}/rotation',[LineConnectController::class, 'rotation'])->whereNumber('channel')->name('rotation');
        Route::post('/line/{channel}/toggle',[LineConnectController::class, 'toggle'])->whereNumber('channel')->name('toggle');
        Route::delete('/line/{channel}',     [LineConnectController::class, 'destroy'])->whereNumber('channel')->name('destroy');

        // Rich menus — the tappable image panel pinned to the chat bottom.
        Route::get('/line/rich-menus',                       [LineRichMenuController::class, 'index'])->name('rich-menus.index');
        Route::get('/line/rich-menus/create',                [LineRichMenuController::class, 'create'])->name('rich-menus.create');
        Route::post('/line/rich-menus',                      [LineRichMenuController::class, 'store'])->name('rich-menus.store');
        Route::post('/line/rich-menus/{richMenu}/default',   [LineRichMenuController::class, 'setDefault'])->whereNumber('richMenu')->name('rich-menus.default');
        Route::post('/line/rich-menus/{richMenu}/clear',     [LineRichMenuController::class, 'clearDefault'])->whereNumber('richMenu')->name('rich-menus.clear');
        Route::delete('/line/rich-menus/{richMenu}',         [LineRichMenuController::class, 'destroy'])->whereNumber('richMenu')->name('rich-menus.destroy');

        // Insights — quota, followers, delivery, demographics (read-only).
        Route::get('/line/insights',         [LineInsightsController::class, 'index'])->name('insights');
    });

// ── Broadcasts — LINE's own send-to-audience pipeline (plan:line_broadcasts). ──
Route::middleware(['web', 'auth', 'plan:line_broadcasts'])
    ->name('user.line.broadcasts.')
    ->group(function () {
        Route::get('/line/broadcasts',                    [LineBroadcastController::class, 'index'])->name('index');
        Route::get('/line/broadcasts/create',             [LineBroadcastController::class, 'create'])->name('create');
        Route::post('/line/broadcasts',                   [LineBroadcastController::class, 'store'])->name('store');
        Route::post('/line/broadcasts/{broadcast}/start', [LineBroadcastController::class, 'start'])->whereNumber('broadcast')->name('start');
        Route::post('/line/broadcasts/{broadcast}/pause', [LineBroadcastController::class, 'pause'])->whereNumber('broadcast')->name('pause');
        Route::post('/line/broadcasts/{broadcast}/retry', [LineBroadcastController::class, 'retry'])->whereNumber('broadcast')->name('retry');
        Route::post('/line/broadcasts/{broadcast}/batch', [LineBroadcastController::class, 'sendBatch'])->whereNumber('broadcast')->name('batch');
        Route::delete('/line/broadcasts/{broadcast}',     [LineBroadcastController::class, 'destroy'])->whereNumber('broadcast')->name('destroy');
    });

// ── Inbound push — one signed callback URL per channel. Raw: no session/CSRF. ──
Route::middleware(['api', 'throttle:240,1'])
    ->post('/api/line/inbound/{token}', [LineWebhookController::class, 'ingest'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('line.inbound');

// ── Node flow-engine callbacks — X-Node-Token guarded, CSRF-exempt (see
//    bootstrap/app.php). The long-lived Node runtime mirrors flow messages into
//    the inbox (flow-log) and resolves AI / webhook nodes (flow-node). ──
Route::middleware('api')->group(function () {
    Route::post('/api/line/flow-log',  [LineFlowNodeController::class, 'log']);
    Route::post('/api/line/flow-node', [LineFlowNodeController::class, 'node']);
});
