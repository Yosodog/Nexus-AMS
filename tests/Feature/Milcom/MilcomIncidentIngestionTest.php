<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Jobs\GenerateMilcomRecommendationsJob;
use App\Models\Alliance;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomIncidentIngestionTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
        config()->set('services.nexus_api_token', 'testing-nexus-token');
        Queue::fake();
    }

    public function test_duplicate_incoming_war_ingestion_persists_one_incident_and_queues_one_high_priority_run(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $aggressor = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $payload = $this->incomingWarPayload(92_001, $aggressor, $attacked);

        $this->postIncomingWar($payload)->assertOk();
        $this->postIncomingWar($payload)->assertOk();

        $this->assertDatabaseCount('war_declaration_receipts', 1);
        $this->assertDatabaseCount('milcom_incidents', 1);
        $this->assertDatabaseCount('milcom_objectives', 1);
        $this->assertDatabaseCount('milcom_recommendation_runs', 1);
        $this->assertSame(OperationType::Counter, MilcomOperation::query()->sole()->type);
        $this->assertSame(IncidentStatus::Countering, MilcomIncident::query()->sole()->status);
        $this->assertSame('incoming_war', MilcomRecommendationRun::query()->sole()->trigger);

        Queue::assertPushed(GenerateMilcomRecommendationsJob::class, 1);
        Queue::assertPushed(
            GenerateMilcomRecommendationsJob::class,
            fn (GenerateMilcomRecommendationsJob $job): bool => $job->queue === null,
        );
    }

    public function test_incident_is_linked_to_an_active_plan_only_when_minimum_reserved_coverage_exists(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $firstFriendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $secondFriendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $aggressor = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Plan,
            'status' => OperationStatus::Active,
        ]);
        $objective = $this->createMilcomObjective($operation, $aggressor, [
            'priority_tier' => PriorityTier::Critical,
            'status' => ObjectiveStatus::Dispatched,
            'desired_team_depth' => 3,
            'minimum_team_depth' => 2,
        ]);
        $this->createAssignment($objective, $firstFriendly, ['status' => AssignmentStatus::Approved]);
        $this->createAssignment($objective, $secondFriendly, ['status' => AssignmentStatus::Dispatched]);

        $this->postIncomingWar($this->incomingWarPayload(92_002, $aggressor, $attacked))->assertOk();

        $incident = MilcomIncident::query()->sole();
        $this->assertSame(IncidentStatus::CoveredByPlan, $incident->status);
        $this->assertSame($objective->id, $incident->objective_id);
        $this->assertStringContainsString('enough approved nations or Discord rooms', $incident->coverage_reason);
        $this->assertDatabaseCount('milcom_operations', 1);
        $this->assertDatabaseCount('milcom_recommendation_runs', 0);
        Queue::assertNotPushed(GenerateMilcomRecommendationsJob::class);
    }

    public function test_distinct_incoming_wars_from_one_aggressor_reuse_the_same_open_counter(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $firstAttacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $secondAttacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $aggressor = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);

        $this->postIncomingWar($this->incomingWarPayload(92_003, $aggressor, $firstAttacked))->assertOk();
        $this->postIncomingWar($this->incomingWarPayload(92_004, $aggressor, $secondAttacked))->assertOk();

        $incidents = MilcomIncident::query()->orderBy('id')->get();
        $objective = MilcomObjective::query()->sole();

        $this->assertCount(2, $incidents);
        $this->assertSame([$objective->id], $incidents->pluck('objective_id')->unique()->values()->all());
        $this->assertSame(MilcomObjective::OPEN_KEY_VALUE, $objective->open_key);
        $this->assertSame(1, MilcomOperation::query()->where('type', OperationType::Counter->value)->count());
    }

    /** @return array{Alliance, Alliance} */
    private function alliances(): array
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        cache()->forever('alliances:membership:ids', [$friendlyAlliance->id]);

        return [$friendlyAlliance, $enemyAlliance];
    }
}
