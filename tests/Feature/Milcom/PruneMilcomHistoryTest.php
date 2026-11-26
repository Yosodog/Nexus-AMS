<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\MilcomObjective;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Services\Discord\DiscordMilcomReadProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class PruneMilcomHistoryTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    public function test_old_unreferenced_runs_of_finished_operations_are_deleted_with_their_children(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $run = $this->createRun($operation, $objective, ['created_at' => now()->subDays(90)]);
        $nation = Nation::factory()->create();
        $snapshot = $this->createReadinessSnapshot($run, $nation, 'friendly', [
            'fetched_at' => now()->subDays(90),
        ]);
        $recommendation = $this->createRecommendation($run, $objective);
        $newer = $this->createRun($operation, $objective, ['created_at' => now()->subDays(80)]);
        $this->createReadinessSnapshot($newer, $nation, 'friendly', ['fetched_at' => now()->subDays(80)]);
        $objective->forceFill(['latest_recommendation_run_id' => $newer->id])->save();
        $this->createRecommendation($newer, $objective);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($run);
        $this->assertModelMissing($snapshot);
        $this->assertModelMissing($recommendation);
        $this->assertModelExists($newer);
    }

    public function test_runs_inside_retention_survive(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $older = $this->createRun($operation, $objective, ['created_at' => now()->subDays(59)]);
        $newest = $this->createRun($operation, $objective, ['created_at' => now()->subDays(1)]);
        $objective->forceFill(['latest_recommendation_run_id' => $newest->id])->save();

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelExists($older);
        $this->assertModelExists($newest);
    }

    public function test_the_latest_run_of_an_objective_survives_however_old(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $old = $this->createRun($operation, $objective, ['created_at' => now()->subDays(400)]);
        $latest = $this->createRun($operation, $objective, ['created_at' => now()->subDays(399)]);
        $objective->forceFill(['latest_recommendation_run_id' => $latest->id])->save();

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($latest);
        $this->assertSame($latest->id, $objective->fresh()->latest_recommendation_run_id);
    }

    public function test_the_newest_run_of_each_objective_survives_even_when_not_linked_as_latest(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $older = $this->createRun($operation, $objective, ['created_at' => now()->subDays(300)]);
        $newest = $this->createRun($operation, $objective, [
            'created_at' => now()->subDays(299),
            'status' => RecommendationRunStatus::Failed,
        ]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($older);
        $this->assertModelExists($newest);
    }

    public function test_the_newest_operation_level_run_survives_for_force_comparison(): void
    {
        [$operation] = $this->finishedOperationWithObjective();
        $older = $this->createRun($operation, null, ['created_at' => now()->subDays(300)]);
        $newest = $this->createRun($operation, null, ['created_at' => now()->subDays(299)]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($older);
        $this->assertModelExists($newest);
    }

    public function test_the_run_holding_an_objectives_newest_recommendation_survives(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $holder = $this->createRun($operation, null, ['created_at' => now()->subDays(300)]);
        $this->createRecommendation($holder, $objective);
        $newestOperationRun = $this->createRun($operation, null, ['created_at' => now()->subDays(299)]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelExists($holder);
        $this->assertModelExists($newestOperationRun);
    }

    public function test_runs_referenced_by_assignments_survive_and_keep_their_link(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $referenced = $this->createRun($operation, null, ['created_at' => now()->subDays(300)]);
        $this->createRun($operation, null, ['created_at' => now()->subDays(299)]);
        $assignment = $this->createAssignment($objective, Nation::factory()->create(), [
            'recommendation_run_id' => $referenced->id,
        ]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelExists($referenced);
        $this->assertSame($referenced->id, $assignment->fresh()->recommendation_run_id);
    }

    public function test_runs_of_unfinished_operations_survive_however_old(): void
    {
        $survivors = [];

        foreach ([
            OperationStatus::Draft,
            OperationStatus::Generating,
            OperationStatus::Review,
            OperationStatus::Dispatching,
            OperationStatus::Active,
            OperationStatus::Failed,
        ] as $status) {
            $operation = $this->createMilcomOperation(['status' => $status]);
            $objective = $this->createMilcomObjective($operation, Nation::factory()->create());

            foreach (range(1, 2) as $offset) {
                $survivors[] = $this->createRun($operation, $objective, [
                    'created_at' => now()->subDays(500 + $offset),
                ]);
            }
        }

        $this->artisan('milcom:prune-history')->assertSuccessful();

        foreach ($survivors as $run) {
            $this->assertModelExists($run);
        }
    }

    public function test_queued_and_running_runs_survive_even_for_finished_operations(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $queued = $this->createRun($operation, $objective, [
            'status' => RecommendationRunStatus::Queued,
            'created_at' => now()->subDays(300),
        ]);
        $running = $this->createRun($operation, $objective, [
            'status' => RecommendationRunStatus::Running,
            'created_at' => now()->subDays(300),
        ]);
        $snapshot = $this->createReadinessSnapshot($running, Nation::factory()->create(), 'friendly', [
            'fetched_at' => now()->subDays(300),
        ]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelExists($queued);
        $this->assertModelExists($running);
        $this->assertModelExists($snapshot);
    }

    public function test_old_snapshots_of_unprotected_runs_are_pruned_while_protected_ones_stay(): void
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Review]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create());
        $stale = $this->createRun($operation, $objective, ['created_at' => now()->subDays(20)]);
        $latest = $this->createRun($operation, $objective, ['created_at' => now()->subDays(19)]);
        $objective->forceFill(['latest_recommendation_run_id' => $latest->id])->save();
        $nation = Nation::factory()->create();

        $oldUnprotected = $this->createReadinessSnapshot($stale, $nation, 'friendly', [
            'fetched_at' => now()->subDays(40),
        ]);
        $recentUnprotected = $this->createReadinessSnapshot($stale, Nation::factory()->create(), 'friendly', [
            'fetched_at' => now()->subDays(13),
        ]);
        $oldProtected = $this->createReadinessSnapshot($latest, $nation, 'friendly', [
            'fetched_at' => now()->subDays(20),
        ]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($oldUnprotected);
        $this->assertModelExists($recentUnprotected);
        $this->assertModelExists($oldProtected);
        $this->assertModelExists($stale);
    }

    public function test_deletes_are_chunked_and_a_second_run_finds_nothing(): void
    {
        config(['milcom.retention.batch_size' => 2]);
        [$operation] = $this->finishedOperationWithObjective();
        $doomed = collect(range(1, 5))->map(fn (int $day): MilcomRecommendationRun => $this->createRun($operation, null, [
            'created_at' => now()->subDays(300 + $day),
        ]));
        $newest = $this->createRun($operation, null, ['created_at' => now()->subDays(100)]);

        $deleteStatements = 0;
        DB::listen(function ($query) use (&$deleteStatements): void {
            if (str_starts_with(strtolower($query->sql), 'delete from "milcom_recommendation_runs"')
                || str_starts_with(strtolower($query->sql), 'delete from `milcom_recommendation_runs`')) {
                $deleteStatements++;
            }
        });

        $this->artisan('milcom:prune-history')
            ->expectsOutputToContain('recommendation runs: 5')
            ->assertSuccessful();

        $this->assertSame(3, $deleteStatements);
        $this->assertSame(1, MilcomRecommendationRun::query()->count());
        $this->assertModelExists($newest);
        $doomed->each(fn (MilcomRecommendationRun $run) => $this->assertModelMissing($run));

        $this->artisan('milcom:prune-history')
            ->expectsOutputToContain('recommendation runs: 0')
            ->expectsOutputToContain('readiness snapshots: 0')
            ->assertSuccessful();
        $this->assertSame(1, MilcomRecommendationRun::query()->count());
    }

    public function test_snapshot_deletes_are_chunked(): void
    {
        config(['milcom.retention.batch_size' => 2]);
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $nation = Nation::factory()->create();
        $snapshots = collect(range(1, 5))->map(fn (int $day): MilcomReadinessSnapshot => $this->createReadinessSnapshot(
            $this->createRun($operation, null, ['created_at' => now()->subDays(60 + $day)]),
            $nation,
            'friendly',
            ['fetched_at' => now()->subDays(44 + $day)],
        ));
        $run = $this->createRun($operation, null, ['created_at' => now()->subDays(2)]);
        $keep = $this->createReadinessSnapshot($run, $nation, 'friendly', [
            'fetched_at' => now()->subDays(2),
        ]);

        $this->artisan('milcom:prune-history')
            ->expectsOutputToContain('readiness snapshots: 5')
            ->assertSuccessful();

        $snapshots->each(fn (MilcomReadinessSnapshot $snapshot) => $this->assertModelMissing($snapshot));
        $this->assertModelExists($keep);
    }

    public function test_pretend_reports_counts_without_deleting(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $old = $this->createRun($operation, $objective, ['created_at' => now()->subDays(90)]);
        $this->createRun($operation, $objective, ['created_at' => now()->subDays(80)]);
        $nation = Nation::factory()->create();
        $snapshot = $this->createReadinessSnapshot($old, $nation, 'friendly', [
            'fetched_at' => now()->subDays(90),
        ]);
        $this->createReadinessSnapshot(
            MilcomRecommendationRun::query()->latest('id')->firstOrFail(),
            $nation,
            'friendly',
            ['fetched_at' => now()->subDays(80)],
        );

        $this->artisan('milcom:prune-history', ['--pretend' => true])
            ->expectsOutputToContain('would prune')
            ->expectsOutputToContain('recommendation runs: 1')
            ->expectsOutputToContain('readiness snapshots: 1')
            ->assertSuccessful();

        $this->assertModelExists($old);
        $this->assertModelExists($snapshot);
    }

    public function test_a_nations_newest_snapshot_and_its_run_survive_so_discord_readiness_still_answers(): void
    {
        [$operation, $objective] = $this->finishedOperationWithObjective();
        $nation = Nation::factory()->create();
        $holder = $this->createRun($operation, $objective, ['created_at' => now()->subDays(400)]);
        $snapshot = $this->createReadinessSnapshot($holder, $nation, 'friendly', [
            'fetched_at' => now()->subDays(400),
        ]);
        $newer = $this->createRun($operation, $objective, ['created_at' => now()->subDays(300)]);
        $this->createRun($operation, null, ['created_at' => now()->subDays(299)]);
        $objective->forceFill(['latest_recommendation_run_id' => $newer->id])->save();
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelExists($holder);
        $this->assertModelExists($snapshot);
        $readiness = app(DiscordMilcomReadProvider::class)->readiness($user);
        $this->assertNotNull($readiness);
        $this->assertSame($nation->id, $readiness['nation']->id);
        $this->assertSame($snapshot->id, $readiness['snapshot']->id);
    }

    public function test_only_older_snapshots_of_a_nation_are_pruned(): void
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create());
        $nation = Nation::factory()->create();
        $oldRun = $this->createRun($operation, $objective, ['created_at' => now()->subDays(100)]);
        $newerRun = $this->createRun($operation, $objective, ['created_at' => now()->subDays(90)]);
        $newestRun = $this->createRun($operation, $objective, ['created_at' => now()->subDays(80)]);
        $objective->forceFill(['latest_recommendation_run_id' => $newestRun->id])->save();
        $oldest = $this->createReadinessSnapshot($oldRun, $nation, 'friendly', ['fetched_at' => now()->subDays(100)]);
        $newest = $this->createReadinessSnapshot($newerRun, $nation, 'friendly', ['fetched_at' => now()->subDays(90)]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($oldest);
        $this->assertModelExists($newest);
    }

    public function test_default_retention_is_sixty_days_for_runs_and_fourteen_for_snapshots(): void
    {
        $this->assertSame(60, config('milcom.retention.recommendation_run_days'));
        $this->assertSame(14, config('milcom.retention.readiness_snapshot_days'));
        $this->assertSame(500, config('milcom.retention.batch_size'));
    }

    public function test_retention_comes_from_config(): void
    {
        config(['milcom.retention.recommendation_run_days' => 10]);
        [$operation] = $this->finishedOperationWithObjective();
        $old = $this->createRun($operation, null, ['created_at' => now()->subDays(12)]);
        $newest = $this->createRun($operation, null, ['created_at' => now()->subDays(11)]);

        $this->artisan('milcom:prune-history')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($newest);
    }

    /**
     * @return array{0: MilcomOperation, 1: MilcomObjective}
     */
    private function finishedOperationWithObjective(): array
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Completed]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create());

        return [$operation, $objective];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRun(MilcomOperation $operation, ?MilcomObjective $objective, array $overrides = []): MilcomRecommendationRun
    {
        $createdAt = $overrides['created_at'] ?? now();
        unset($overrides['created_at']);

        $run = MilcomRecommendationRun::query()->create(array_merge([
            'operation_id' => $operation->id,
            'objective_id' => $objective?->id,
            'status' => RecommendationRunStatus::Succeeded,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', uniqid('prune', true)),
            'trigger' => 'test',
            'progress_percent' => 100,
            'generation_version' => $operation->generation_version,
        ], $overrides));
        $run->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $run;
    }

    private function createRecommendation(MilcomRecommendationRun $run, MilcomObjective $objective): MilcomObjectiveRecommendation
    {
        return MilcomObjectiveRecommendation::query()->create([
            'recommendation_run_id' => $run->id,
            'objective_id' => $objective->id,
            'proposed_team' => [],
        ]);
    }
}
