<?php

namespace App\Http\Controllers\Admin;

use App\Services\TaxDashboardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxesController
{
    use AuthorizesRequests;

    public function index(Request $request, TaxDashboardService $dashboardService): View
    {
        $this->authorize('view-taxes');

        $dashboard = $dashboardService->getDashboard();
        $ledgerUrl = $request->user()?->can('view-financial-reports')
            ? route('admin.finance.index', $dashboard['ledger_filters'])
            : null;

        return view('admin.taxes.index', compact('dashboard', 'ledgerUrl'));
    }
}
