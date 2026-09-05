<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRaidFinderSettingsRequest;
use App\Models\NoRaidList;
use App\Services\AuditLogger;
use App\Services\RaidFinderCache;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RaidController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RaidFinderCache $raidFinderCache,
    ) {}

    /**
     * @return Factory|View|Application|object
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
            'activityCityThreshold' => SettingService::getRaidActivityCityThreshold(),
            'minimumInactiveTurns' => SettingService::getRaidMinimumInactiveTurns(),
        ]);
    }

    /**
     * @return RedirectResponse
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
        $this->raidFinderCache->invalidatePolicy();

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
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function destroyNoRaid(int $id)
    {
        $this->authorize('manage-raids');

        NoRaidList::where('id', $id)->delete();
        $this->raidFinderCache->invalidatePolicy();

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
     * @throws AuthorizationException
     */
    public function updateTopCap(UpdateRaidFinderSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage-raids');

        $validated = $request->validated();
        $previous = [
            'raid_top_alliance_cap' => SettingService::getTopRaidable(),
            'raid_activity_city_threshold' => SettingService::getRaidActivityCityThreshold(),
            'raid_minimum_inactive_turns' => SettingService::getRaidMinimumInactiveTurns(),
        ];
        $updated = [
            'raid_top_alliance_cap' => (int) $validated['top_cap'],
            'raid_activity_city_threshold' => (int) ($validated['raid_activity_city_threshold'] ?? $previous['raid_activity_city_threshold']),
            'raid_minimum_inactive_turns' => (int) ($validated['raid_minimum_inactive_turns'] ?? $previous['raid_minimum_inactive_turns']),
        ];

        SettingService::setTopRaidable($updated['raid_top_alliance_cap']);
        SettingService::setRaidActivityCityThreshold($updated['raid_activity_city_threshold']);
        SettingService::setRaidMinimumInactiveTurns($updated['raid_minimum_inactive_turns']);
        $this->raidFinderCache->invalidatePolicy();

        $this->auditLogger->success(
            category: 'settings',
            action: 'raid_finder_settings_updated',
            context: [
                'changes' => [
                    'raid_top_alliance_cap' => [
                        'from' => $previous['raid_top_alliance_cap'],
                        'to' => $updated['raid_top_alliance_cap'],
                    ],
                    'raid_activity_city_threshold' => [
                        'from' => $previous['raid_activity_city_threshold'],
                        'to' => $updated['raid_activity_city_threshold'],
                    ],
                    'raid_minimum_inactive_turns' => [
                        'from' => $previous['raid_minimum_inactive_turns'],
                        'to' => $updated['raid_minimum_inactive_turns'],
                    ],
                ],
            ],
            message: 'Raid finder settings updated.'
        );

        return redirect()->route('admin.raids.index')->with('alert-message', 'Raid finder settings updated')->with('alert-type', 'success');
    }
}
