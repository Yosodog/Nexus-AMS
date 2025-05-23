<?php

namespace App\Http\Controllers\Admin;

use App\Services\TaxService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class TaxesController
{
    use AuthorizesRequests;

    public function index()
    {
        $this->authorize('view-taxes');

        $stats = TaxService::getSummaryStats();
        $charts = TaxService::getResourceChartData();
        $totals = TaxService::getDailyTotals();

        return view('admin.taxes.index', compact('stats', 'charts', 'totals'));
    }
}