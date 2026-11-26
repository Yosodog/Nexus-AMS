<?php

namespace Tests\Integration;

use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Services\Milcom\LifecycleReconciler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;

class MilcomRecommendationRunQueryPlanTest extends MySqlIntegrationTestCase
{
    use BuildsMilcomFixtures;

    public function test_stale_counter_query_never_scans_the_runs_table(): void
    {
        $this->seedRuns(1500);
        [$operation, $objective] = $this->createStaleCounterObjective(95_001);
        $this->insertRun($operation->id, $objective->id, RecommendationRunStatus::Succeeded);
        DB::statement('ANALYZE TABLE milcom_recommendation_runs');

        $query = app(LifecycleReconciler::class)->staleCounterObjectiveQuery();
        $plan = $this->explain($query);

        $runAccess = array_values(array_filter(
            $plan,
            fn (array $row): bool => ($row['table'] ?? null) === 'milcom_recommendation_runs',
        ));

        $this->assertNotEmpty($runAccess, 'Plan does not read the runs table at all: '.json_encode($plan));

        foreach ($runAccess as $row) {
            $this->assertNotSame(
                'ALL',
                strtoupper((string) $row['type']),
                'milcom_recommendation_runs must be read through an index: '.json_encode($plan),
            );
            $this->assertNotNull($row['key'], 'No index chosen for the runs table: '.json_encode($plan));
        }

        $this->assertSame([$objective->id], $query->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $this->insertRun($operation->id, null, RecommendationRunStatus::Running);

        $this->assertSame([], $query->pluck('id')->all());
    }

    public function test_prune_command_cascades_snapshots_and_keeps_referenced_runs_on_mysql(): void
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Completed]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create());
        $old = $this->createPrunableRun($operation, $objective, 90);
        $latest = $this->createPrunableRun($operation, $objective, 89);
        $objective->forceFill(['latest_recommendation_run_id' => $latest->id])->save();
        $nation = Nation::factory()->create();
        $snapshot = $this->createReadinessSnapshot($old, $nation, 'friendly', ['fetched_at' => now()->subDays(90)]);
        $kept = $this->createReadinessSnapshot($latest, $nation, 'friendly', ['fetched_at' => now()->subDays(89)]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($snapshot);
        $this->assertModelExists($latest);
        $this->assertModelExists($kept);
        $this->assertSame($latest->id, $objective->fresh()->latest_recommendation_run_id);
        $this->assertInstanceOf(MilcomReadinessSnapshot::class, $kept->fresh());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function explain(Builder $query): array
    {
        return array_map(
            fn (object $row): array => array_change_key_case((array) $row, CASE_LOWER),
            DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings()),
        );
    }

    private function createPrunableRun(MilcomOperation $operation, MilcomObjective $objective, int $ageDays): MilcomRecommendationRun
    {
        $id = $this->insertRun($operation->id, $objective->id, RecommendationRunStatus::Succeeded, now()->subDays($ageDays));

        return MilcomRecommendationRun::query()->findOrFail($id);
    }

    private function seedRuns(int $count): void
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Completed]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create());
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = $this->runRow($operation->id, $objective->id, RecommendationRunStatus::Succeeded, now()->subDays(5));
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('milcom_recommendation_runs')->insert($chunk);
        }
    }

    private function insertRun(int $operationId, ?int $objectiveId, RecommendationRunStatus $status, mixed $createdAt = null): int
    {
        return (int) DB::table('milcom_recommendation_runs')->insertGetId(
            $this->runRow($operationId, $objectiveId, $status, $createdAt ?? now()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runRow(int $operationId, ?int $objectiveId, RecommendationRunStatus $status, mixed $createdAt): array
    {
        return [
            'operation_id' => $operationId,
            'objective_id' => $objectiveId,
            'status' => $status->value,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', uniqid('plan', true)),
            'trigger' => 'test',
            'progress_percent' => 100,
            'generation_version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @return array{0: MilcomOperation, 1: MilcomObjective}
     */
    private function createStaleCounterObjective(int $warId): array
    {
        $attacked = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'generated_at' => now()->subMinutes(31),
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Review,
        ]);
        $war = $this->createWar($warId, $target, $attacked);
        MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subHour(),
        ]);

        return [$operation, $objective];
    }
}
