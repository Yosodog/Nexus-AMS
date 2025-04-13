<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\RaidFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RaidFinderController extends Controller
{
    /**
     * @param RaidFinderService $raidFinderService
     */
    public function __construct(protected RaidFinderService $raidFinderService)
    {
    }

    /**
     * @param int|null $nation_id
     * @return JsonResponse
     */
    public function show(?int $nation_id = null)
    {
        $nationId = $nation_id ?? Auth::user()->nation_id;

        logger("RaidFinder API called for nation ID: $nationId");

        $nation = Nation::findOrFail($nationId);

        if ($nation->alliance_id !== (int)env('PW_ALLIANCE_ID')) {
            logger("Blocked request for nation not in alliance.");
            abort(403, 'You can only run this for your alliance.');
        }

        $targets = $this->raidFinderService->findTargets($nationId);

        logger("Found " . count($targets) . " targets");

        return response()->json($targets);
    }
}
