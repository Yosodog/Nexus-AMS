<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NoRaidList;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RaidController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     */
    public function index()
    {
        $noRaidList = NoRaidList::orderBy('alliance_id')->with("alliance")->get();
        $topCap = SettingService::getTopRaidable();

        return view('admin.defense.raids', [
            'noRaidList' => $noRaidList,
            'topCap' => $topCap,
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeNoRaid(Request $request)
    {
        $request->validate([
            'alliance_id' => [
                'required',
                'integer',
                'unique:no_raid_list,alliance_id',
                'exists:alliances,id'
            ],
        ]);
        NoRaidList::create([
            'alliance_id' => $request->alliance_id
        ]);

        return redirect()->route('admin.raids.index')->with('alert-message', 'Alliance added to no-raid list')->with('alert-type', 'success');
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyNoRaid(int $id)
    {
        NoRaidList::where('id', $id)->delete();

        return redirect()->route('admin.raids.index')->with('alert-message', 'Alliance removed from no-raid list')->with('alert-type', 'success');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateTopCap(Request $request)
    {
        $request->validate(['top_cap' => 'required|integer|min:1|max:1000']);

        SettingService::setTopRaidable($request->input('top_cap'));

        return redirect()->route('admin.raids.index')->with('alert-message', 'Top alliance cap updated')->with('alert-type', 'success');
    }
}
