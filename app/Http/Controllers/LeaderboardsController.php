<?php

namespace App\Http\Controllers;

use App\Http\Requests\RaidingLeaderboardRequest;
use App\Services\AllianceMemberEligibilityService;
use App\Services\LeaderboardDirectoryService;
use Illuminate\Contracts\View\View;

class LeaderboardsController extends Controller
{
    public function __invoke(
        AllianceMemberEligibilityService $memberEligibilityService,
        LeaderboardDirectoryService $leaderboardDirectoryService,
        RaidingLeaderboardRequest $request,
        ?string $board = null
    ): View {
        $viewerNation = $memberEligibilityService->nationFor($request->user());

        return view('leaderboards.index', $leaderboardDirectoryService->getPageData(
            $board,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
            $viewerNation->id
        ));
    }
}
