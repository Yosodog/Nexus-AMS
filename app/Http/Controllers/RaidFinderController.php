<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use App\Models\NoRaidList;
use App\Services\RaidFinderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RaidFinderController extends Controller
{
    public function __construct(protected RaidFinderService $raidFinderService) {}

    public function index(Request $request)
    {
        $nationId = $request->get('nation_id') ?? Auth::user()->nation_id;
        $nation = Nation::findOrFail($nationId);

        if ($nation->alliance_id !== (int) env('PW_ALLIANCE_ID')) {
            abort(403, 'This nation is not in your alliance.');
        }

        $cacheKey = "raid-finder:{$nationId}";
        $targets = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($nationId) {
            return $this->raidFinderService->findTargets($nationId);
        });

        // ✅ AJAX request? Return just the data.
        if ($request->ajax()) {
            return response()->json($targets);
        }

        return view('defense.raid-finder', [
            'nationId' => $nationId,
        ]);
    }
}
