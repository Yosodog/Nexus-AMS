<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\Alliance;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Services\Milcom\ReadinessSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomPerformanceBudgetTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
    }

    public function test_large_lists_return_only_fifty_rows_and_compact_json(): void
    {
        $manager = $this->createMilcomManager();
        $this->actingAs($manager);
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $targets = Nation::factory()->count(75)->create(['alliance_id' => $enemyAlliance->id]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);

        foreach ($targets as $index => $target) {
            $this->createMilcomObjective($operation, $target, [
                'priority_tier' => $index < 10 ? PriorityTier::Critical : PriorityTier::Standard,
                'priority_score' => 100 - $index,
                'status' => ObjectiveStatus::Review,
            ]);
        }

        $json = $this->getJson("/api/v1/milcom/operations/{$operation->id}/objectives?filter=all&limit=100")
            ->assertOk()
            ->assertJsonCount(50, 'data.objectives');
        $this->assertLessThan(250_000, strlen($json->getContent()));

        $page = $this->get(route('admin.milcom.plans.show', $operation))->assertOk();
        $this->assertSame(50, substr_count($page->getContent(), 'data-milcom-objective-row'));
    }

    public function test_plan_csv_exports_targets_assignments_and_escapes_spreadsheet_formulas(): void
    {
        $manager = $this->createMilcomManager();
        $this->actingAs($manager);
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'nation_name' => 'Friendly Export Nation',
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'nation_name' => '=HYPERLINK("bad")',
        ]);
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'metadata' => ['wave' => 3],
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Approved,
        ]);
        $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Approved,
        ]);

        $response = $this->get(route('admin.milcom.plans.export', $operation))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Friendly Export Nation', $csv);
        $this->assertStringContainsString("https://politicsandwar.com/nation/id={$target->id}", $csv);
        $this->assertStringContainsString("https://politicsandwar.com/nation/id={$friendly->id}", $csv);
    }

    public function test_snapshot_candidate_query_count_is_constant_as_the_friendly_pool_grows(): void
    {
        $alliance = Alliance::factory()->create();
        $friendlies = Nation::factory()->count(100)->create(['alliance_id' => $alliance->id]);
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation();
        $service = app(ReadinessSnapshotService::class);
        $smallRun = $this->createRun($operation->id, 1);
        $largeRun = $this->createRun($operation->id, 2);

        $smallQueries = $this->captureQueryCount(
            $service,
            $smallRun,
            $friendlies->take(5)->pluck('id')->map('intval')->all(),
            (int) $target->id,
        );
        $largeQueries = $this->captureQueryCount(
            $service,
            $largeRun,
            $friendlies->pluck('id')->map('intval')->all(),
            (int) $target->id,
        );

        $this->assertSame($smallQueries, $largeQueries);
        $this->assertLessThanOrEqual(10, $largeQueries);
    }

    public function test_large_snapshot_capture_writes_bounded_chunks(): void
    {
        $alliance = Alliance::factory()->create();
        $nations = Nation::factory()->count(450)->create(['alliance_id' => $alliance->id]);
        $operation = $this->createMilcomOperation();
        $run = $this->createRun($operation->id, 3);
        $snapshotInsertQueries = 0;

        DB::listen(function ($query) use (&$snapshotInsertQueries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert')
                && str_contains($query->sql, 'milcom_readiness_snapshots')) {
                $snapshotInsertQueries++;
            }
        });

        $profiles = app(ReadinessSnapshotService::class)->capture(
            $run,
            $nations->take(150)->pluck('id')->map('intval')->all(),
            $nations->skip(150)->pluck('id')->map('intval')->all(),
            CarbonImmutable::now(),
        );

        $this->assertCount(450, $profiles);
        $this->assertDatabaseCount('milcom_readiness_snapshots', 450);
        $this->assertSame(3, $snapshotInsertQueries);
        $this->assertEqualsCanonicalizing(
            array_keys((array) config('milcom.game_rules.offensive_slot_projects')),
            array_keys($profiles[(int) $nations->first()->id]->projects),
        );
    }

    private function createRun(int $operationId, int $salt): MilcomRecommendationRun
    {
        return MilcomRecommendationRun::query()->create([
            'operation_id' => $operationId,
            'status' => RecommendationRunStatus::Running,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', "performance-run-{$salt}"),
            'trigger' => 'test',
            'progress_percent' => 0,
            'generation_version' => 1,
            'objectives_total' => 1,
        ]);
    }

    /**
     * @param  list<int>  $friendlyIds
     */
    private function captureQueryCount(
        ReadinessSnapshotService $service,
        MilcomRecommendationRun $run,
        array $friendlyIds,
        int $targetId,
    ): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->capture($run, $friendlyIds, [$targetId], CarbonImmutable::now());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
