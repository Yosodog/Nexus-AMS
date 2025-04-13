<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WarAidRequest;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WarAidController extends Controller
{
    /**
     * @return Factory|View|Application|object
     */
    public function index()
    {
        $pending = WarAidRequest::where('status', 'pending')->with('nation', 'account')->get();
        $history = WarAidRequest::whereIn('status', ['approved', 'denied'])->with('nation', 'account')->latest(
        )->paginate(25);
        $enabled = SettingService::isWarAidEnabled();

        return view('admin.defense.war-aid', compact('pending', 'history', 'enabled'));
    }

    /**
     * @param Request $request
     * @param WarAidRequest $aidRequest
     * @param WarAidService $warAidService
     * @return RedirectResponse
     */
    public function approve(Request $request, WarAidRequest $aidRequest, WarAidService $warAidService)
    {
        $data = $request->validate(
            collect(PWHelperService::resources())->mapWithKeys(fn($r) => [$r => ['nullable', 'integer', 'min:0']]
            )->toArray()
        );

        $warAidService->approveAidRequest($aidRequest, $data);

        return back()->with([
            'alert-message' => 'Aid request approved.',
            'alert-type' => 'success'
        ]);
    }

    /**
     * @param WarAidRequest $aidRequest
     * @param WarAidService $warAidService
     * @return RedirectResponse
     */
    public function deny(WarAidRequest $aidRequest, WarAidService $warAidService)
    {
        $warAidService->denyAidRequest($aidRequest);

        return back()->with([
            'alert-message' => 'Aid request denied.',
            'alert-type' => 'success'
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function toggle()
    {
        $currently = SettingService::isWarAidEnabled();
        SettingService::setWarAidEnabled(!$currently);

        return back()->with([
            'alert-message' => 'War aid has been ' . ($currently ? 'disabled' : 'enabled') . '.',
            'alert-type' => 'info'
        ]);
    }
}