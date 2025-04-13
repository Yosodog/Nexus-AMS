<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\RaidFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RaidFinderController extends Controller
{
    /**
     * @param RaidFinderService $raidFinderService
     */
    public function __construct(protected RaidFinderService $raidFinderService)
    {
    }

    public function show(?int $nation_id = null)
    {
        $nationId = $nation_id ?? Auth::user()->nation_id;

        $nation = Nation::findOrFail($nationId);

        if ($nation->alliance_id !== (int) env('PW_ALLIANCE_ID')) {
            abort(403, 'You can only run this for your alliance.');
        }

        $cacheKey = "raid-finder:{$nationId}";

        $targets = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($nationId) {
            return app(\App\Services\RaidFinderService::class)->findTargets($nationId);
        });

        return response()->json($targets);
    }
}
