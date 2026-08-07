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
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Services\Milcom\LifecycleReconciler;
use App\Services\Milcom\MilcomQueryService;
use App\Services\Milcom\OperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomLifecycleTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

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

    public function test_reconciliation_expires_undeclared_work_and_requeues_an_active_incident(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $attacked = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Active,
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Approved,
            'deadline_at' => now()->subMinute(),
            'open_key' => 1,
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Approved,
            'approved_at' => now()->subMinutes(5),
        ]);
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

        $this->assertSame(AssignmentStatus::Released, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Expired, $objective->fresh()->status);
        $this->assertNull($objective->fresh()->open_key);
        $this->assertSame(OperationStatus::Completed, $operation->fresh()->status);
        $this->assertSame(IncidentStatus::Countering, $incident->fresh()->status);
        $this->assertNotSame($objective->id, $incident->fresh()->objective_id);
        $this->assertSame(1, MilcomObjective::query()
            ->where('target_nation_id', $target->id)
            ->where('open_key', 1)
            ->count());
        $this->assertNull($incident->fresh()->coverage_reason);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'objective.expired',
        ]);
        Queue::assertPushed(
            GenerateMilcomRecommendationsJob::class,
            fn (GenerateMilcomRecommendationsJob $job): bool => $job->queue === null,
        );
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
