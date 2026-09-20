<?php

namespace App\Bsp\Http\Controllers\Admin;

use App\Bsp\Services\ReportService;
use App\Http\Controllers\Controller;
use App\Support\FormatSettings;
use Illuminate\Http\Response;

/**
 * Admin BSP dashboard — platform P&L (revenue vs Meta cost = margin) and a
 * per-workspace breakdown. All figures come from the CORE wallet ledger
 * (wallet_transactions written by OverflowBilling); no bsp_* tables.
 */
class BspDashboardController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(): Response
    {
        $cur = FormatSettings::currencyFor();

        return response()->view('bsp.dashboard.index', [
            'totals'   => $this->reports->platformTotals(),
            'rows'     => $this->reports->perWorkspace(),
            'currency' => strtoupper($cur?->code ?? 'USD'),
            'symbol'   => $cur?->symbol ?: ($cur?->code ?? ''),
        ]);
    }
}
