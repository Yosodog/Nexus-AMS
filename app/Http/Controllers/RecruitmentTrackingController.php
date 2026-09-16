<?php

namespace App\Http\Controllers;

use App\Models\RecruitmentMessage;
use App\Services\RecruitmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecruitmentTrackingController extends Controller
{
    public function __construct(
        private readonly RecruitmentService $recruitmentService,
    ) {}

    /**
     * Capture click from a recruitment message and redirect to the application landing page.
     */
    public function click(Request $request, string $tracking_key): RedirectResponse
    {
        $message = RecruitmentMessage::withTrashed()
            ->where('tracking_key', $tracking_key)
            ->first();

        if (! $message) {
            return redirect()->route('apply.show');
        }

        $this->recruitmentService->recordClick(
            $message,
            $request->ip(),
            $request->userAgent(),
            $request->string('cohort')->toString() ?: null,
        );

        return redirect()->route('apply.show', [
            'utm_source' => 'recruitment',
            'utm_campaign' => $message->tracking_key,
            'ref' => 'recruitment',
        ]);
    }
}
