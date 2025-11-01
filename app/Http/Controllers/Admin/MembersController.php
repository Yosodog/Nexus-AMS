<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\MemberStatsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class MembersController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(MemberStatsService $statsService): View
    {
        $this->authorize('view-members');

        return view('admin.members.index', $statsService->getOverviewData());
    }

    /**
     * @throws AuthorizationException
     */
    public function show(Nation $nation, MemberStatsService $service): View
    {
        $this->authorize('view-members');

        return view('admin.members.show', $service->getNationStats($nation));
    }
}
