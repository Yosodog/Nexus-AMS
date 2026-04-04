<?php

namespace App\Http\Controllers;

use App\Services\LeaderboardDirectoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaderboardsController extends Controller
{
    public function __invoke(
        LeaderboardDirectoryService $leaderboardDirectoryService,
        Request $request,
        ?string $board = null
    ): View {
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
            Auth::user()?->nation?->id
        ));
    }
}
