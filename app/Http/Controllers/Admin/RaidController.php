<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NoRaidList;
use App\Services\AuditLogger;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class RaidController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     *
     * @throws AuthorizationException
     */
    public function index()
    {
        $this->authorize('view-raids');

        $noRaidList = NoRaidList::orderBy('alliance_id')->with('alliance')->get();
        $topCap = SettingService::getTopRaidable();

        return view('admin.defense.raids', [
            'noRaidList' => $noRaidList,
            'topCap' => $topCap,
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function storeNoRaid(Request $request)
    {
        $this->authorize('manage-raids');

        $request->validate([
            'alliance_id' => [
                'required',
                'integer',
                'unique:no_raid_list,alliance_id',
                'exists:alliances,id',
            ],
        ]);
        NoRaidList::create([
            'alliance_id' => $request->alliance_id,
        ]);

        $this->auditLogger->success(
            category: 'settings',
            action: 'no_raid_alliance_added',
            context: [
                'data' => [
                    'alliance_id' => (int) $request->alliance_id,
                ],
            ],
            message: 'Alliance added to no-raid list.'
        );

        return redirect()->route('admin.raids.index')->with('alert-message', 'Alliance added to no-raid list')->with('alert-type', 'success');
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function destroyNoRaid(int $id)
    {
        $this->authorize('manage-raids');

        NoRaidList::where('id', $id)->delete();

        $this->auditLogger->success(
            category: 'settings',
            action: 'no_raid_alliance_removed',
            context: [
                'data' => [
                    'no_raid_list_id' => $id,
                ],
            ],
            message: 'Alliance removed from no-raid list.'
        );

        return redirect()->route('admin.raids.index')->with('alert-message', 'Alliance removed from no-raid list')->with('alert-type', 'success');
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function updateTopCap(Request $request)
    {
        $this->authorize('manage-raids');

        $previous = SettingService::getTopRaidable();
        $request->validate(['top_cap' => 'required|integer|min:1|max:1000']);

        SettingService::setTopRaidable($request->input('top_cap'));

        $this->auditLogger->success(
            category: 'settings',
            action: 'raid_top_cap_updated',
            context: [
                'changes' => [
                    'raid_top_alliance_cap' => [
                        'from' => $previous,
                        'to' => (int) $request->input('top_cap'),
                    ],
                ],
            ],
            message: 'Raid top cap updated.'
        );

        return redirect()->route('admin.raids.index')->with('alert-message', 'Top alliance cap updated')->with('alert-type', 'success');
    }
}
