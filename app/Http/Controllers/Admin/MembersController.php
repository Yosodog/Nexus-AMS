<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nation;
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
     * @param Nation $nation
     * @param MemberStatsService $service
     * @return View
     */
    public function show(Nation $nation, MemberStatsService $service): View
    {
        return view('admin.members.show', $service->getNationStats($nation));
    }
}
