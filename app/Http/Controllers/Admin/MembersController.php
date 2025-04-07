<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
}
