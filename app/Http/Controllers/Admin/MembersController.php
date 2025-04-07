<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nations;
use App\Services\MemberStatsService;
use Illuminate\View\View;

class MembersController extends Controller
{
    /**
     * @param MemberStatsService $statsService
     * @return View
     */
    public function index(MemberStatsService $statsService): View
    {
        return view('admin.members.index', $statsService->getOverviewData());
    }

    /**
     * @param Nations $nation
     * @param MemberStatsService $service
     * @return View
     */
    public function show(Nations $nation, MemberStatsService $service): View
    {
        return view('admin.members.show', $service->getNationStats($nation));
    }
}
