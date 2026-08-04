<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Domain\Milcom\ReadinessRefreshResult;
use App\Jobs\GenerateMilcomRecommendationsJob;
use App\Models\Alliance;
use App\Models\MilcomAssignment;
use App\Models\MilcomIncident;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Services\Milcom\ReadinessRefreshService;
use App\Services\Milcom\RecommendationEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomRecommendationExecutionTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    public function test_plan_blocks_a_missing_target_without_failing_the_entire_run(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_500,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_500,
        ]);
        NationMilitary::query()->create([
            'nation_id' => $friendly->id,
            ...array_fill_keys([
                'soldiers',
                'tanks',
                'aircraft',
                'ships',
                'missiles',
                'nukes',
                'spies',
                'soldiers_today',
                'tanks_today',
                'aircraft_today',
                'ships_today',
                'missiles_today',
                'nukes_today',
                'spies_today',
                'soldier_casualties',
                'soldier_kills',
                'tank_casualties',
                'tank_kills',
                'aircraft_casualties',
                'aircraft_kills',
                'ship_casualties',
                'ship_kills',
                'missile_casualties',
                'missile_kills',
                'nuke_casualties',
                'nuke_kills',
                'spy_casualties',
                'spy_kills',
                'spy_attacks',
            ], 0),
            'soldiers' => 150_000,
            'tanks' => 10_000,
            'aircraft' => 1_200,
            'ships' => 120,
            'missiles' => 0,
            'nukes' => 0,
        ]);

        $operation = $this->createMilcomOperation([
            'failed_at' => now()->subMinute(),
            'failure_details' => ['message' => 'Previous attempt failed.'],
        ]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $run = $this->createQueuedRun($operation->id, null);
        $fetchedAt = CarbonImmutable::now();

        $this->mock(ReadinessRefreshService::class)
            ->shouldReceive('refresh')
            ->once()
            ->andReturn(new ReadinessRefreshResult(
                fetchedAt: $fetchedAt,
                refreshedNationIds: [$friendly->id],
                missingNationIds: [$target->id],
            ));

        app(RecommendationEngine::class)->execute($run);

        $this->assertSame(RecommendationRunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(OperationStatus::Review, $operation->fresh()->status);
        $this->assertNull($operation->fresh()->failure_details);
        $this->assertNull($operation->fresh()->failed_at);
        $this->assertNull($run->fresh()->failure_details);
        $this->assertSame(ObjectiveStatus::Blocked, $objective->fresh()->status);
        $this->assertSame(
            ['missing_military_data' => 1],
            $objective->fresh()->blocker_summary,
        );
        $this->assertSame(1, MilcomReadinessSnapshot::query()->where('recommendation_run_id', $run->id)->count());
        $this->assertSame(
            ['missing_military_data' => 1],
            MilcomObjectiveRecommendation::query()
                ->where('recommendation_run_id', $run->id)
                ->firstOrFail()
                ->blocker_summary,
        );
    }

    public function test_counter_still_fails_when_its_required_target_is_missing(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $operation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $run = $this->createQueuedRun($operation->id, $objective->id);

        $this->mock(ReadinessRefreshService::class)
            ->shouldReceive('refresh')
            ->once()
            ->andReturn(new ReadinessRefreshResult(
                fetchedAt: CarbonImmutable::now(),
                refreshedNationIds: [$friendly->id],
                missingNationIds: [$target->id],
            ));

        try {
            app(RecommendationEngine::class)->execute($run);
            $this->fail('The missing counter target should fail recommendation generation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $target->id, $exception->getMessage());
        }

        $this->assertSame(RecommendationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(OperationStatus::Failed, $operation->fresh()->status);
    }

    public function test_counter_never_recommends_a_nation_already_defending_against_the_target(): void
    {
        config()->set('milcom.game_rules.base_offensive_slots', 5);
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $attacked = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $availableFriendlies = Nation::factory()->count(3)->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
        ]);
        $participants = collect([$attacked, ...$availableFriendlies->all(), $target]);
        $participants->each(fn (Nation $nation) => $this->createCompleteMilitary($nation));
        $incomingWar = $this->createWar(925_000, $target, $attacked);
        $operation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $incident = MilcomIncident::query()->create([
            'war_id' => $incomingWar->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now(),
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'source_incident_id' => $incident->id,
            'desired_team_depth' => 3,
            'minimum_team_depth' => 3,
        ]);
        $incident->forceFill(['objective_id' => $objective->id])->save();
        $run = $this->createQueuedRun($operation->id, $objective->id);
        $refreshedIds = $participants->pluck('id')->map('intval')->all();

        $this->mock(ReadinessRefreshService::class)
            ->shouldReceive('refresh')
            ->once()
            ->andReturn(new ReadinessRefreshResult(
                fetchedAt: CarbonImmutable::now(),
                refreshedNationIds: $refreshedIds,
                missingNationIds: [],
            ));

        app(RecommendationEngine::class)->execute($run);

        $recommended = MilcomAssignment::query()
            ->where('objective_id', $objective->id)
            ->where('status', AssignmentStatus::Proposed->value)
            ->pluck('friendly_nation_id')
            ->map('intval')
            ->all();
        $recommendation = MilcomObjectiveRecommendation::query()
            ->where('recommendation_run_id', $run->id)
            ->firstOrFail();
        $alternativeNationIds = collect($recommendation->alternatives)
            ->flatMap(fn (array $alternative): array => array_map('intval', $alternative['nation_ids'] ?? []));

        $this->assertSame(RecommendationRunStatus::Succeeded, $run->fresh()->status);
        $this->assertCount(3, $recommended);
        $this->assertNotContains($attacked->id, $recommended);
        $this->assertNotContains($attacked->id, $recommendation->proposed_team['nation_ids']);
        $this->assertNotContains($attacked->id, $alternativeNationIds);
        $this->assertSame(1, $recommendation->blocker_summary['duplicate_war']);
    }

    public function test_auto_assigner_never_proposes_a_hard_ineligible_nation(): void
    {
        config()->set('milcom.game_rules.base_offensive_slots', 5);
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $valid = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $vacation = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'vacation_mode_turns' => 12,
        ]);
        $applicant = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'alliance_position' => 'APPLICANT',
            'score' => 1_000,
        ]);
        $outOfRange = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 100,
        ]);
        $noSlots = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $duplicateWar = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
        ]);
        $participants = collect([
            $valid,
            $vacation,
            $applicant,
            $outOfRange,
            $noSlots,
            $duplicateWar,
            $target,
        ]);
        $participants->each(fn (Nation $nation) => $this->createCompleteMilitary($nation));

        for ($index = 0; $index < 5; $index++) {
            $this->createWar(
                910_000 + $index,
                $noSlots,
                Nation::factory()->create(),
            );
        }

        $this->createWar(920_000, $duplicateWar, $target);

        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $invalidLocked = $this->createAssignment($objective, $vacation, [
            'is_locked' => true,
        ]);
        $run = $this->createQueuedRun($operation->id, null);
        $refreshedIds = $participants->pluck('id')->map('intval')->all();

        $this->mock(ReadinessRefreshService::class)
            ->shouldReceive('refresh')
            ->once()
            ->withArgs(fn (array $nationIds): bool => ! in_array($applicant->id, $nationIds, true))
            ->andReturn(new ReadinessRefreshResult(
                fetchedAt: CarbonImmutable::now(),
                refreshedNationIds: $refreshedIds,
                missingNationIds: [],
            ));

        app(RecommendationEngine::class)->execute($run);

        $this->assertSame(RecommendationRunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame([$valid->id], MilcomAssignment::query()
            ->where('objective_id', $objective->id)
            ->where('status', 'proposed')
            ->pluck('friendly_nation_id')
            ->map('intval')
            ->all());
        $this->assertSame(AssignmentStatus::Released, $invalidLocked->fresh()->status);
        $this->assertFalse($invalidLocked->fresh()->is_locked);
        $this->assertDatabaseMissing('milcom_assignments', [
            'friendly_nation_id' => $vacation->id,
            'status' => AssignmentStatus::Proposed->value,
        ]);
        $this->assertDatabaseMissing('milcom_assignments', ['friendly_nation_id' => $applicant->id]);
        $this->assertDatabaseMissing('milcom_assignments', ['friendly_nation_id' => $outOfRange->id]);
        $this->assertDatabaseMissing('milcom_assignments', ['friendly_nation_id' => $noSlots->id]);
        $this->assertDatabaseMissing('milcom_assignments', ['friendly_nation_id' => $duplicateWar->id]);
    }

    public function test_an_eligible_manual_lock_is_preserved_even_when_it_falls_outside_the_top_forty(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendlies = Nation::factory()->count(45)->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
        ]);
        $friendlies->push($target)->each(fn (Nation $nation) => $this->createCompleteMilitary($nation));
        $manualFriendly = $friendlies->slice(0, 45)->last();
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $locked = $this->createAssignment($objective, $manualFriendly, ['is_locked' => true]);
        $run = $this->createQueuedRun($operation->id, null);
        $refreshedIds = $friendlies->pluck('id')->map('intval')->all();

        $this->mock(ReadinessRefreshService::class)
            ->shouldReceive('refresh')
            ->once()
            ->andReturn(new ReadinessRefreshResult(
                fetchedAt: CarbonImmutable::now(),
                refreshedNationIds: $refreshedIds,
                missingNationIds: [],
            ));

        app(RecommendationEngine::class)->execute($run);

        $this->assertSame(AssignmentStatus::Proposed, $locked->fresh()->status);
        $this->assertTrue($locked->fresh()->is_locked);
        $this->assertSame([$manualFriendly->id], MilcomAssignment::query()
            ->where('objective_id', $objective->id)
            ->where('status', AssignmentStatus::Proposed->value)
            ->pluck('friendly_nation_id')
            ->map('intval')
            ->all());
        $this->assertArrayHasKey(
            $manualFriendly->id,
            MilcomObjectiveRecommendation::query()
                ->where('recommendation_run_id', $run->id)
                ->firstOrFail()
                ->factor_explanations['members'],
        );
    }

    public function test_an_already_accepted_job_runs_even_if_the_worker_has_stale_feature_flag_config(): void
    {
        $operation = $this->createMilcomOperation();
        $run = $this->createQueuedRun($operation->id, null);
        config()->set('milcom.v2_enabled', false);
        $engine = $this->mock(RecommendationEngine::class);
        $engine->shouldReceive('execute')
            ->once()
            ->withArgs(fn (MilcomRecommendationRun $received): bool => $received->is($run));

        (new GenerateMilcomRecommendationsJob($run->id))->handle($engine);
    }

    private function createQueuedRun(int $operationId, ?int $objectiveId): MilcomRecommendationRun
    {
        return MilcomRecommendationRun::query()->create([
            'operation_id' => $operationId,
            'objective_id' => $objectiveId,
            'status' => RecommendationRunStatus::Queued,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', "execution-test-{$operationId}-{$objectiveId}"),
            'trigger' => 'test',
            'progress_percent' => 0,
            'generation_version' => 1,
            'objectives_total' => 1,
            'failure_details' => ['message' => 'Previous attempt failed.'],
        ]);
    }

    private function createCompleteMilitary(Nation $nation): void
    {
        NationMilitary::query()->create([
            'nation_id' => $nation->id,
            ...array_fill_keys([
                'soldiers_today',
                'tanks_today',
                'aircraft_today',
                'ships_today',
                'missiles_today',
                'nukes_today',
                'spies_today',
                'soldier_casualties',
                'soldier_kills',
                'tank_casualties',
                'tank_kills',
                'aircraft_casualties',
                'aircraft_kills',
                'ship_casualties',
                'ship_kills',
                'missile_casualties',
                'missile_kills',
                'nuke_casualties',
                'nuke_kills',
                'spy_casualties',
                'spy_kills',
                'spy_attacks',
            ], 0),
            'soldiers' => 150_000,
            'tanks' => 10_000,
            'aircraft' => 1_200,
            'ships' => 120,
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 60,
        ]);
    }
}
