<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Jobs\GenerateMilcomRecommendationsJob;
use App\Models\Alliance;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Services\Milcom\LifecycleReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomStaleCounterRefreshQueryTest extends TestCase
{
    use BuildsMilcomFixtures;
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    private Nation $attacked;

    private Alliance $enemyAlliance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();
        $friendlyAlliance = Alliance::factory()->create();
        $this->enemyAlliance = Alliance::factory()->create();
        $this->attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
    }

    public function test_objective_without_any_runs_is_selected_and_refreshed(): void
    {
        [$operation, $objective] = $this->createStaleCounterObjective(94_001);
        Queue::fake();

        $this->assertSame([$objective->id], $this->staleObjectiveIds());

        app(LifecycleReconciler::class)->reconcileAll();

        $this->assertSame(1, MilcomRecommendationRun::query()->where('operation_id', $operation->id)->count());
        $this->assertSame(2, $operation->fresh()->generation_version);
        Queue::assertPushed(GenerateMilcomRecommendationsJob::class);
    }

    public function test_objective_with_only_terminal_runs_is_selected(): void
    {
        [$operation, $objective] = $this->createStaleCounterObjective(94_002);

        foreach ([
            RecommendationRunStatus::Succeeded,
            RecommendationRunStatus::Failed,
            RecommendationRunStatus::Superseded,
        ] as $status) {
            $this->createRun($operation, $objective, $status);
        }

        $this->assertSame([$objective->id], $this->staleObjectiveIds());
    }

    public function test_active_run_on_a_sibling_objective_of_the_same_operation_excludes_the_objective(): void
    {
        [$operation, $objective] = $this->createStaleCounterObjective(94_003);
        $sibling = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Review,
        ]);
        $this->createRun($operation, $sibling, RecommendationRunStatus::Queued);

        $this->assertSame([], $this->staleObjectiveIds());

        MilcomRecommendationRun::query()->update(['status' => RecommendationRunStatus::Running->value]);

        $this->assertSame([], $this->staleObjectiveIds());

        MilcomRecommendationRun::query()->update(['status' => RecommendationRunStatus::Succeeded->value]);

        $this->assertSame([$objective->id], $this->staleObjectiveIds());
    }

    public function test_active_run_on_another_operation_does_not_exclude_the_objective(): void
    {
        [, $objective] = $this->createStaleCounterObjective(94_004);
        [$otherOperation, $otherObjective] = $this->createStaleCounterObjective(94_005);
        $this->createRun($otherOperation, $otherObjective, RecommendationRunStatus::Running);

        $this->assertSame([$objective->id], $this->staleObjectiveIds());
    }

    public function test_operation_level_active_run_without_an_objective_excludes_the_operation(): void
    {
        [$operation] = $this->createStaleCounterObjective(94_006);
        $this->createRun($operation, null, RecommendationRunStatus::Queued);

        $this->assertSame([], $this->staleObjectiveIds());
    }

    /**
     * @return list<int>
     */
    private function staleObjectiveIds(): array
    {
        return app(LifecycleReconciler::class)
            ->staleCounterObjectiveQuery()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{0: MilcomOperation, 1: MilcomObjective}
     */
    private function createStaleCounterObjective(int $warId): array
    {
        $target = Nation::factory()->create(['alliance_id' => $this->enemyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'generated_at' => now()->subMinutes(31),
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Review,
        ]);
        $war = $this->createWar($warId, $target, $this->attacked);
        MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $this->attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subHour(),
        ]);

        return [$operation, $objective];
    }

    private function createRun(
        MilcomOperation $operation,
        ?MilcomObjective $objective,
        RecommendationRunStatus $status,
    ): MilcomRecommendationRun {
        return MilcomRecommendationRun::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective?->id,
            'status' => $status,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', uniqid('run', true)),
            'trigger' => 'test',
            'progress_percent' => 0,
            'generation_version' => $operation->generation_version,
        ]);
    }
}
