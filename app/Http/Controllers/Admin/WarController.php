<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WarService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarController extends Controller
{
    /**
     * @param WarService $warService
     * @return View
     */
    public function index(WarService $warService): View
    {
        return view('admin.wars', [
            'wars' => $warService->getActiveWars(),
            'stats' => $warService->getStats(),
            'warTypeDistribution' => $warService->getWarTypeDistribution(),
            'warStartHistory' => $warService->getWarStartHistory(),
            'topNations' => $warService->getTopNationsWithActiveWars(),
            'resourceUsage' => $warService->getResourceUsageSummary(),
            'damageBreakdown' => $warService->getDamageDealtVsTaken(),
            'aggroDefenderSplit' => $warService->getAggressorDefenderSplit(),
            'warsByNation' => $warService->getActiveWarsByNation(),
        ]);
    }
}
