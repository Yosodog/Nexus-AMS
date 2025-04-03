<?php

namespace App\Http\Controllers\Admin;

use App\Services\TaxService;

class TaxesController
{
    public function index()
    {
        $stats = TaxService::getSummaryStats();
        $charts = TaxService::getResourceChartData();
        $totals = TaxService::getDailyTotals();

        return view('admin.taxes.index', compact('stats', 'charts', 'totals'));
    }
}