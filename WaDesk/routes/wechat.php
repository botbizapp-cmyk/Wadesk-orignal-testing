<?php

/*
|--------------------------------------------------------------------------
| WeChat channel routes — WaDesk core (Official Account). Phase 1.
|--------------------------------------------------------------------------
|
| A workspace pastes AppID + AppSecret + Token (+ optional EncodingAESKey); each
| channel has its own webhook at /api/wechat/inbound/{token} (raw, signature-
| verified, no session). GET = URL-validation handshake, POST = inbound XML.
| Loaded from bootstrap/app.php in the same slot as line/telegram.
|
*/

use App\Http\Controllers\WeChat\WeChatBroadcastController;
use App\Http\Controllers\WeChat\WeChatConnectController;
use App\Http\Controllers\WeChat\WeChatFlowNodeController;
use App\Http\Controllers\WeChat\WeChatInsightsController;
use App\Http\Controllers\WeChat\WeChatMenuController;
use App\Http\Controllers\WeChat\WeChatWebhookController;
use Illuminate\Support\Facades\Route;

// ── Channel connect + manage — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_wechat'])
    ->name('user.wechat.')
    ->group(function () {
        Route::get('/wechat',                  [WeChatConnectController::class, 'index'])->name('index');
        Route::post('/wechat/connect',         [WeChatConnectController::class, 'connect'])->name('connect');
        Route::post('/wechat/{channel}/retry', [WeChatConnectController::class, 'retry'])->whereNumber('channel')->name('retry');
        Route::post('/wechat/{channel}/toggle',[WeChatConnectController::class, 'toggle'])->whereNumber('channel')->name('toggle');
        Route::delete('/wechat/{channel}',     [WeChatConnectController::class, 'destroy'])->whereNumber('channel')->name('destroy');

        // Custom menu (自定义菜单) — the persistent bottom bar.
        Route::get('/wechat/menu',        [WeChatMenuController::class, 'index'])->name('menu');
        Route::post('/wechat/menu',       [WeChatMenuController::class, 'save'])->name('menu.save');
        Route::post('/wechat/menu/clear', [WeChatMenuController::class, 'clear'])->name('menu.clear');

        // Insights — follower analytics (read-only).
        Route::get('/wechat/insights',    [WeChatInsightsController::class, 'index'])->name('insights');
    });

// ── Broadcasts — WeChat mass-send (plan:wechat_broadcasts). One-shot send. ──
Route::middleware(['web', 'auth', 'plan:wechat_broadcasts'])
    ->name('user.wechat.broadcasts.')
    ->group(function () {
        Route::get('/wechat/broadcasts',                    [WeChatBroadcastController::class, 'index'])->name('index');
        Route::get('/wechat/broadcasts/create',             [WeChatBroadcastController::class, 'create'])->name('create');
        Route::post('/wechat/broadcasts',                   [WeChatBroadcastController::class, 'store'])->name('store');
        Route::post('/wechat/broadcasts/{broadcast}/send',  [WeChatBroadcastController::class, 'send'])->whereNumber('broadcast')->name('send');
        Route::delete('/wechat/broadcasts/{broadcast}',     [WeChatBroadcastController::class, 'destroy'])->whereNumber('broadcast')->name('destroy');
    });

// ── Inbound — one signed callback URL per channel. GET verifies, POST ingests.
//    Raw: no session/CSRF (CSRF-exempt in bootstrap/app.php). ──
Route::middleware(['api', 'throttle:240,1'])
    ->match(['get', 'post'], '/api/wechat/inbound/{token}', [WeChatWebhookController::class, 'handle'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('wechat.inbound');

// ── Node flow-engine callbacks — X-Node-Token guarded, CSRF-exempt. The Node
//    runtime delegates every SEND back here (flow-send, which uses the managed
//    token + mirrors to the inbox) and resolves AI / webhook nodes (flow-node). ──
Route::middleware('api')->group(function () {
    Route::post('/api/wechat/flow-send', [WeChatFlowNodeController::class, 'send']);
    Route::post('/api/wechat/flow-node', [WeChatFlowNodeController::class, 'node']);
});
