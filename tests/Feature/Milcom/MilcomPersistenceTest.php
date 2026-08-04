<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\Alliance;
use App\Models\MilcomDispatch;
use App\Models\MilcomIncident;
use App\Models\MilcomNationCapacityLock;
use App\Models\MilcomObjective;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomPersistenceTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    public function test_models_hydrate_lifecycle_columns_as_backed_enums(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Active,
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'priority_tier' => PriorityTier::Critical,
            'status' => ObjectiveStatus::Dispatched,
            'desired_team_depth' => 3,
            'minimum_team_depth' => 3,
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Dispatched,
        ]);
        $war = $this->createWar(91_001, $target, $friendly);
        $incident = MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $friendly->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now(),
        ]);
        $run = MilcomRecommendationRun::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'status' => RecommendationRunStatus::Succeeded,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', 'enum-cast'),
            'trigger' => 'test',
            'progress_percent' => 100,
            'generation_version' => 1,
        ]);
        $dispatch = MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Queued,
            'dedupe_key' => "milcom-objective:{$objective->id}:room:v1",
            'payload_snapshot' => ['source' => ['type' => 'milcom_objective']],
        ]);

        $this->assertSame(OperationType::Counter, $operation->fresh()->type);
        $this->assertSame(OperationStatus::Active, $operation->fresh()->status);
        $this->assertSame(PriorityTier::Critical, $objective->fresh()->priority_tier);
        $this->assertSame(ObjectiveStatus::Dispatched, $objective->fresh()->status);
        $this->assertSame(AssignmentStatus::Dispatched, $assignment->fresh()->status);
        $this->assertSame(IncidentStatus::Countering, $incident->fresh()->status);
        $this->assertSame(RecommendationRunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(DispatchStatus::Queued, $dispatch->fresh()->status);
    }

    public function test_incident_war_id_is_unique(): void
    {
        $friendly = Nation::factory()->create();
        $aggressor = Nation::factory()->create();
        $war = $this->createWar(91_002, $aggressor, $friendly);

        MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $friendly->id,
            'aggressor_nation_id' => $aggressor->id,
            'status' => IncidentStatus::New,
            'detected_at' => now(),
        ]);

        $this->assertUniqueViolation(fn () => MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $friendly->id,
            'aggressor_nation_id' => $aggressor->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now(),
        ]));
    }

    public function test_objective_assignment_dispatch_and_capacity_identity_constraints_are_unique(): void
    {
        $friendly = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, $target);
        $assignment = $this->createAssignment($objective, $friendly);
        $dedupeKey = "milcom-objective:{$objective->id}:room:v1";

        $this->assertUniqueViolation(
            fn () => $this->createMilcomObjective($operation, $target)
        );
        $this->assertUniqueViolation(
            fn () => $this->createAssignment($objective, $friendly)
        );

        MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Queued,
            'dedupe_key' => $dedupeKey,
            'payload_snapshot' => [],
        ]);
        $this->assertUniqueViolation(fn () => MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Queued,
            'dedupe_key' => $dedupeKey,
            'payload_snapshot' => [],
        ]));

        MilcomNationCapacityLock::query()->create(['friendly_nation_id' => $friendly->id]);
        $this->assertUniqueViolation(
            fn () => MilcomNationCapacityLock::query()->create(['friendly_nation_id' => $friendly->id])
        );

        $this->assertDatabaseHas('milcom_assignments', ['id' => $assignment->id]);
    }

    public function test_only_one_open_counter_objective_can_exist_per_aggressor(): void
    {
        $target = Nation::factory()->create();
        $firstOperation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $secondOperation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $first = $this->createMilcomObjective($firstOperation, $target, [
            'status' => ObjectiveStatus::Pending,
            'open_key' => MilcomObjective::OPEN_KEY_VALUE,
        ]);

        $this->assertUniqueViolation(fn () => $this->createMilcomObjective($secondOperation, $target, [
            'status' => ObjectiveStatus::Pending,
            'open_key' => MilcomObjective::OPEN_KEY_VALUE,
        ]));

        $first->forceFill(['status' => ObjectiveStatus::Completed])->save();

        $this->assertNull($first->fresh()->open_key);
        $replacement = $this->createMilcomObjective($secondOperation, $target, [
            'status' => ObjectiveStatus::Pending,
            'open_key' => MilcomObjective::OPEN_KEY_VALUE,
        ]);
        $this->assertSame(MilcomObjective::OPEN_KEY_VALUE, $replacement->open_key);
    }

    public function test_readiness_snapshot_identity_is_unique_within_a_run_and_role(): void
    {
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, $target);
        $run = $this->attachSuccessfulRecommendation($objective, []);

        $this->assertUniqueViolation(fn () => MilcomReadinessSnapshot::query()->create([
            'recommendation_run_id' => $run->id,
            'nation_id' => $target->id,
            'role' => 'target',
            'alliance_id' => $target->alliance_id,
            'alliance_position' => $target->alliance_position,
            'score' => $target->score,
            'cities' => $target->num_cities,
            'fetched_at' => now(),
            'payload' => ['fetched_at' => now()->toIso8601String()],
        ]));
    }

    private function assertUniqueViolation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a database unique-constraint violation.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }
}
