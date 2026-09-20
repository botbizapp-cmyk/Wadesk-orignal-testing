<?php

namespace App\Bsp\Http\Controllers;

use App\Bsp\Models\BspCreditAllocation;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Health probe — confirms the BSP add-on booted, autoloaded its App\Bsp\*
 * classes, and merged its manifest. After the full merge the add-on owns only
 * the Meta credit-line (Path A); pricing/wallet live in the CORE system, so the
 * only table it still owns is bsp_credit_allocations. Admin-only.
 */
class BspStatusController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'ok'            => true,
            'module'        => 'bsp',
            'booted'        => true,
            'merged'        => true, // pricing/wallet merged into core (wallet-rules + wallet_transactions)
            'enabled'       => (bool) SystemSetting::get('bsp_enabled', true),
            'graph_version' => (string) SystemSetting::get('bsp_graph_version', 'v26.0'),
            'credit_allocations_table' => Schema::hasTable('bsp_credit_allocations'),
            'credit_allocations'       => Schema::hasTable('bsp_credit_allocations') ? BspCreditAllocation::count() : 0,
            'meta_cost_column'         => Schema::hasColumn('message_rates', 'meta_cost_minor'),
        ]);
    }
}
