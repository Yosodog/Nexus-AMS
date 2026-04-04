<?php

namespace App\Http\Controllers;

use App\Http\Requests\RaidingLeaderboardRequest;
use Illuminate\Http\RedirectResponse;

class RaidingLeaderboardController extends Controller
{
    public function __invoke(RaidingLeaderboardRequest $request): RedirectResponse
    {
        $query = array_filter([
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ]);

        return redirect()->route('leaderboards.index', [
            'board' => 'raid-performance',
            ...$query,
        ]);
    }
}
