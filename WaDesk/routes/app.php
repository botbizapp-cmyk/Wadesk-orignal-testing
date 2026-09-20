<?php

use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\AiAgentController;
use App\Http\Controllers\Api\App\AutoreplyController;
use App\Http\Controllers\Api\App\BillingController;
use App\Http\Controllers\Api\App\CampaignController;
use App\Http\Controllers\Api\App\ChatController;
use App\Http\Controllers\Api\App\ContactGroupController;
use App\Http\Controllers\Api\App\ContentController;
use App\Http\Controllers\Api\App\DeviceController;
use App\Http\Controllers\Api\App\GroupController;
use App\Http\Controllers\Api\App\InboxController;
use App\Http\Controllers\Api\App\MobilePaymentController;
use App\Http\Controllers\Api\App\PasswordController;
use App\Http\Controllers\Api\App\ProfileController;
use App\Http\Controllers\Api\App\QueueController;
use App\Http\Controllers\Api\App\QuickMessageController;
use App\Http\Controllers\Api\App\TemplateController;
use App\Http\Controllers\Api\App\TwoFactorController;
use App\Http\Controllers\Api\App\WorkspaceController;
use App\Http\Controllers\Api\App\PaymentGatewayController;
use App\Http\Controllers\Api\App\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile App API   (mounted at /api/app, `api` group — see bootstrap/app.php)
|--------------------------------------------------------------------------
| Every endpoint the Flutter app consumes. Response shapes match the app;
| the Flutter ApiConfig.baseUrl must be ".../api/app". Paths are written
| WITHOUT the /api/app prefix.
*/

/* ───────────────────────── PUBLIC (no token) ───────────────────────── */

// Payment return/cancel landing pages. The gateway redirects the in-app
// WebView here (NO Sanctum token), and the Flutter WebView intercepts the URL
// substring (/paypal/return, /paypal/cancel, /paystack/callback, /paystack/cancel)
// to close itself. Must be public + return a tiny HTML page so the nav resolves.
$payClose = fn (string $msg) => response(
    '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $msg . '</title></head>'
    . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:40px;color:#0B1F1C">'
    . '<h3>' . $msg . '</h3><p>You may close this window.</p></body></html>',
    200, ['Content-Type' => 'text/html']
);
Route::get('/paypal/return',      fn () => $payClose('Payment complete'));
Route::get('/paypal/cancel',      fn () => $payClose('Payment cancelled'));
Route::get('/paystack/callback',  fn () => $payClose('Payment complete'));
Route::get('/paystack/cancel',    fn () => $payClose('Payment cancelled'));

// Auth — throttled to blunt credential/OTP brute-force + enumeration (#27).
// 10 requests/min/IP is generous for a real user, hostile for a script.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/login',                [AuthController::class, 'login']);
    Route::post('/register',             [AuthController::class, 'register']);
    Route::post('/auth/social/callback', [AuthController::class, 'socialCallback']);
    Route::post('/auth/verify-passcode', [AuthController::class, 'verifyPasscode']);
    Route::post('/forgot',               [PasswordController::class, 'sendOtp']);
    Route::post('/verify-otp',           [PasswordController::class, 'verifyOtp']);
    Route::post('/reset',                [PasswordController::class, 'resetPassword']);
    // Two-factor (WhatsApp OTP). enable/disable are password-gated; send/verify
    // complete a login when two_factor:1 comes back. (Replaces the old PIN passcode.)
    Route::post('/2fa/enable',           [TwoFactorController::class, 'enableTwoFactor']);
    Route::post('/2fa/send',             [TwoFactorController::class, 'sendOtp']);
    Route::post('/2fa/verify',           [TwoFactorController::class, 'verifyOtp']);
    Route::post('/2fa/disable',          [TwoFactorController::class, 'disableTwoFactor']);
});
Route::get('/countries',             [ProfileController::class, 'countries']);

// Marketing / CMS content (no login needed)
Route::get('/pages',  [ContentController::class, 'pages']);
Route::get('/blog',   [ContentController::class, 'blog']);
Route::get('/blog/{key}', [ContentController::class, 'blogShow']);
Route::get('/faq',    [ContentController::class, 'faq']);
Route::get('/banner', [ContentController::class, 'banner']);

/* ─────────────────────── AUTHENTICATED (Bearer) ────────────────────── */

