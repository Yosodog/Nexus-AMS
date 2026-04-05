<?php

namespace App\Http\Controllers;

use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\GrowthCircleService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GrowthCircleController extends Controller
{
    public function __construct(
        private readonly GrowthCircleService $service,
        private readonly AllianceMembershipService $membershipService,
        private readonly AuditLogger $auditLogger
    ) {}

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

        if (! $this->membershipService->contains($nation->alliance_id)) {
            return back()->with([
                'alert-message' => 'Only alliance members can enroll in Growth Circles.',
                'alert-type' => 'error',
            ]);
        }

        try {
            $this->service->enroll($nation);
        } catch (ValidationException $e) {
            return back()->with([
                'alert-message' => $e->validator->errors()->first() ?: 'Unable to enroll in Growth Circles.',
                'alert-type' => 'error',
            ]);
        }

        $this->auditLogger->success(
            category: 'finance',
            action: 'growth_circle_enrolled',
            subject: $nation,
            context: [
                'data' => [
                    'nation_id' => $nation->id,
                ],
            ],
            message: 'Nation enrolled in Growth Circles.'
        );

        return back()->with([
            'alert-message' => 'You have been enrolled in Growth Circles. Your tax bracket will update shortly.',
            'alert-type' => 'success',
        ]);
    }
}
