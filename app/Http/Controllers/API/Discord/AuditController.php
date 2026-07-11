<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Models\AuditResult;
use App\Models\User;
use App\Services\AllianceMemberEligibilityService;
use App\Services\Audit\AuditRemediationService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        private readonly AllianceMemberEligibilityService $eligibilityService,
        private readonly AuditService $auditService,
        private readonly AuditRemediationService $remediationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $nation = $this->eligibilityService->nationFor($actor);
        $violations = $this->auditService->getNationAndCityViolationsForNation($nation);

        return $this->response($violations['nation']->concat($violations['cities'])
            ->sortBy(fn (AuditResult $result): string => ($result->rule?->priority->value ?? 'info').':'.($result->first_detected_at?->timestamp ?? 0))
            ->values()
            ->map(fn (AuditResult $result): array => $this->present($result))
            ->all());
    }

    public function acknowledge(Request $request, AuditResult $auditResult): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $result = $this->remediationService->acknowledge(
            $this->actor($request),
            $auditResult,
            $validated['note'] ?? null,
        );

        return $this->response($this->present($result), 'Audit finding acknowledged.');
    }

    public function snooze(Request $request, AuditResult $auditResult): JsonResponse
    {
        $validated = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:168']]);
        $result = $this->remediationService->snooze(
            $this->actor($request),
            $auditResult,
            (int) $validated['hours'],
        );

        return $this->response($this->present($result), 'Audit reminders snoozed.');
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        return $actor;
    }

    /** @param array<int, mixed>|array<string, mixed> $data */
    private function response(array $data, ?string $message = null): JsonResponse
    {
        return response()->json([
            'data' => $data,
            ...($message ? ['message' => $message] : []),
            'meta' => ['contract_version' => 1],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AuditResult $result): array
    {
        return [
            'id' => $result->id,
            'name' => $result->rule?->name ?? 'Audit finding',
            'description' => $result->rule?->description,
            'priority' => $result->rule?->priority->value ?? 'info',
            'target_type' => $result->target_type->value,
            'target' => $result->city?->name ?? 'Nation-wide',
            'first_detected_at' => $result->first_detected_at?->toIso8601String(),
            'acknowledged_at' => $result->acknowledged_at?->toIso8601String(),
            'snoozed_until' => $result->snoozed_until?->toIso8601String(),
            'waived_until' => $result->waived_until?->toIso8601String(),
            'due_at' => $result->due_at?->toIso8601String(),
        ];
    }
}
