<?php

/*
|--------------------------------------------------------------------------
| Facebook Pages channel routes — part of WaDesk core
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php (same "before the workspace-slug catch-all"
| slot as routes/bsp.php). The user connects their Facebook account once
| (/facebook/connect — embedded signup, OAuth redirect, or manual token) and
| every Page it manages is stored; Meta calls /webhooks/facebook for
| feed/comment/message events. The platform admin enables the channel with a
| single toggle on /admin/settings/wadesk-message (it reuses the WhatsApp Meta
| app by default — no separate App Secret / config id / callback url).
|
*/

use App\Http\Controllers\Facebook\FacebookComposerAiController;
use App\Http\Controllers\Facebook\FacebookFlowNodeController;
use App\Http\Controllers\Facebook\FacebookBroadcastController;
use App\Http\Controllers\Facebook\FacebookInsightsController;
use App\Http\Controllers\Facebook\FacebookPostController;
use App\Http\Controllers\Facebook\FacebookSetupController;
use App\Http\Controllers\Facebook\FacebookWebhookController;
use App\Http\Controllers\FacebookConnectController;
use Illuminate\Support\Facades\Route;

// ── Account connect (native OAuth) — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_facebook'])->group(function () {
    Route::get('/facebook/connect',           [FacebookConnectController::class, 'start'])->name('facebook.connect');
    Route::get('/facebook/callback',          [FacebookConnectController::class, 'callback'])->name('facebook.callback');
    // Embedded signup (Facebook JS SDK popup) → posts the code.
    Route::post('/facebook/connect/embedded', [FacebookConnectController::class, 'connectEmbedded'])->name('facebook.connect.embedded');
    // Manual — paste a Page access token.
    Route::post('/facebook/connect/manual',   [FacebookConnectController::class, 'connectManual'])->name('facebook.connect.manual');
    Route::post('/facebook/{id}/refresh',     [FacebookConnectController::class, 'refresh'])->whereNumber('id')->name('facebook.refresh');
    Route::post('/facebook/{id}/resubscribe', [FacebookConnectController::class, 'resubscribe'])->whereNumber('id')->name('facebook.resubscribe');
    Route::delete('/facebook/{id}',           [FacebookConnectController::class, 'disconnect'])->whereNumber('id')->name('facebook.disconnect');
});

// ── Facebook Messenger Broadcasts — parity with Telegram broadcasts. ──
Route::middleware(['web', 'auth', 'plan:access_facebook'])
    ->name('user.facebook.')
    ->group(function () {
        Route::get('/facebook/broadcasts',                    [FacebookBroadcastController::class, 'index'])->name('broadcasts');
        Route::post('/facebook/broadcasts',                   [FacebookBroadcastController::class, 'store'])->name('broadcasts.store');
        Route::post('/facebook/broadcasts/{broadcast}/start', [FacebookBroadcastController::class, 'start'])->whereNumber('broadcast')->name('broadcasts.start');
        Route::post('/facebook/broadcasts/{broadcast}/pause', [FacebookBroadcastController::class, 'pause'])->whereNumber('broadcast')->name('broadcasts.pause');
        Route::post('/facebook/broadcasts/{broadcast}/retry', [FacebookBroadcastController::class, 'retry'])->whereNumber('broadcast')->name('broadcasts.retry');
        Route::post('/facebook/broadcasts/{broadcast}/batch', [FacebookBroadcastController::class, 'sendBatch'])->whereNumber('broadcast')->name('broadcasts.batch');
        Route::delete('/facebook/broadcasts/{broadcast}',     [FacebookBroadcastController::class, 'destroy'])->whereNumber('broadcast')->name('broadcasts.destroy');
    });

