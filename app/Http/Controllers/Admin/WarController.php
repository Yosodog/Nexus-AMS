<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WarService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class WarController extends Controller
{
    use AuthorizesRequests;

    /**
     * @param WarService $warService
     * @return View
     * @throws AuthorizationException
     */
    public function index(WarService $warService): View
    {
        $this->authorize('view-wars');

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
