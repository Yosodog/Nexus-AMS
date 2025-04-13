<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use App\Services\RaidFinderService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * @param Request $request
     * @return Factory|View|Application|JsonResponse|object
     */
    public function index(Request $request)
    {
        $nationId = $request->get('nation_id') ?? Auth::user()->nation_id;

        return view('defense.raid-finder', [
            'nationId' => $nationId,
        ]);
    }
}