// ── Facebook Posts — composer + scheduler. Plan-gated (facebook_posts). ──
Route::middleware(['web', 'auth', 'plan:facebook_posts'])
    ->name('user.facebook.')
    ->group(function () {
        Route::get('/facebook/posts',                 [FacebookPostController::class, 'index'])->name('posts');
        Route::get('/facebook/posts/create',          [FacebookPostController::class, 'create'])->name('posts.create');
        Route::get('/facebook/my-posts',              [FacebookPostController::class, 'grid'])->name('my-posts');
        Route::post('/facebook/posts',                [FacebookPostController::class, 'store'])->name('posts.store');
        Route::post('/facebook/posts/{id}/publish',   [FacebookPostController::class, 'publishNow'])->whereNumber('id')->name('posts.publish');
        Route::delete('/facebook/posts/{id}',         [FacebookPostController::class, 'destroy'])->whereNumber('id')->name('posts.destroy');

        // AI composer tools (JSON) — mirror /instagram/posts/ai/*. Same-origin
        // CSRF + auth; the AI-feature plan gate is enforced inside the controller.
        Route::post('/facebook/posts/ai/caption',   [FacebookComposerAiController::class, 'caption'])->name('posts.ai.caption');
        Route::post('/facebook/posts/ai/repurpose', [FacebookComposerAiController::class, 'repurpose'])->name('posts.ai.repurpose');
        Route::post('/facebook/posts/ai/review',    [FacebookComposerAiController::class, 'review'])->name('posts.ai.review');
        Route::post('/facebook/posts/ai/best-time', [FacebookComposerAiController::class, 'bestTime'])->name('posts.ai.besttime');
        Route::post('/facebook/posts/ai/image',     [FacebookComposerAiController::class, 'image'])->name('posts.ai.image');

        Route::get('/facebook/insights',              [FacebookInsightsController::class, 'index'])->name('insights');
    });

// ── Messenger Profile setup — Get Started, Persistent Menu, Ice Breakers,
//    Greeting for a connected Page. Channel-level config, so gated on the same
//    plan feature as connecting the account (access_facebook). ──
Route::middleware(['web', 'auth', 'plan:access_facebook'])
    ->name('user.facebook.')
    ->group(function () {
        Route::get('/facebook/setup',                    [FacebookSetupController::class, 'index'])->name('setup');
        Route::post('/facebook/setup/get-started',       [FacebookSetupController::class, 'updateGetStarted'])->name('setup.getstarted');
        Route::delete('/facebook/setup/get-started',     [FacebookSetupController::class, 'deleteGetStarted'])->name('setup.getstarted.delete');
        Route::post('/facebook/setup/greeting',          [FacebookSetupController::class, 'updateGreeting'])->name('setup.greeting');
        Route::delete('/facebook/setup/greeting',        [FacebookSetupController::class, 'deleteGreeting'])->name('setup.greeting.delete');
        Route::post('/facebook/setup/menu',              [FacebookSetupController::class, 'updateMenu'])->name('setup.menu');
        Route::delete('/facebook/setup/menu',            [FacebookSetupController::class, 'deleteMenu'])->name('setup.menu.delete');
        Route::post('/facebook/setup/ice-breakers',      [FacebookSetupController::class, 'updateIceBreakers'])->name('setup.icebreakers');
        Route::delete('/facebook/setup/ice-breakers',    [FacebookSetupController::class, 'deleteIceBreakers'])->name('setup.icebreakers.delete');
    });

// ── Meta webhook — verify (GET) + events (POST). Raw: no session/CSRF. ──
Route::get('/webhooks/facebook',  [FacebookWebhookController::class, 'verify']);
Route::post('/webhooks/facebook', [FacebookWebhookController::class, 'handle']);

// ── Node → Laravel flow-engine bridge (raw, X-Node-Token guarded, no session). ──
// The ported Facebook flow engine (node/services/facebookFlowService.js) runs
// every send itself and calls back here only to mirror a flow message into the
// inbox (flow-log) and to resolve "smart" nodes / cross-channel handoff
// (flow-node). Auth is the shared X-Node-Token, checked inside the controller —
// so these MUST be CSRF-exempt (see bootstrap/app.php webhooks/facebook + these).
Route::post('/api/facebook/flow-log',  [FacebookFlowNodeController::class, 'log']);
Route::post('/api/facebook/flow-node', [FacebookFlowNodeController::class, 'node']);

// Admin settings for Facebook live on the existing WaDesk Message settings page
// (/admin/settings/wadesk-message) — it reuses the same Meta app, so there is
// just a single "Enable Facebook Pages" toggle there, saved via
// AdminPagesController::settingsProvidersUpdate. No separate page.

// ── Health ping ──
Route::middleware('web')->get('/facebook/_health', function () {
    return response()->json(['ok' => true, 'channel' => 'facebook', 'version' => '1.0.0']);
});
