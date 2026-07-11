<?php

namespace App\Http\Controllers;

use App\Enums\AuditPriority;
use App\Http\Requests\AuditAcknowledgeRequest;
use App\Http\Requests\AuditSnoozeRequest;
use App\Http\Requests\RegenerateNationBuildRecommendationRequest;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Services\AllianceMemberEligibilityService;
use App\Services\Audit\AuditRemediationService;
use App\Services\Audit\AuditService;
use App\Services\NationBuildRecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly NationBuildRecommendationService $recommendationService,
        private readonly AllianceMemberEligibilityService $eligibilityService,
        private readonly AuditRemediationService $remediationService,
    ) {}

    public function index(): View|RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('user.dashboard')->with([
                'alert-message' => 'Link a nation to view audit results.',
                'alert-type' => 'error',
            ]);
        }

        $nation = $this->eligibilityService->nationFor($user);

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
            'remediationHistory' => AuditResultEvent::query()
                ->with(['rule:id,name', 'city:id,name'])
                ->where('nation_id', $nation->id)
                ->latest('occurred_at')
                ->limit(25)
                ->get(),
        ]);
    }

    public function acknowledge(AuditAcknowledgeRequest $request, AuditResult $auditResult): RedirectResponse
    {
        $this->remediationService->acknowledge(
            $request->user(),
            $auditResult,
            $request->validated('note'),
        );

        return redirect()->route('audit.index')->with([
            'alert-message' => 'Audit finding acknowledged.',
            'alert-type' => 'success',
        ]);
    }

    public function snooze(AuditSnoozeRequest $request, AuditResult $auditResult): RedirectResponse
    {
        $this->remediationService->snooze(
            $request->user(),
            $auditResult,
            (int) $request->validated('hours'),
        );

        return redirect()->route('audit.index')->with([
            'alert-message' => 'Discord audit reminders snoozed.',
            'alert-type' => 'success',
        ]);
    }

    public function regenerate(RegenerateNationBuildRecommendationRequest $request): RedirectResponse
    {
        $nation = $this->eligibilityService->nationFor($request->user());

        RefreshNationBuildRecommendationJob::dispatch((int) $nation->id);

        return redirect()->route('audit.index')->with([
            'alert-message' => 'Build recommendation queued for regeneration.',
            'alert-type' => 'success',
        ]);
    }
}
