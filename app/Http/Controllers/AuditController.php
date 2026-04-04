<?php

namespace App\Http\Controllers;

use App\Enums\AuditPriority;
use App\Http\Requests\RegenerateNationBuildRecommendationRequest;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Services\Audit\AuditService;
use App\Services\NationBuildRecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly NationBuildRecommendationService $recommendationService
    ) {}

    public function index(): View|RedirectResponse
    {
        $user = Auth::user();
        $nation = $user?->nation;

        if (! $nation) {
            return redirect()->route('user.dashboard')->with([
                'alert-message' => 'Link a nation to view audit results.',
                'alert-type' => 'error',
            ]);
        }

        $nation->load(['cities', 'buildRecommendation']);

        $violations = $this->auditService->getNationAndCityViolationsForNation($nation);
        $allViolations = $violations['nation']->concat($violations['cities']);

        $grouped = $allViolations->groupBy(function ($result): string {
            return $result->rule?->priority->value ?? AuditPriority::Info->value;
        });

        $priorityOrder = [
            AuditPriority::High,
            AuditPriority::Medium,
            AuditPriority::Low,
            AuditPriority::Info,
        ];

        $recommendation = $nation->buildRecommendation;

        return view('audit.index', [
            'nation' => $nation,
            'violationsByPriority' => $grouped,
            'priorityOrder' => $priorityOrder,
            'buildRecommendation' => $recommendation,
            'buildRecommendationJson' => $recommendation
                ? json_encode($recommendation->recommended_build_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : null,
            'buildRecommendationGroups' => $recommendation
                ? $this->recommendationService->buildDisplayGroups($recommendation->recommended_build_json ?? [])
                : [],
        ]);
    }

    public function regenerate(RegenerateNationBuildRecommendationRequest $request): RedirectResponse
    {
        $nation = $request->user()->nation;

        if (! $nation) {
            return redirect()->route('user.dashboard')->with([
                'alert-message' => 'Link a nation to regenerate a build recommendation.',
                'alert-type' => 'error',
            ]);
        }

        RefreshNationBuildRecommendationJob::dispatch((int) $nation->id);

        return redirect()->route('audit.index')->with([
            'alert-message' => 'Build recommendation queued for regeneration.',
            'alert-type' => 'success',
        ]);
    }
}
