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
        $batchId = SettingService::getLastNationSyncBatchId();
        $batch = $batchId ? Bus::findBatch($batchId) : null;

        return view('admin.settings', compact('batch'));
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
}
