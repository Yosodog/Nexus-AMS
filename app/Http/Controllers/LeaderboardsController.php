<?php

namespace App\Http\Controllers;

use App\Services\AllianceMemberEligibilityService;
use App\Services\LeaderboardDirectoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LeaderboardsController extends Controller
{
    public function __invoke(
        AllianceMemberEligibilityService $memberEligibilityService,
        LeaderboardDirectoryService $leaderboardDirectoryService,
        Request $request,
        ?string $board = null
    ): View {
        $viewerNation = $memberEligibilityService->nationFor($request->user());

        if ($board === 'raid-performance') {
            $request->validate([
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
            ]);
        }

        return view('leaderboards.index', $leaderboardDirectoryService->getPageData(
            $board,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
            $viewerNation->id
        ));
    }
}
