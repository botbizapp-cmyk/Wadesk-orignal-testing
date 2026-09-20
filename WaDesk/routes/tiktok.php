<?php

/*
|--------------------------------------------------------------------------
| TikTok channel routes — part of WaDesk core (Phase 1: Connect)
|--------------------------------------------------------------------------
|
| The user connects a TikTok account once (Login Kit OAuth). Each connected
| account stores its own access + refresh token (encrypted, 24h access token
| refreshed by TiktokTokenRefreshSweeper). Phase 1 ships Connect + account
| management; Insights (Display API) and Posting (Content Posting API) land in
| Phases 2–3. Loaded from bootstrap/app.php in the same slot as routes/facebook.php.
|
*/

use App\Http\Controllers\Tiktok\TiktokAccountsController;
use App\Http\Controllers\Tiktok\TiktokConnectController;
use App\Http\Controllers\Tiktok\TiktokFlowNodeController;
use App\Http\Controllers\Tiktok\TiktokInsightsController;
use App\Http\Controllers\Tiktok\TiktokPostController;
use App\Http\Controllers\Tiktok\TiktokShopController;
use App\Http\Controllers\Tiktok\TiktokWebhookController;
use Illuminate\Support\Facades\Route;

// ── Account connect (Login Kit OAuth) — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_tiktok'])
    ->name('user.tiktok.')
    ->group(function () {
        Route::get('/tiktok/accounts',        [TiktokAccountsController::class, 'index'])->name('accounts');
        Route::get('/tiktok/insights',        [TiktokInsightsController::class, 'index'])->name('insights');

        // Posting (Content Posting API — Upload/Inbox path). Sub-gated: needs
        // access_tiktok (this group) AND tiktok_posts, so a plan can grant the
        // TikTok channel without granting posting.
        Route::middleware('plan:tiktok_posts')->group(function () {
            Route::get('/tiktok/posts',           [TiktokPostController::class, 'index'])->name('posts');
            Route::get('/tiktok/posts/create',    [TiktokPostController::class, 'create'])->name('posts.create');
            Route::post('/tiktok/posts',          [TiktokPostController::class, 'store'])->name('posts.store');
            Route::post('/tiktok/posts/{id}/status', [TiktokPostController::class, 'status'])->whereNumber('id')->name('posts.status');
            Route::delete('/tiktok/posts/{id}',   [TiktokPostController::class, 'destroy'])->whereNumber('id')->name('posts.destroy');
        });

        // TikTok Shop (App C — Partner API). Lives with the store integrations.
        Route::get('/tiktok-shop',            [TiktokShopController::class, 'index'])->name('shop');
        Route::get('/tiktok-shop/connect',    [TiktokShopController::class, 'connect'])->name('shop.connect');
        Route::get('/tiktok-shop/callback',   [TiktokShopController::class, 'callback'])->name('shop.callback');
        Route::delete('/tiktok-shop/{id}',    [TiktokShopController::class, 'disconnect'])->whereNumber('id')->name('shop.disconnect');
        Route::get('/tiktok/connect',         [TiktokConnectController::class, 'start'])->name('connect');
        Route::get('/tiktok/callback',        [TiktokConnectController::class, 'callback'])->name('callback');
        Route::post('/tiktok/{id}/refresh',   [TiktokConnectController::class, 'refresh'])->whereNumber('id')->name('refresh');
        Route::delete('/tiktok/{id}',         [TiktokConnectController::class, 'disconnect'])->whereNumber('id')->name('disconnect');

        // Business Messaging (DM inbox) — partner-gated. Store the business
        // credentials + region so the DM lane can open once TikTok approves the
        // Messaging-Partner app (webhook is registered on the app in the portal).
        Route::middleware('plan:tiktok_inbox')->group(function () {
            Route::post('/tiktok/{id}/inbox',     [TiktokConnectController::class, 'connectInbox'])->whereNumber('id')->name('inbox.connect');
            Route::delete('/tiktok/{id}/inbox',   [TiktokConnectController::class, 'disconnectInbox'])->whereNumber('id')->name('inbox.disconnect');
        });
    });

// ── TikTok webhook — signed events (TikTok-Signature). Raw: no session/CSRF. ──
Route::post('/webhooks/tiktok', [TiktokWebhookController::class, 'handle']);
// Business Messaging inbound (partner-gated, separate business app secret).
Route::post('/webhooks/tiktok/business', [TiktokWebhookController::class, 'business']);
// Ads "New Lead" webhook — lead-gen Instant Form submissions → Contacts.
Route::post('/webhooks/tiktok/leads', [TiktokWebhookController::class, 'leads']);

// ── Node → Laravel flow-engine bridge (raw, X-Node-Token guarded, no session). ──
// The ported TikTok flow engine (node/services/tiktokFlowService.js) runs every
// send itself and calls back here only to mirror a flow message into the inbox
// (flow-log) and to resolve "smart" nodes — AI / webhook (flow-node).
Route::post('/api/tiktok/flow-log',  [TiktokFlowNodeController::class, 'log']);
Route::post('/api/tiktok/flow-node', [TiktokFlowNodeController::class, 'node']);

// ── Health ping ──
Route::middleware('web')->get('/tiktok/_health', function () {
    return response()->json(['ok' => true, 'channel' => 'tiktok', 'version' => '1.0.0']);
});
