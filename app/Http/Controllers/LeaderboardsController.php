<?php

namespace App\Http\Controllers;

use App\Http\Requests\RaidingLeaderboardRequest;
use App\Services\LeaderboardDirectoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class LeaderboardsController extends Controller
{
    public function __invoke(
        LeaderboardDirectoryService $leaderboardDirectoryService,
        RaidingLeaderboardRequest $request,
        ?string $board = null
    ): View {
        return view('leaderboards.index', $leaderboardDirectoryService->getPageData(
            $board,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
            Auth::user()?->nation?->id
        ));
    }
}
