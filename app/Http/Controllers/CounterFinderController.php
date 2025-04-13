<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use App\Services\NationMatchService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CounterFinderController extends Controller
{
    /**
     * @param Request $request
     * @param NationMatchService $matchService
     * @param int|null $nation
     * @return Factory|View|Application|RedirectResponse|object
     */
    public function index(Request $request, NationMatchService $matchService, ?int $nation = null)
    {
        $targetNation = null;
        $nations = collect();

        if ($nation !== null) {
            $targetNation = Nation::with('military')->find($nation);

            if (!$targetNation) {
                return redirect()
                    ->route('defense.counters')
                    ->with(['alert-message' => 'Target nation not found.', 'alert-type' => 'error']);
            }

            $ourNations = Nation::with('military')
                ->where('alliance_id', env('PW_ALLIANCE_ID'))
                ->where('alliance_position', '!=', 'APPLICANT')
                ->where("vacation_mode_turns", 0)
                ->get();

            $nations = $ourNations->map(function ($nation) use ($matchService, $targetNation) {
                if ($matchService->canAttack($nation, $targetNation)) {
                    $nation->match_score = $matchService->score($nation, $targetNation);
                    $nation->in_range = true;
                } else {
                    $nation->match_score = null;
                    $nation->in_range = false;
                }
                return $nation;
            })->sortBy(fn($n) => !$n->in_range)
                ->sortByDesc(fn($n) => $n->match_score)
                ->values();
        } else {
            // No target provided, just list all of our nations
            $nations = Nation::with('military')
                ->where('alliance_id', env('PW_ALLIANCE_ID'))
                ->get();
        }

        return view('defense.counters', [
            'target' => $targetNation,
            'nations' => $nations,
        ]);
    }
}
