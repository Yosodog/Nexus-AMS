<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Enums\DiscordQueueStatus;
use App\Jobs\GenerateMilcomRecommendationsJob;
use App\Models\Alliance;
use App\Models\DiscordQueue;
use App\Models\MilcomDispatch;
use App\Models\MilcomEvent;
use App\Models\MilcomIncident;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Services\Milcom\LifecycleReconciler;
use App\Services\Milcom\MilcomQueryService;
use App\Services\Milcom\OperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomLifecycleTest extends TestCase
{
    use BuildsMilcomFixtures;
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();
    }

    public function test_reconciliation_links_a_declaration_then_completes_assignment_objective_and_operation(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Dispatched,
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Dispatched,
            'dispatched_at' => now(),
        ]);
        $incomingWar = $this->createWar(93_001, $target, $attacked);
        $incident = MilcomIncident::query()->create([
            'war_id' => $incomingWar->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now(),
        ]);
        $declaredWar = $this->createWar(93_002, $friendly, $target);
        $reconciler = app(LifecycleReconciler::class);

        $reconciler->reconcileAll();

        $this->assertSame(AssignmentStatus::Engaged, $assignment->fresh()->status);
        $this->assertSame($declaredWar->id, $assignment->fresh()->declared_war_id);
        $this->assertSame(ObjectiveStatus::Engaged, $objective->fresh()->status);

        WarAttack::query()->create([
            'id' => 940_001,
            'war_id' => $declaredWar->id,
            'att_id' => $friendly->id,
            'def_id' => $target->id,
            'type' => 'GROUND',
            'success' => 3,
        ]);
        WarAttack::query()->create([
            'id' => 940_002,
            'war_id' => $declaredWar->id,
            'att_id' => $target->id,
            'def_id' => $friendly->id,
            'type' => 'GROUND',
            'success' => 3,
        ]);
        $reconciler->recordAttack(940_001, $declaredWar->id);
        $reconciler->recordAttack(940_002, $declaredWar->id);
        $objectiveRow = app(MilcomQueryService::class)
            ->objectivesCursor($operation, ['filter' => 'all', 'limit' => 50])
            ->items()[0];

        $this->assertSame(1, $objectiveRow['attack_count']);
        $this->assertSame(1, $objectiveRow['successful_attack_count']);
        $this->assertFalse($objectiveRow['first_hit_overdue']);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'war.attack.outgoing.success.940001',
        ]);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'war.attack.incoming.940002',
        ]);

        $declaredWar->forceFill([
            'turns_left' => 0,
            'end_date' => now(),
            'winner_id' => $friendly->id,
        ])->save();
        $reconciler->reconcileAll();

        $this->assertSame(AssignmentStatus::Completed, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Completed, $objective->fresh()->status);
        $this->assertSame(OperationStatus::Completed, $operation->fresh()->status);
        $this->assertSame(IncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'assignment.engaged',
        ]);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'objective.completed',
        ]);
    }

    public function test_reconciliation_refreshes_stale_counter_recommendations_without_changing_work_ids(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'generated_at' => now()->subMinutes(31),
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Review,
            'deadline_at' => null,
            'open_key' => 1,
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Proposed,
            'is_locked' => true,
        ]);
        $run = $this->attachSuccessfulRecommendation($objective, [$friendly]);
        $run->forceFill(['finished_at' => now()->subMinutes(31)])->save();
        $incomingWar = $this->createWar(93_003, $target, $attacked);
        $incident = MilcomIncident::query()->create([
            'war_id' => $incomingWar->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subMinutes(10),
        ]);
        cache()->forever('alliances:membership:ids', [$friendlyAlliance->id]);
        Queue::fake();

        app(LifecycleReconciler::class)->reconcileAll();
        app(LifecycleReconciler::class)->reconcileAll();

        $this->assertDatabaseCount('milcom_operations', 1);
        $this->assertDatabaseCount('milcom_objectives', 1);
        $this->assertDatabaseCount('milcom_recommendation_runs', 2);
        $this->assertSame(2, $operation->fresh()->generation_version);
        $this->assertSame(2, $objective->fresh()->generation_version);
        $this->assertSame(AssignmentStatus::Proposed, $assignment->fresh()->status);
        $this->assertTrue($assignment->fresh()->is_locked);
        $this->assertSame(IncidentStatus::Countering, $incident->fresh()->status);
        $this->assertSame($objective->id, $incident->fresh()->objective_id);
        $this->assertSame('counter_auto_refresh', MilcomRecommendationRun::query()->latest('id')->firstOrFail()->trigger);
        Queue::assertPushed(
            GenerateMilcomRecommendationsJob::class,
            fn (GenerateMilcomRecommendationsJob $job): bool => $job->queue === null
                && $job->afterCommit === true,
        );
        Queue::assertPushed(GenerateMilcomRecommendationsJob::class, 1);
    }

    public function test_reconciliation_marks_a_dispatched_counter_overdue_without_releasing_it(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Active,
            'deadline_at' => now()->subMinute(),
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Dispatched,
            'deadline_at' => now()->subMinute(),
            'discord_channel_id' => '323456789012345678',
            'open_key' => 1,
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Dispatched,
            'approved_at' => now()->subMinutes(10),
            'dispatched_at' => now()->subMinutes(5),
        ]);
        $incomingWar = $this->createWar(93_004, $target, $attacked);
        $incident = MilcomIncident::query()->create([
            'war_id' => $incomingWar->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subMinutes(10),
        ]);
        Queue::fake();

        app(LifecycleReconciler::class)->reconcileAll();
        app(LifecycleReconciler::class)->reconcileAll();

        $this->assertDatabaseCount('milcom_operations', 1);
        $this->assertDatabaseCount('milcom_objectives', 1);
        $this->assertSame(AssignmentStatus::Dispatched, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Dispatched, $objective->fresh()->status);
        $this->assertSame('323456789012345678', $objective->fresh()->discord_channel_id);
        $this->assertNotNull($objective->fresh()->declaration_overdue_at);
        $this->assertSame($objective->id, $incident->fresh()->objective_id);
        $this->assertSame(IncidentStatus::Countering, $incident->fresh()->status);
        $this->assertSame(1, MilcomEvent::query()
            ->where('objective_id', $objective->id)
            ->where('event_type', 'objective.declaration_overdue')
            ->count());
        Queue::assertNothingPushed();
    }

    public function test_counter_refresh_skips_inactive_wars_and_federation_held_operations(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $inactiveTarget = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $heldTarget = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);

        $inactiveOperation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'generated_at' => now()->subMinutes(31),
        ]);
        $inactiveObjective = $this->createMilcomObjective($inactiveOperation, $inactiveTarget, [
            'status' => ObjectiveStatus::Review,
        ]);
        $inactiveRun = $this->attachSuccessfulRecommendation($inactiveObjective, []);
        $inactiveRun->forceFill(['finished_at' => now()->subMinutes(31)])->save();
        $inactiveWar = $this->createWar(93_005, $inactiveTarget, $attacked, [
            'turns_left' => 0,
            'end_date' => now()->subMinute(),
        ]);
        MilcomIncident::query()->create([
            'war_id' => $inactiveWar->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $inactiveTarget->id,
            'objective_id' => $inactiveObjective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subHour(),
        ]);

        $heldOperation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'generated_at' => now()->subMinutes(31),
            'federation_action_required' => true,
            'federation_held_at' => now()->subMinute(),
        ]);
        $heldObjective = $this->createMilcomObjective($heldOperation, $heldTarget, [
            'status' => ObjectiveStatus::Review,
        ]);
        $heldRun = $this->attachSuccessfulRecommendation($heldObjective, []);
        $heldRun->forceFill(['finished_at' => now()->subMinutes(31)])->save();
        $heldWar = $this->createWar(93_006, $heldTarget, $attacked);
        MilcomIncident::query()->create([
            'war_id' => $heldWar->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $heldTarget->id,
            'objective_id' => $heldObjective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subHour(),
        ]);
        Queue::fake();

        app(LifecycleReconciler::class)->reconcileAll();

        $this->assertSame(1, $inactiveOperation->fresh()->generation_version);
        $this->assertSame(1, $heldOperation->fresh()->generation_version);
        $this->assertDatabaseCount('milcom_recommendation_runs', 2);
        Queue::assertNothingPushed();
    }

    public function test_mass_plan_deadlines_still_expire_and_release_unreviewed_work(): void
    {
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Plan,
            'status' => OperationStatus::Review,
            'deadline_at' => now()->subMinute(),
        ]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Review,
            'deadline_at' => now()->subMinute(),
            'open_key' => 1,
        ]);
        $assignment = $this->createAssignment($objective, Nation::factory()->create());
        Queue::fake();

        app(LifecycleReconciler::class)->reconcileAll();

        $this->assertSame(AssignmentStatus::Released, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Expired, $objective->fresh()->status);
        $this->assertSame(OperationStatus::Completed, $operation->fresh()->status);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'objective.expired',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_officer_completion_releases_reservations_before_the_operation_can_be_archived(): void
    {
        $actor = $this->createMilcomManager();
        $friendly = Nation::factory()->create();
        $target = Nation::factory()->create();
        $attacked = Nation::factory()->create();
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Approved,
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Approved,
        ]);
        $war = $this->createWar(93_004, $target, $attacked);
        $incident = MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now(),
        ]);
        $service = app(OperationService::class);

        try {
            $service->archive($operation, $actor->id);
            $this->fail('An active operation was archived without completion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }

        $service->complete($operation, $actor->id);
        $service->archive($operation->fresh(), $actor->id);

        $this->assertSame(AssignmentStatus::Released, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Completed, $objective->fresh()->status);
        $this->assertSame(IncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertSame(OperationStatus::Archived, $operation->fresh()->status);

        $this->actingAs($actor)
            ->get(route('admin.milcom.archive.show', $operation))
            ->assertOk()
            ->assertSee($target->nation_name)
            ->assertSee($friendly->nation_name)
            ->assertSee('Released');
    }

    public function test_cancelling_a_counter_records_an_explicit_ignored_incident_reason(): void
    {
        $actor = $this->createMilcomManager();
        $target = Nation::factory()->create();
        $attacked = Nation::factory()->create();
        $operation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $objective = $this->createMilcomObjective($operation, $target, ['open_key' => 1]);
        $war = $this->createWar(93_005, $target, $attacked);
        $incident = MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $target->id,
            'objective_id' => $objective->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now(),
        ]);
        $reason = 'Officer confirmed coalition coverage outside the application.';

        app(OperationService::class)->cancelObjective($objective, 1, $actor->id, $reason);

        $this->assertSame(ObjectiveStatus::Cancelled, $objective->fresh()->status);
        $this->assertSame(IncidentStatus::Ignored, $incident->fresh()->status);
        $this->assertSame($reason, $incident->fresh()->ignored_reason);
        $this->assertSame(OperationStatus::Completed, $operation->fresh()->status);
    }

    public function test_terminal_room_is_archived_only_after_creation_queue_finishes(): void
    {
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Completed]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Completed,
            'dispatch_version' => 1,
            'discord_channel_id' => '123456789012345678',
        ]);
        $command = DiscordQueue::query()->create([
            'action' => 'WAR_ROOM_CREATE',
            'lane' => 'side_effects',
            'dedupe_scope' => 'milcom-lifecycle-test',
            'payload' => [],
            'status' => DiscordQueueStatus::Complete,
            'attempts' => 1,
            'available_at' => now(),
        ]);
        MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Sent,
            'dedupe_key' => "milcom-objective:{$objective->id}:room:v1",
            'queue_id' => $command->id,
            'payload_snapshot' => [],
            'external_channel_id' => $objective->discord_channel_id,
        ]);

        app(LifecycleReconciler::class)->reconcileAll();

        $this->assertDatabaseHas('milcom_dispatches', [
            'objective_id' => $objective->id,
            'dedupe_key' => "milcom-objective:{$objective->id}:archive:v1",
            'status' => DispatchStatus::Queued->value,
        ]);
        $this->assertDatabaseHas('discord_queue', [
            'action' => 'WAR_ROOM_ARCHIVE',
            'dedupe_key' => "milcom-objective:{$objective->id}:archive:v1",
            'status' => DiscordQueueStatus::Pending->value,
        ]);
    }
}
