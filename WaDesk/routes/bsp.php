<?php

/*
|--------------------------------------------------------------------------
| BSP Billing routes (MERGED into core — formerly the addon/bsp module)
|--------------------------------------------------------------------------
|
| Now part of the core app: required from bootstrap/app.php, controllers live
| in app/Bsp/. Owns only the RESELLER-specific surfaces:
|   - P&L dashboard (/admin/bsp)            — margin from the core wallet ledger
|   - Connect Meta / credit line (Path A)   — attach WaDesk's credit line to a WABA
|
| Message PRICING + Meta COST + margin live on the CORE page
| /admin/settings/wallet-rules. The old /admin/bsp/rate-cards redirects there,
| and there is no separate BSP wallet — customers use the core wallet.
|
*/

use App\Bsp\Http\Controllers\Admin\BspCreditController;
use App\Bsp\Http\Controllers\Admin\BspDashboardController;
use App\Bsp\Http\Controllers\BspStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'admin'])
    ->prefix('admin/bsp')
    ->name('admin.bsp.')
    ->group(function () {
        // Health probe.
        Route::get('/status', [BspStatusController::class, 'status'])->name('status');

        // Legacy rate-card URL → the merged core pricing page.
        Route::get('/rate-cards', fn () => redirect()->route('admin.settings.wallet-rules'))->name('rate-cards.index');

        // Meta credit line (Path A) — the reseller model: attach WaDesk's own
        // Meta credit line to a customer WABA so Meta bills the platform, and
        // the platform bills the customer via the wallet + Message pricing.
        Route::get('/credit',              [BspCreditController::class, 'index'])->name('credit.index');
        Route::post('/credit/settings',    [BspCreditController::class, 'saveSettings'])->name('credit.settings');
        Route::post('/credit/attach',      [BspCreditController::class, 'attach'])->name('credit.attach');
        Route::post('/credit/attach-all',  [BspCreditController::class, 'attachAll'])->name('credit.attach-all');
        Route::post('/credit/{id}/revoke', [BspCreditController::class, 'revoke'])->name('credit.revoke');

        // Reseller P&L (module landing).
        Route::get('/', [BspDashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard', [BspDashboardController::class, 'index'])->name('dashboard.alias');
    });