// `app.workspace` runs after auth:sanctum: it resolves the X-Workspace-Id /
// X-Device-Id headers into the request's current_workspace_id so every
// workspace-scoped endpoint below is scoped to the workspace the app selected.
Route::middleware(['auth:sanctum', 'app.workspace'])->group(function () {

    // Workspaces — after login the app lists the user's workspaces (each with
    // its plan + devices), then sends X-Workspace-Id on subsequent calls.
    // The app is HEADER-DRIVEN: it remembers the selected workspace locally and
    // sends X-Workspace-Id on each call. There is deliberately NO server "switch"
    // — that would write users.current_workspace_id, the column the WEB reads, and
    // switch the user's web session too. Per-request header scoping never persists.
    Route::get ('/workspaces',                        [WorkspaceController::class, 'index']);
    Route::post('/workspaces',                        [WorkspaceController::class, 'store']);
    Route::get ('/workspaces/{id}',                   [WorkspaceController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/workspaces/{id}', [WorkspaceController::class, 'update'])->whereNumber('id');

    // Profile
    Route::get('/user',             [ProfileController::class, 'me']);
    Route::post('/user-profile',    [ProfileController::class, 'updateProfile']);
    Route::post('/change-password', [PasswordController::class, 'changePassword']);
    Route::post('/logout',          [AuthController::class, 'logout']);

    // Devices (B2)
    Route::get   ('/get-devices',              [DeviceController::class, 'getDevices']);
    Route::get   ('/device-status/{deviceId}', [DeviceController::class, 'deviceStatus']);
    // Both forms: /device-contacts (device from X-Device-Id header) AND the
    // legacy /device-contacts/{id} (explicit id in path).
    Route::get   ('/device-contacts/{id?}',    [DeviceController::class, 'deviceContacts'])->whereNumber('id');
    // Pair / disconnect / remove a Baileys device. The app polls
    // /devices/{id}/qr or /devices/{id}/pair-code while the QR or 8-digit
    // pairing code is on screen, and /device-status/{id} for live state.
    Route::post  ('/devices',                  [DeviceController::class, 'store']);
    Route::post  ('/devices/connect-twilio',   [DeviceController::class, 'connectTwilio']);
    Route::post  ('/devices/connect-waba',     [DeviceController::class, 'connectWaba']);
    Route::get   ('/devices/{id}/qr',          [DeviceController::class, 'qr'])->whereNumber('id');
    Route::get   ('/devices/{id}/pair-code',   [DeviceController::class, 'pairCode'])->whereNumber('id');
    Route::post  ('/devices/{id}/disconnect',  [DeviceController::class, 'disconnect'])->whereNumber('id');
    Route::delete('/devices/{id}',             [DeviceController::class, 'destroy'])->whereNumber('id');

    // Templates (B3)
    Route::get('/get-templates',          [TemplateController::class, 'index']);
    Route::get('/get-templates-category', [TemplateController::class, 'categories']);
    Route::post('/templates-store',       [TemplateController::class, 'store']);
    Route::post('/templates/delete',      [TemplateController::class, 'destroy']);
    Route::get('/templates/{id}',         [TemplateController::class, 'show'])->whereNumber('id');
    Route::put('/templates/{id}',         [TemplateController::class, 'update'])->whereNumber('id');
    Route::delete('/templates/{id}',      [TemplateController::class, 'destroy'])->whereNumber('id');

    // Contacts / groups (B5)
    Route::get   ('/get-contacts',                [ContactGroupController::class, 'getContacts']);
    Route::get   ('/get-contact-groups',          [ContactGroupController::class, 'index']);
    Route::post  ('/contact-groups',              [ContactGroupController::class, 'store']);
    Route::post  ('/contact-groups/bulk-delete',  [ContactGroupController::class, 'bulkDelete']);
    Route::delete('/contact-groups/{groupId}',    [ContactGroupController::class, 'destroy'])->whereNumber('groupId');
    // Single-contact CRUD — add, fetch, edit, delete one contact at a
    // time without going through the group payload. Optional group_ids[]
    // on create/update attaches to one or more contact groups.
    Route::post  ('/contacts',                    [ContactGroupController::class, 'storeContact']);
    Route::get   ('/contacts/{id}',               [ContactGroupController::class, 'showContact'])->whereNumber('id');
    Route::patch ('/contacts/{id}',               [ContactGroupController::class, 'updateContact'])->whereNumber('id');
    Route::delete('/contacts/{id}',               [ContactGroupController::class, 'destroyContact'])->whereNumber('id');

    // Quick message + chats (B4)
    Route::post('/send-quick-message',           [QuickMessageController::class, 'sendQuickMessage']);
    Route::get('/quick-message/chats',           [QuickMessageController::class, 'getAllChats']);
    Route::get('/quick-message/chat/{toNumber}', [QuickMessageController::class, 'getChatMessages']);
    Route::delete('/quick-message/chat/{toNumber}', [QuickMessageController::class, 'deleteChat']);
    Route::post('/quick-message/archive',        [QuickMessageController::class, 'archive']);

    // Queues / bulk (B4)
    Route::post('/create-queue',           [QueueController::class, 'createMessageQueue']);
    Route::get('/get-queues',              [QueueController::class, 'getQueues']);
    Route::get('/get-queue/{queueId}',     [QueueController::class, 'getQueueMessages'])->whereNumber('queueId');
    Route::get('/delete-queues',           [QueueController::class, 'deleteQueue']);
    Route::post('/start-sending',          [QueueController::class, 'startSelectedMessageQueue']);
    Route::post('/send-to-existing-queue', [QueueController::class, 'sendToExistingQueue']);
    Route::post('/update-queue-name',      [QueueController::class, 'updateQueueName']);
    Route::post('/archive-queue',          [QueueController::class, 'archiveQueue']);
    Route::get('/all-archive-queue',       [QueueController::class, 'all_archive_queue']);
    Route::post('/queue/toggle-pin',       [QueueController::class, 'togglePinQueue']);
    Route::get('/queues/pinned',           [QueueController::class, 'getPinnedQueues']);
    Route::get('/message-status/{queueId}', [QueueController::class, 'messageStatus'])->whereNumber('queueId');
    Route::get('/get-contact-csv',         [QueueController::class, 'getContactCsv']);
    Route::post('/schedule-message',       [QueueController::class, 'scheduleMessage']);

    // Campaigns (B6)
    Route::get('/campaigns/statistics', [CampaignController::class, 'statistics']);
    Route::delete('/campaigns/bulk',    [CampaignController::class, 'bulkDestroy']);
    Route::get('/campaigns',            [CampaignController::class, 'index']);
    Route::post('/campaigns',           [CampaignController::class, 'store']);
    Route::get('/campaigns/{id}',       [CampaignController::class, 'show'])->whereNumber('id');
    Route::post('/campaigns/{id}/stop', [CampaignController::class, 'stop'])->whereNumber('id');
    Route::delete('/campaigns/{id}',    [CampaignController::class, 'destroy'])->whereNumber('id');

    // Autoreplies + flows (B6)
    Route::get('/autoreplies',                          [AutoreplyController::class, 'index']);
    Route::post('/autoreplies',                         [AutoreplyController::class, 'store']);
    Route::get('/getflows',                             [AutoreplyController::class, 'getflows']);
    Route::get('/autoreplies/{id}',                     [AutoreplyController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'post'], '/autoreplies/{id}',  [AutoreplyController::class, 'update'])->whereNumber('id');
    Route::delete('/autoreplies/{id}',                  [AutoreplyController::class, 'destroy'])->whereNumber('id');

    // Billing (B7)
    Route::get('/plans',                        [BillingController::class, 'plans']);
    Route::get('/order-data',                   [BillingController::class, 'orderData']);
    Route::get('/orders/history',               [BillingController::class, 'orderHistory']);
    Route::get('/orders/invoice/{id}/download', [BillingController::class, 'downloadInvoice'])->whereNumber('id');
    Route::get('/payment-gateway-settings',     [BillingController::class, 'paymentGatewaySettings']);
    Route::post('/create-order',                [BillingController::class, 'createOrder']);
    Route::get('/coupon/available',             [BillingController::class, 'availableCoupons']);
    Route::get('/packages/{id}',                [BillingController::class, 'packageDetails'])->whereNumber('id');

    // Server-side payment operations (CodeCanyon security fix). The app never
    // holds a secret key — it calls these, which do all secret-key work on the
    // server and return only client secrets / order ids / public keys.
    Route::post('/stripe/create-payment-intent', [MobilePaymentController::class, 'stripeCreatePaymentIntent']);
    Route::post('/razorpay/create-order',        [MobilePaymentController::class, 'razorpayCreateOrder']);
    Route::post('/razorpay/verify',              [MobilePaymentController::class, 'razorpayVerify']);
    Route::post('/paypal/create-order',          [MobilePaymentController::class, 'paypalCreateOrder']);
    Route::post('/paypal/capture-order',         [MobilePaymentController::class, 'paypalCaptureOrder']);
    Route::post('/paystack/initialize',          [MobilePaymentController::class, 'paystackInitialize']);
    Route::post('/paystack/verify',              [MobilePaymentController::class, 'paystackVerify']);

    // AI Agents — the same AI agents the web Team Inbox manages (list / create /
    // update / delete), workspace-scoped via the X-Workspace-Id header.
    // /models returns the providers + models this workspace can ACTUALLY use
    // (admin-enabled keys + the workspace's own BYOK keys) so the app shows a
    // picker instead of hardcoding model strings. Declared BEFORE /{id}.
    Route::get   ('/ai-agents/models', [AiAgentController::class, 'models']);
    Route::get   ('/ai-agents',        [AiAgentController::class, 'index']);
    Route::post  ('/ai-agents',        [AiAgentController::class, 'store']);
    Route::patch ('/ai-agents/{id}',   [AiAgentController::class, 'update'])->whereNumber('id');
    Route::delete('/ai-agents/{id}',   [AiAgentController::class, 'destroy'])->whereNumber('id');

    // ONE-CALL inbox bundle — returns chats + groups + contact-groups + queues
    // + pinned + archived (queues & chats) in a single response, so the chat-list
    // screen doesn't fan out to 7 endpoints on every open. Each slice is the
    // same JSON its own endpoint returns.
    Route::get   ('/inbox',                                       [InboxController::class, 'bundle']);

    // Push registration (FCM/APNs) — wakes the app on inbound when it's
    // backgrounded/killed. Register on login/token-refresh, unregister on logout.
    Route::post  ('/device-tokens/register',   [\App\Http\Controllers\Api\App\DeviceTokenController::class, 'register']);
    Route::post  ('/device-tokens/unregister', [\App\Http\Controllers\Api\App\DeviceTokenController::class, 'unregister']);

    // Real-time broadcasting auth for the mobile Pusher client. The web
    // /broadcasting/auth is SESSION-based; this token-authed variant lets the
    // app subscribe to the SAME private channels — private-workspace.{id}.inbox
    // (live chat list) + private-conversation.{id} (open thread) — validated by
    // the exact channel callbacks in routes/channels.php, using the sanctum user.
    Route::post  ('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
        return \Illuminate\Support\Facades\Broadcast::auth($request);
    });

    // Client bootstrap — real-time (Pusher public key/cluster) + push
    // availability + channel contract, so the app self-configures without any
    // key being handed over manually. Public values only (never the secret).
    Route::get   ('/config', [\App\Http\Controllers\Api\App\ConfigController::class, 'show']);

    // Chat / Team Inbox (B8) — 1-to-1 chat across Baileys + WABA + Twilio.
    Route::get   ('/chats',                                       [ChatController::class, 'index']);
    Route::post  ('/chats',                                       [ChatController::class, 'start']);
    Route::get   ('/chats/search-recipients',                     [ChatController::class, 'searchRecipients']);
    // Dedicated archived-list endpoint. Same shape as /chats but only
    // archived conversations (1-to-1 + groups). ?kind=one_to_one|group
    // to split. Registered BEFORE /chats/{id} because Laravel matches
    // routes top-down (the {id} route is also `->whereNumber('id')` so
    // "archived" would skip it, but ordering keeps the intent obvious).
    Route::get   ('/chats/archived',                              [ChatController::class, 'archivedIndex']);
    Route::get   ('/chats/{id}',                                  [ChatController::class, 'show'])->whereNumber('id');
    Route::patch ('/chats/{id}',                                  [ChatController::class, 'rename'])->whereNumber('id');
    // Polling delta (live chat). Throttled per user — 30/min = a poll every ~2s
    // ceiling; the app should poll every 3-5s. Protects the server from a
    // runaway client without limiting the normal cadence.
    Route::get   ('/chats/{id}/messages',                         [ChatController::class, 'messagesSince'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post  ('/chats/{id}/messages',                         [ChatController::class, 'sendMessage'])->whereNumber('id');
    Route::post  ('/chats/{id}/template',                         [ChatController::class, 'sendTemplate'])->whereNumber('id');
    Route::post  ('/chats/{id}/flow',                             [ChatController::class, 'startFlow'])->whereNumber('id');
    Route::post  ('/chats/{id}/read',                             [ChatController::class, 'markRead'])->whereNumber('id');
    // Attach / detach an AI agent to this chat (body: { agent_id } or null).
    Route::post  ('/chats/{id}/assign-agent',                     [ChatController::class, 'assignAgent'])->whereNumber('id');
    Route::post  ('/chats/{id}/archive',                          [ChatController::class, 'archive'])->whereNumber('id');
    Route::delete('/chats/{id}',                                  [ChatController::class, 'destroy'])->whereNumber('id');
    Route::post  ('/chats/{c}/messages/{m}/react',                [ChatController::class, 'messageReact'])->whereNumber('c')->whereNumber('m');
    Route::patch ('/chats/{c}/messages/{m}/star',                 [ChatController::class, 'messageToggleStar'])->whereNumber('c')->whereNumber('m');
    Route::delete('/chats/{c}/messages/{m}',                      [ChatController::class, 'messageDestroy'])->whereNumber('c')->whereNumber('m');
    Route::post  ('/chats/{c}/messages/{m}/pin',                  [ChatController::class, 'messagePin'])->whereNumber('c')->whereNumber('m');
    Route::post  ('/chats/{c}/messages/{m}/forward',              [ChatController::class, 'messageForward'])->whereNumber('c')->whereNumber('m');
    // Bulk-delete + send-from-saved-queue (composer button).
    Route::post  ('/chats/bulk-delete',                            [ChatController::class, 'bulkDelete']);
    // Unified bulk delete — chats + queues + contact groups in ONE call.
    // Body: { chat_ids[], queue_ids[], contact_group_ids[] }. Lets the app
    // collapse a multi-select on the chat list into one network round-trip
    // regardless of row type. Per-kind result blocks in the response.
    Route::post  ('/bulk-delete',                                  [ChatController::class, 'bulkDeleteAll']);
    Route::post  ('/chats/{id}/queue-send',                        [ChatController::class, 'sendQueueIntoChat'])->whereNumber('id');

    // WhatsApp groups (B9) — Baileys-only create + manage. The conversation
    // mirror lets /chats/{id} read + send into groups exactly like a 1:1.
    Route::get   ('/groups',                                      [GroupController::class, 'index']);
    Route::post  ('/groups',                                      [GroupController::class, 'create']);
    Route::get   ('/groups/{jid}',                                [GroupController::class, 'show'])->where('jid', '.*');
    Route::post  ('/groups/{jid}/participants',                   [GroupController::class, 'participants'])->where('jid', '.*');
    Route::post  ('/groups/{jid}/subject',                        [GroupController::class, 'updateSubject'])->where('jid', '.*');
    Route::post  ('/groups/{jid}/description',                    [GroupController::class, 'updateDescription'])->where('jid', '.*');
    Route::post  ('/groups/{jid}/settings',                       [GroupController::class, 'updateSetting'])->where('jid', '.*');
    Route::post  ('/groups/{jid}/leave',                          [GroupController::class, 'leave'])->where('jid', '.*');
    Route::get   ('/groups/{jid}/invite-code',                    [GroupController::class, 'inviteCode'])->where('jid', '.*');
    Route::post  ('/groups/{jid}/revoke-invite',                  [GroupController::class, 'revokeInvite'])->where('jid', '.*');
    // Open-or-create a Conversation thread for any group the device is a
    // participant of. Lets the app send into groups that ALREADY EXIST in
    // WhatsApp (created elsewhere) without first creating them via /groups.
    Route::post  ('/groups/{jid}/open-chat',                      [GroupController::class, 'openChat'])->where('jid', '.*');

    // Wallet (B11) — balances, ledger, top-up create/confirm. Plan
    // upgrades stay on /create-order; this is the wallet-credit path.
    Route::get ('/wallet',                       [WalletController::class, 'index']);
    Route::get ('/wallet/transactions',          [WalletController::class, 'transactions']);
    Route::post('/wallet/topup',                 [WalletController::class, 'topup']);
    Route::post('/wallet/topup/confirm',         [WalletController::class, 'topupConfirm']);

    // Admin: Payment-gateway config (B12) — set public + secret keys for
    // EVERY gateway via API (Stripe / Razorpay / PayPal / 27 more). Keys
    // are encrypted at rest; the LIST endpoint never returns decrypted
    // values — only a `credentials_set` map per key so the app can show
    // "API secret: configured / not set" without leaking secrets. Admin
    // role required (gate inside the controller).
    Route::get ('/admin/payment-gateways',                  [PaymentGatewayController::class, 'index']);
    Route::get ('/admin/payment-gateways/{id}',             [PaymentGatewayController::class, 'show'])->whereNumber('id');
    Route::patch('/admin/payment-gateways/{id}',            [PaymentGatewayController::class, 'update'])->whereNumber('id');
    Route::post('/admin/payment-gateways/{id}/toggle',      [PaymentGatewayController::class, 'toggle'])->whereNumber('id');

    // Content / profile utility (B7)
    Route::get('/notifications',                    [ContentController::class, 'notifications']);
    Route::post('/notifications/mark-as-read/{id}', [ContentController::class, 'markNotificationRead'])->whereNumber('id');
    Route::post('/notifications/mark-all-read',     [ContentController::class, 'markAllNotificationsRead']);
    Route::get('/affiliate/code',                   [ContentController::class, 'affiliateCode']);
    Route::get('/credits',                          [ContentController::class, 'credits']);
    Route::get('/attributes',                       [ContentController::class, 'attributes']);
});
