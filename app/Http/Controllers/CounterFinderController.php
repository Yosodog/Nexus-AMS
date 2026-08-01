<?php

namespace App\Http\Controllers;

use App\Enums\AlliancePositionEnum;
use App\Models\Nation;
use App\Services\AllianceMemberEligibilityService;
use App\Services\AllianceMembershipService;
use App\Services\NationMatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CounterFinderController extends Controller
{
    public function index(
        Request $request,
        NationMatchService $matchService,
        AllianceMemberEligibilityService $memberEligibilityService,
        AllianceMembershipService $membershipService,
        ?int $nation = null
    ): View|RedirectResponse {
        $this->authorize('view-wars');
        $memberEligibilityService->nationFor($request->user());

        $targetNation = null;
        $nations = collect();

        if ($nation !== null) {
            $targetNation = Nation::with('military')->find($nation);

            if (! $targetNation) {
                return redirect()
                    ->route('defense.counters')
                    ->with(['alert-message' => 'Target nation not found.', 'alert-type' => 'error']);
            }

            $ourNations = Nation::with('military')
                ->whereIn('alliance_id', $membershipService->getAllianceIds())
                ->whereIn('alliance_position', $this->memberPositions())
                ->where('vacation_mode_turns', 0)
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
            })->sortBy(fn ($n) => ! $n->in_range)
                ->sortByDesc(fn ($n) => $n->match_score)
                ->values();
        } else {
            // No target provided, just list all of our nations
            $nations = Nation::with('military')
                ->whereIn('alliance_id', $membershipService->getAllianceIds())
                ->whereIn('alliance_position', $this->memberPositions())
                ->get();
        }

        return view('defense.counters', [
            'target' => $targetNation,
            'nations' => $nations,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function memberPositions(): array
    {
        return [
            AlliancePositionEnum::MEMBER->value,
            AlliancePositionEnum::OFFICER->value,
            AlliancePositionEnum::HEIR->value,
            AlliancePositionEnum::LEADER->value,
        ];
    }
}
