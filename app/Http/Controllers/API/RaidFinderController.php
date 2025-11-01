<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\RaidFinderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RaidFinderController extends Controller
{
    public function __construct(
        protected RaidFinderService $raidFinderService,
        protected AllianceMembershipService $membershipService
    ) {}

    public function show(?int $nation_id = null)
    {
        $nationId = $nation_id ?? Auth::user()->nation_id;

        $nation = Nation::findOrFail($nationId);

        if (! $this->membershipService->contains($nation->alliance_id)) {
            abort(403, 'You can only run this for your alliance.');
        }

        $cacheKey = "raid-finder:{$nationId}";

        $targets = Cache::remember($cacheKey, now()->addMinutes(30), fn () => $this->raidFinderService->findTargets($nationId));

        return response()->json($targets);
    }
}
