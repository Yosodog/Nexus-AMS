<?php

namespace App\Http\Controllers;

use App\Http\Requests\Rebuilding\StoreRebuildingRequest;
use App\Models\RebuildingRequest;
use App\Services\RebuildingService;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RebuildingController extends Controller
{
    public function index(RebuildingService $rebuildingService): View
    {
        $nation = Auth::user()->nation;
        $cycleId = $rebuildingService->getCurrentCycleId();
        $estimate = $rebuildingService->buildNationEstimate($nation, $cycleId);
        $requests = RebuildingRequest::query()
            ->where('nation_id', $nation->id)
            ->latest()
            ->take(25)
            ->get();

        return view('defense.rebuilding', [
            'nation' => $nation,
            'requests' => $requests,
            'estimate' => $estimate,
            'cycleId' => $cycleId,
            'enabled' => SettingService::isRebuildingEnabled(),
        ]);
    }

    public function store(StoreRebuildingRequest $request, RebuildingService $rebuildingService): RedirectResponse
    {
        try {
            $rebuildingService->submitRequest($request->user()->nation, $request->validated());
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return redirect()->route('defense.rebuilding')->with([
                'alert-message' => $details ?: 'Unable to submit rebuilding request.',
                'alert-type' => 'error',
            ]);
        }

        return redirect()->route('defense.rebuilding')->with([
            'alert-message' => 'Your rebuilding request has been submitted.',
            'alert-type' => 'success',
        ]);
    }
}
