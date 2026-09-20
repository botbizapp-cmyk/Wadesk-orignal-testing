<?php

/*
|--------------------------------------------------------------------------
| Telegram channel routes — part of WaDesk core (merged from the standalone
| WaDesk Telegram integration, adapted to our core-channel pattern).
|--------------------------------------------------------------------------
|
| Bot API (plain HTTPS) — a workspace pastes a @BotFather token; each bot has
| its own webhook at /api/telegram/inbound/{token} (raw, secret-verified, no
| session). Loaded from bootstrap/app.php in the same slot as facebook/tiktok.
|
*/

use App\Http\Controllers\Telegram\TelegramAccountController;
use App\Http\Controllers\Telegram\TelegramBroadcastController;
use App\Http\Controllers\Telegram\TelegramConnectController;
use App\Http\Controllers\Telegram\TelegramFlowNodeController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// ── Bot connect + manage — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_telegram'])
    ->name('user.telegram.')
    ->group(function () {
        Route::get('/telegram',                 [TelegramConnectController::class, 'index'])->name('index');
        Route::post('/telegram/connect',        [TelegramConnectController::class, 'connect'])->name('connect');

        // Broadcasts — Telegram ships its own (no phone audience for core campaigns).
        // Sub-gated: needs access_telegram (this group) AND telegram_broadcasts, so a
        // plan can grant the Telegram channel without granting broadcasts.
        Route::middleware('plan:telegram_broadcasts')->group(function () {
            Route::get('/telegram/broadcasts',                    [TelegramBroadcastController::class, 'index'])->name('broadcasts');
            Route::get('/telegram/broadcasts/create',             [TelegramBroadcastController::class, 'create'])->name('broadcasts.create');
            Route::post('/telegram/broadcasts',                   [TelegramBroadcastController::class, 'store'])->name('broadcasts.store');
            Route::post('/telegram/broadcasts/{broadcast}/start', [TelegramBroadcastController::class, 'start'])->whereNumber('broadcast')->name('broadcasts.start');
            Route::post('/telegram/broadcasts/{broadcast}/pause', [TelegramBroadcastController::class, 'pause'])->whereNumber('broadcast')->name('broadcasts.pause');
            Route::post('/telegram/broadcasts/{broadcast}/retry', [TelegramBroadcastController::class, 'retry'])->whereNumber('broadcast')->name('broadcasts.retry');
            Route::post('/telegram/broadcasts/{broadcast}/batch', [TelegramBroadcastController::class, 'sendBatch'])->whereNumber('broadcast')->name('broadcasts.batch');
            Route::delete('/telegram/broadcasts/{broadcast}',     [TelegramBroadcastController::class, 'destroy'])->whereNumber('broadcast')->name('broadcasts.destroy');
        });
        Route::post('/telegram/{bot}/payments', [TelegramConnectController::class, 'savePayments'])->whereNumber('bot')->name('payments');
        Route::post('/telegram/{bot}/retry',    [TelegramConnectController::class, 'retry'])->whereNumber('bot')->name('retry');
        Route::post('/telegram/{bot}/toggle',   [TelegramConnectController::class, 'toggle'])->whereNumber('bot')->name('toggle');
        Route::delete('/telegram/{bot}',        [TelegramConnectController::class, 'destroy'])->whereNumber('bot')->name('destroy');

        // MTProto account login + @BotFather bot creation (in-app). Optional
        // convenience — the paste-token flow above is the primary path.
        Route::post('/telegram/account/send-code',     [TelegramAccountController::class, 'sendCode'])->name('account.send-code');
        Route::post('/telegram/account/sign-in',       [TelegramAccountController::class, 'signIn'])->name('account.sign-in');
        Route::post('/telegram/account/qr-start',      [TelegramAccountController::class, 'qrStart'])->middleware('throttle:20,1')->name('account.qr-start');
        Route::post('/telegram/account/qr-poll',       [TelegramAccountController::class, 'qrPoll'])->middleware('throttle:120,1')->name('account.qr-poll');
        Route::post('/telegram/account/cancel',        [TelegramAccountController::class, 'cancel'])->name('account.cancel');
        Route::post('/telegram/account/check',         [TelegramAccountController::class, 'check'])->name('account.check');
        Route::delete('/telegram/account/{account?}',  [TelegramAccountController::class, 'disconnect'])->name('account.disconnect');
        Route::post('/telegram/account/create-bot',    [TelegramAccountController::class, 'createBot'])->middleware('throttle:10,1')->name('account.create-bot');
        Route::post('/telegram/account/check-username',[TelegramAccountController::class, 'checkUsername'])->middleware('throttle:20,1')->name('account.check-username');
    });

// ── Inbound push — one signed callback URL per bot. Raw: no session/CSRF. ──
Route::middleware(['api', 'throttle:120,1'])
    ->post('/api/telegram/inbound/{token}', [TelegramWebhookController::class, 'ingest'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('telegram.inbound');

// ── Node → Laravel flow-engine bridge (raw, X-Node-Token guarded, no session). ──
Route::post('/api/telegram/flow-log',  [TelegramFlowNodeController::class, 'log']);
Route::post('/api/telegram/flow-node', [TelegramFlowNodeController::class, 'node']);

// ── Health ping ──
Route::middleware('web')->get('/telegram/_health', fn () => response()->json(['ok' => true, 'channel' => 'telegram', 'version' => '1.0.0']));
