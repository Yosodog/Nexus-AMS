<?php

namespace App\Http\Controllers;

use App\Services\GrowthCircleService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GrowthCircleController extends Controller
{
    public function __construct(private readonly GrowthCircleService $service) {}

    public function enroll(): RedirectResponse
    {
        if (! SettingService::isGrowthCirclesEnabled()) {
            return back()->with([
                'alert-message' => 'Growth Circles is not currently available.',
                'alert-type' => 'error',
            ]);
        }

        $nation = Auth::user()->nation;

        if (! $nation) {
            return back()->with([
                'alert-message' => 'No nation linked to your account.',
                'alert-type' => 'error',
            ]);
        }

        try {
            $this->service->enroll($nation);
        } catch (ValidationException $e) {
            return back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'You have been enrolled in Growth Circles. Your tax bracket will update shortly.',
            'alert-type' => 'success',
        ]);
    }
}
