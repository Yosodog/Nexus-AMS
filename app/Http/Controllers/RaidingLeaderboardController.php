<?php

namespace App\Http\Controllers;

use App\Http\Requests\RaidingLeaderboardRequest;
use App\Services\War\RaidLeaderboardService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class RaidingLeaderboardController extends Controller
{
    public function __invoke(
        RaidingLeaderboardRequest $request,
        RaidLeaderboardService $raidLeaderboardService
    ): View {
        $from = $request->date('from')
            ? Carbon::parse($request->date('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = $request->date('to')
            ? Carbon::parse($request->date('to'))->endOfDay()
            : now()->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $nationId = Auth::user()?->nation?->id;
        $payload = $raidLeaderboardService->buildLeaderboard($from, $to, $nationId);

        return view('defense.raid-leaderboard', [
            ...$payload,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }
}
