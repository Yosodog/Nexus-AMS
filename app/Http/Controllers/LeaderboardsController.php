<?php

namespace App\Http\Controllers;

use App\Services\NationProfitabilityService;
use Illuminate\Contracts\View\View;

class LeaderboardsController extends Controller
{
    public function __invoke(NationProfitabilityService $profitabilityService): View
    {
        return view('leaderboards.index', [
            'profitability' => $profitabilityService->getLeaderboard(),
        ]);
    }
}
