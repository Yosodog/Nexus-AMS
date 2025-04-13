<?php

namespace App\Http\Controllers;

use App\Models\War;
use App\Models\WarAidRequest;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WarAidController extends Controller
{
    /**
     * @return Factory|View|Application|object
     */
    public function index()
    {
        $nation = Auth::user()->nation;
        $wars = War::where(function ($query) use ($nation) {
            $query->where('att_id', $nation->id)
                ->orWhere('def_id', $nation->id);
        })->active()->get();

        $requests = WarAidRequest::where('nation_id', $nation->id)
            ->latest()
            ->take(25)
            ->get();

        return view('defense.war-aid', compact('nation', 'wars', 'requests'));
    }

    /**
     * @param Request $request
     * @param WarAidService $warAidService
     * @return RedirectResponse
     * @throws ValidationException
     */
    public function store(Request $request, WarAidService $warAidService)
    {
        if (!SettingService::isWarAidEnabled()) {
            return redirect()->route('defense.war-aid')->with([
                'alert-message' => 'War aid is currently disabled.',
                'alert-type' => 'error',
            ]);
        }

        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'note' => ['required', 'string', 'max:255'],
            ...collect(PWHelperService::resources())
                ->mapWithKeys(fn($r) => [$r => ['nullable', 'integer', 'min:0']])
                ->toArray()
        ]);

        $warAidService->submitAidRequest(Auth::user()->nation, $data);

        return redirect()->route('defense.war-aid')->with([
            'alert-message' => 'Your war aid request has been submitted.',
            'alert-type' => 'success'
        ]);
    }
}