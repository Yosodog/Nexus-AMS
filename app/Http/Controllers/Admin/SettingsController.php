<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * @return View
     */
    public function index(): View
    {
        $nationBatchId = SettingService::getLastNationSyncBatchId();
        $allianceBatchId = SettingService::getLastAllianceSyncBatchId();
        $warBatchId = SettingService::getLastWarSyncBatchId();


        $nationBatch = $nationBatchId ? Bus::findBatch($nationBatchId) : null;
        $allianceBatch = $allianceBatchId ? Bus::findBatch($allianceBatchId) : null;
        $warBatch = $warBatchId ? Bus::findBatch($warBatchId) : null;


        return view('admin.settings', [
            'nationBatch' => $nationBatch,
            'allianceBatch' => $allianceBatch,
            'warBatch' => $warBatch,
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function runSyncNation(): RedirectResponse
    {
        Artisan::call('sync:nations');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Nation sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function runSyncAlliance(): RedirectResponse
    {
        Artisan::call('sync:alliances');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Alliance sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function runSyncWar(): RedirectResponse
    {
        Artisan::call('sync:wars');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'War sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }
}
