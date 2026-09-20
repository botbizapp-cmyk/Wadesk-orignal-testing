<?php

/*
|--------------------------------------------------------------------------
| Email flow-engine routes — WaDesk core (MailTrixy bridge). Phase 3.
|--------------------------------------------------------------------------
|
| Node → Laravel callbacks for the email flow runtime. The Node engine holds
| no mail credentials: every SEND is delegated back here (flow-send → the
| MailTrixy bridge + inbox mirror), smart AI / webhook nodes resolve via
| flow-node, and flow-log mirrors without sending. All X-Node-Token guarded,
| CSRF-exempt in bootstrap/app.php. Loaded from bootstrap/app.php in the same
| slot as wechat/viber (before the workspace-slug catch-all). The inbound
| ingest itself stays on routes/api.php (POST /api/mailtrixy/inbound).
|
*/

use App\Http\Controllers\Mailtrixy\EmailFlowNodeController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::post('/api/email/flow-send', [EmailFlowNodeController::class, 'send']);
    Route::post('/api/email/flow-node', [EmailFlowNodeController::class, 'node']);
    Route::post('/api/email/flow-log',  [EmailFlowNodeController::class, 'log']);
});
