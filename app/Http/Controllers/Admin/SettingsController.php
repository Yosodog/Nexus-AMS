<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * @return View
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

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
        $this->authorize('view-diagnostic-info');

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
        $this->authorize('view-diagnostic-info');

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
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:wars');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'War sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function cancelSync(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $request->validate([
            'batch_id' => 'required|string',
            'type' => 'required|in:nation,alliance,war',
        ]);

        $batch = Bus::findBatch($request->input('batch_id'));

        if ($batch && ! $batch->finished() && ! $batch->cancelled()) {
            $batch->cancel();
        }

        $message = ucfirst($request->input('type')) . ' sync cancelled.';

        return redirect()->route('admin.settings')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
