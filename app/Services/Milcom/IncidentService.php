<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\Alliance;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IncidentService
{
    public function __construct(
        private readonly AllianceMembershipService $membership,
        private readonly RecommendationEngine $recommendations,
        private readonly MilcomEventRecorder $events,
    ) {}

    public function ingest(
        int $warId,
        int $aggressorNationId,
        ?int $aggressorAllianceId,
        int $attackedNationId,
        ?int $attackedAllianceId,
    ): ?MilcomIncident {
        $started = hrtime(true);

        if ($attackedAllianceId === null || ! $this->membership->contains($attackedAllianceId)) {
            return null;
        }

        $shouldRecommend = false;

        try {
            [$incident, $shouldRecommend] = $this->persist(
                $warId,
                $aggressorNationId,
                $aggressorAllianceId,
                $attackedNationId,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }

            [$incident, $shouldRecommend] = $this->persist(
                $warId,
                $aggressorNationId,
                $aggressorAllianceId,
                $attackedNationId,
            );
        }

        if ($shouldRecommend && $incident->objective !== null) {
            $this->recommendations->queue(
                $incident->objective->operation,
                $incident->objective,
                'incoming_war',
            );
        }

        $elapsedMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $context = [
            'war_id' => $warId,
            'incident_id' => $incident->id,
            'operation_id' => $incident->objective?->operation_id,
            'objective_id' => $incident->objective_id,
            'handling_state' => $incident->status->value,
            'recommendation_queued' => $shouldRecommend,
            'elapsed_ms' => $elapsedMs,
            'budget_ms' => 2_000,
        ];
        Log::info('Milcom incoming war persisted.', $context);

        if ($elapsedMs > 2_000) {
            Log::warning('Milcom incoming war persistence exceeded latency budget.', $context);
        }

        return $incident->fresh(['objective.operation']);
    }

    /**
     * @return array{MilcomIncident, bool}
     */
    private function persist(
        int $warId,
        int $aggressorNationId,
        ?int $aggressorAllianceId,
        int $attackedNationId,
    ): array {
        return DB::transaction(function () use (
            $warId,
            $aggressorNationId,
            $aggressorAllianceId,
            $attackedNationId,
        ): array {
            $incident = MilcomIncident::query()->firstOrCreate(
                ['war_id' => $warId],
                [
                    'attacked_nation_id' => $attackedNationId,
                    'aggressor_nation_id' => $aggressorNationId,
                    'status' => IncidentStatus::New,
                    'detected_at' => now(),
                    'metadata' => ['aggressor_alliance_id' => $aggressorAllianceId],
                ],
            );

            if ($incident->objective_id !== null) {
                return [$incident->load('objective.operation'), false];
            }

            if (SettingService::getValue('milcom_counter_monitoring_enabled') === '0') {
                $this->events->record(
                    eventType: 'incident.detected_monitoring_disabled',
                    incidentId: $incident->id,
                    payload: ['war_id' => $warId],
                );

                return [$incident, false];
            }

            $coveredObjective = $this->coveredPlanObjective($aggressorNationId);

            if ($coveredObjective !== null) {
                $incident->forceFill([
                    'status' => IncidentStatus::CoveredByPlan,
                    'objective_id' => $coveredObjective->id,
                    'coverage_reason' => 'An active plan already has enough approved nations or Discord rooms.',
                ])->save();

                $this->events->record(
                    eventType: 'incident.covered_by_plan',
                    operationId: $coveredObjective->operation_id,
                    objectiveId: $coveredObjective->id,
                    incidentId: $incident->id,
                    payload: ['war_id' => $warId],
                );

                return [$incident->load('objective.operation'), false];
            }

            $objective = MilcomObjective::query()
                ->where('target_nation_id', $aggressorNationId)
                ->where('open_key', MilcomObjective::OPEN_KEY_VALUE)
                ->lockForUpdate()
                ->first();
            $created = false;

            if ($objective === null) {
                $targetName = Nation::query()->whereKey($aggressorNationId)->value('nation_name')
                    ?? "Nation {$aggressorNationId}";
                $operation = MilcomOperation::query()->create([
                    'type' => OperationType::Counter,
                    'status' => OperationStatus::Draft,
                    'current_stage' => 'staffing',
                    'name' => "Counter: {$targetName}",
                    'doctrine_version' => 'fixed-v1',
                    'default_war_type' => SettingService::getValue('milcom_default_war_type')
                        ?: config('milcom.discord.default_war_type', 'ORDINARY'),
                    'default_war_reason' => SettingService::getValue('milcom_default_war_reason')
                        ?: config('milcom.discord.default_war_reason', 'Alliance defense counter'),
                    'discord_forum_id' => SettingService::getDiscordWarRoomForumId()
                        ?: config('milcom.discord.forum_id'),
                    'deadline_at' => now()->addMinutes(30),
                    'generation_version' => 1,
                    'dispatch_version' => 0,
                    'metadata' => ['source' => 'incoming_war', 'wave' => 1],
                ]);

                $friendlyAllianceIds = Alliance::query()
                    ->whereIn('id', $this->membership->getAllianceIds())
                    ->pluck('id')
                    ->map('intval')
                    ->all();

                if ($friendlyAllianceIds !== []) {
                    $operation->alliances()->createMany(array_map(
                        static fn (int $allianceId): array => [
                            'alliance_id' => $allianceId,
                            'role' => 'friendly',
                            'included' => true,
                        ],
                        $friendlyAllianceIds
                    ));
                }

                $objective = $operation->objectives()->create([
                    'target_nation_id' => $aggressorNationId,
                    'priority_tier' => PriorityTier::Critical,
                    'priority_score' => 100,
                    'desired_team_depth' => 3,
                    'minimum_team_depth' => 3,
                    'war_type' => $operation->default_war_type,
                    'war_reason' => $operation->default_war_reason,
                    'deadline_at' => $operation->deadline_at,
                    'status' => ObjectiveStatus::Pending,
                    'source_incident_id' => $incident->id,
                    'open_key' => MilcomObjective::OPEN_KEY_VALUE,
                    'generation_version' => 1,
                    'dispatch_version' => 0,
                ]);
                $created = true;
            }

            $incident->forceFill([
                'status' => IncidentStatus::Countering,
                'objective_id' => $objective->id,
                'coverage_reason' => null,
                'ignored_reason' => null,
                'resolved_at' => null,
            ])->save();

            $this->events->record(
                eventType: 'incident.countering',
                operationId: $objective->operation_id,
                objectiveId: $objective->id,
                incidentId: $incident->id,
                payload: ['war_id' => $warId, 'objective_created' => $created],
            );

            $hasActiveRecommendation = MilcomRecommendationRun::query()
                ->where('objective_id', $objective->id)
                ->whereIn('status', [
                    RecommendationRunStatus::Queued->value,
                    RecommendationRunStatus::Running->value,
                ])
                ->exists();

            return [
                $incident->load('objective.operation'),
                $created || ($objective->latest_recommendation_run_id === null && ! $hasActiveRecommendation),
            ];
        }, attempts: 5);
    }

    private function coveredPlanObjective(int $aggressorNationId): ?MilcomObjective
    {
        return MilcomObjective::query()
            ->where('target_nation_id', $aggressorNationId)
            ->whereHas('operation', fn ($query) => $query
                ->where('type', OperationType::Plan->value)
                ->whereIn('status', [
                    OperationStatus::Dispatching->value,
                    OperationStatus::Active->value,
                ]))
            ->withCount(['assignments as coverage_count' => fn ($query) => $query->whereIn('status', [
                'approved',
                'dispatched',
                'engaged',
            ])])
            ->orderBy('id')
            ->get()
            ->first(fn (MilcomObjective $objective): bool => (int) $objective->coverage_count
                >= (int) $objective->minimum_team_depth);
    }
}
