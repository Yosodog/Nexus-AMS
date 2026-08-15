<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Enums\DiscordQueueStatus;
use App\Models\Alliance;
use App\Models\DiscordQueue;
use App\Models\MilcomAssignment;
use App\Models\MilcomDispatch;
use App\Models\MilcomEvent;
use App\Models\MilcomNationCapacityLock;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomApprovalAndDispatchTest extends TestCase
{
    use BuildsMilcomFixtures;
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
        config()->set('services.discord_bot_key', 'milcom-discord-test-token');
        config()->set('services.discord.guild_id', '123456789012345678');
        $this->configureDiscordQueueV2();
        $this->configureDiscordInteractionSigning();
    }

    public function test_warning_requires_a_reason_and_can_then_be_overridden(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $assignment = $this->createAssignment($objective, $friendly);
        $this->attachSuccessfulRecommendation($objective, [$friendly], [
            $friendly->id => ['discord_linked' => false],
        ]);
        $this->authenticateMilcomManager();

        $endpoint = "/api/v1/milcom/objectives/{$objective->id}/approve";
        $this->postJson($endpoint, ['generation_version' => 1])
            ->assertConflict()
            ->assertJsonPath('blockers.0.code', 'warning_override_required')
            ->assertJsonPath('warnings.0.code', 'missing_discord_link');

        $reason = 'Officer accepts the missing Discord account linkage.';
        $this->postJson($endpoint, [
            'generation_version' => 1,
            'override_reason' => $reason,
        ])->assertOk()
            ->assertJsonPath('data.objective.status', ObjectiveStatus::Approved->value)
            ->assertJsonPath('data.warnings.0.code', 'missing_discord_link');

        $this->assertSame(AssignmentStatus::Approved, $assignment->fresh()->status);
        $this->assertSame($reason, $assignment->fresh()->override_reason);
        $this->assertDatabaseHas('milcom_nation_capacity_locks', [
            'friendly_nation_id' => $friendly->id,
        ]);
    }

    public function test_override_reason_never_bypasses_a_hard_game_constraint(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $assignment = $this->createAssignment($objective, $friendly);
        $this->attachSuccessfulRecommendation($objective, [$friendly]);
        $target->forceFill(['beige_turns' => 12])->save();
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/objectives/{$objective->id}/approve", [
            'generation_version' => 1,
            'override_reason' => 'Officer requests dispatch despite the game constraint.',
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'target_beige');

        $this->assertSame(AssignmentStatus::Proposed, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $objective->fresh()->status);
    }

    public function test_attacked_nation_cannot_be_approved_for_its_own_counter(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $attacked = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $assignment = $this->createAssignment($objective, $attacked);
        $this->attachSuccessfulRecommendation($objective, [$attacked]);
        $this->createWar(935_000, $target, $attacked);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/objectives/{$objective->id}/approve", [
            'generation_version' => 1,
            'override_reason' => 'The attacked nation should still be blocked.',
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'duplicate_war');
        $this->postJson("/api/v1/milcom/objectives/{$objective->id}/assignments/manual", [
            'generation_version' => 1,
            'friendly_nation_id' => $attacked->id,
            'override_reason' => 'Manual selection must enforce the same hard rule.',
            'lock' => true,
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'duplicate_war');

        $this->assertSame(AssignmentStatus::Proposed, $assignment->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $objective->fresh()->status);
    }

    public function test_partial_counter_requires_both_force_selection_and_a_reason(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendlies = Nation::factory()->count(2)->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ])->all();
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target, [
            'desired_team_depth' => 3,
            'minimum_team_depth' => 3,
        ]);

        foreach ($friendlies as $rank => $friendly) {
            $this->createAssignment($objective, $friendly, ['rank' => $rank + 1]);
        }

        $this->attachSuccessfulRecommendation($objective, $friendlies);
        $this->authenticateMilcomManager();
        $endpoint = "/api/v1/milcom/objectives/{$objective->id}/approve";

        $this->postJson($endpoint, ['generation_version' => 1])
            ->assertConflict()
            ->assertJsonPath('blockers.0.code', 'minimum_team_depth');
        $this->postJson($endpoint, [
            'generation_version' => 1,
            'force_partial' => true,
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'partial_counter_requires_reason');
        $this->postJson($endpoint, [
            'generation_version' => 1,
            'force_partial' => true,
            'override_reason' => 'Two-member emergency counter approved by the duty officer.',
        ])->assertOk()
            ->assertJsonPath('data.objective.status', ObjectiveStatus::Approved->value);

        $this->assertSame(2, MilcomAssignment::query()
            ->where('objective_id', $objective->id)
            ->where('status', AssignmentStatus::Approved->value)
            ->count());
    }

    public function test_batch_approval_returns_successes_and_failures_independently(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $targets = Nation::factory()->count(2)->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $valid = $this->createMilcomObjective($operation, $targets[0]);
        $invalid = $this->createMilcomObjective($operation, $targets[1]);
        $this->createAssignment($valid, $friendly);
        $this->attachSuccessfulRecommendation($valid, [$friendly]);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/objectives/approve", [
            'generation_version' => 1,
            'objective_ids' => [$valid->id, $invalid->id],
        ])->assertOk()
            ->assertJsonPath('data.approved_objective_ids.0', $valid->id)
            ->assertJsonPath('data.failed.0.objective_id', $invalid->id)
            ->assertJsonPath('data.failed.0.blockers.0.code', 'no_assignments');

        $this->assertSame(ObjectiveStatus::Approved, $valid->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $invalid->fresh()->status);
    }

    public function test_approve_all_eligible_approves_ready_targets_and_skips_the_rest(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendlies = Nation::factory()->count(2)->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $targets = Nation::factory()->count(4)->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $ready = $this->createMilcomObjective($operation, $targets[0], ['priority_score' => 100]);
        $warning = $this->createMilcomObjective($operation, $targets[1], ['priority_score' => 80]);
        $unstaffed = $this->createMilcomObjective($operation, $targets[2], ['priority_score' => 60]);
        $hold = $this->createMilcomObjective($operation, $targets[3], [
            'priority_tier' => PriorityTier::Hold,
            'desired_team_depth' => 0,
            'minimum_team_depth' => 0,
            'priority_score' => 0,
        ]);

        $this->createAssignment($ready, $friendlies[0]);
        $this->attachSuccessfulRecommendation($ready, [$friendlies[0]]);
        $this->createAssignment($warning, $friendlies[1]);
        $this->attachSuccessfulRecommendation($warning, [$friendlies[1]], [
            $friendlies[1]->id => ['discord_linked' => false],
        ]);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/objectives/approve-eligible", [
            'generation_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.approved_objective_ids.0', $ready->id)
            ->assertJsonPath('data.failed.0.objective_id', $warning->id)
            ->assertJsonPath('data.failed.0.blockers.0.code', 'warning_override_required')
            ->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('meta.attempted_count', 2)
            ->assertJsonPath('meta.approved_count', 1)
            ->assertJsonPath('meta.skipped_count', 2)
            ->assertJsonPath('meta.remaining_count', 2)
            ->assertJsonPath('message', 'Approved 1 target. 2 targets still need review.');

        $this->assertSame(ObjectiveStatus::Approved, $ready->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $warning->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $unstaffed->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $hold->fresh()->status);
    }

    public function test_approve_all_with_warnings_approves_reviewable_targets_but_never_hard_blockers(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendlies = Nation::factory()->count(2)->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $targets = Nation::factory()->count(2)->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $warned = $this->createMilcomObjective($operation, $targets[0], ['priority_score' => 100]);
        $hardBlocked = $this->createMilcomObjective($operation, $targets[1], ['priority_score' => 90]);
        $warnedAssignment = $this->createAssignment($warned, $friendlies[0]);
        $hardBlockedAssignment = $this->createAssignment($hardBlocked, $friendlies[1]);
        $this->attachSuccessfulRecommendation($warned, [$friendlies[0]], [
            $friendlies[0]->id => ['discord_linked' => false],
        ]);
        $this->attachSuccessfulRecommendation($hardBlocked, [$friendlies[1]]);
        $targets[1]->forceFill(['vacation_mode_turns' => 12])->save();
        $this->authenticateMilcomManager();
        $endpoint = "/api/v1/milcom/operations/{$operation->id}/objectives/approve-reviewable";

        $this->postJson($endpoint, ['generation_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['override_reason']);

        $reason = 'Duty officer accepts the inactivity and delivery warnings for this wave.';
        $this->postJson($endpoint, [
            'generation_version' => 1,
            'override_reason' => $reason,
        ])->assertOk()
            ->assertJsonPath('data.approved_objective_ids.0', $warned->id)
            ->assertJsonPath('data.failed.0.objective_id', $hardBlocked->id)
            ->assertJsonPath('data.failed.0.blockers.0.code', 'vacation_mode')
            ->assertJsonPath('meta.approved_count', 1)
            ->assertJsonPath('meta.remaining_count', 1);

        $this->assertSame(ObjectiveStatus::Approved, $warned->fresh()->status);
        $this->assertSame($reason, $warnedAssignment->fresh()->override_reason);
        $this->assertSame(ObjectiveStatus::Review, $hardBlocked->fresh()->status);
        $this->assertSame(AssignmentStatus::Proposed, $hardBlockedAssignment->fresh()->status);
    }

    /**
     * SQLite verifies the serialized reservation invariant here. It does not implement
     * SELECT ... FOR UPDATE, so true concurrent interleaving remains a MySQL-only test.
     */
    public function test_existing_reservation_prevents_a_second_approval_from_exhausting_capacity(): void
    {
        config()->set('milcom.game_rules.base_offensive_slots', 1);
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $targets = Nation::factory()->count(2)->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $first = $this->createMilcomObjective($operation, $targets[0]);
        $second = $this->createMilcomObjective($operation, $targets[1]);
        $this->createAssignment($first, $friendly);
        $this->createAssignment($second, $friendly);
        $this->attachSuccessfulRecommendation($first, [$friendly]);
        $this->attachSuccessfulRecommendation($second, [$friendly]);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/objectives/{$first->id}/approve", [
            'generation_version' => 1,
        ])->assertOk();
        $this->postJson("/api/v1/milcom/objectives/{$second->id}/approve", [
            'generation_version' => 1,
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'no_offensive_slot');

        $this->assertSame(1, MilcomAssignment::query()
            ->where('friendly_nation_id', $friendly->id)
            ->where('status', AssignmentStatus::Approved->value)
            ->count());
        $this->assertSame(1, MilcomNationCapacityLock::query()
            ->where('friendly_nation_id', $friendly->id)
            ->count());
    }

    public function test_dispatch_and_signed_room_callback_are_idempotent(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'discord_forum_id' => '223456789012345678',
            'deadline_at' => null,
        ]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $this->createAssignment($objective, $friendly);
        $this->attachSuccessfulRecommendation($objective, [$friendly]);
        $this->authenticateMilcomManager();
        $endpoint = "/api/v1/milcom/objectives/{$objective->id}/dispatch";
        $payload = ['generation_version' => 1];

        $first = $this->postJson($endpoint, $payload)->assertOk();
        $dispatchId = $first->json('data.dispatch.id');
        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('data.dispatch.id', $dispatchId);

        $dispatch = MilcomDispatch::query()->findOrFail($dispatchId);
        $this->assertDatabaseCount('milcom_dispatches', 1);
        $this->assertDatabaseCount('discord_queue', 1);
        $this->assertSame(
            "milcom-objective:{$objective->id}:room:v1",
            $dispatch->dedupe_key,
        );
        $this->assertSame('WAR_ROOM_CREATE', DiscordQueue::query()->sole()->action);

        $callbackPayload = [
            'objective_id' => $objective->id,
            'dispatch_id' => $dispatch->id,
            'discord_channel_id' => '323456789012345678',
        ];
        $headers = $this->signedDiscordServiceHeaders(
            'milcom-discord-test-token',
            '123456789012345678',
            'milcom.objectives.attach-room',
        );

        $this->withHeaders($headers)
            ->postJson('/api/v1/discord/milcom/objectives/attach-room', $callbackPayload)
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', false);
        $declarationDeadline = $objective->fresh()->deadline_at;
        $this->assertNotNull($declarationDeadline);
        $this->assertTrue($declarationDeadline->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertTrue($declarationDeadline->equalTo($operation->fresh()->deadline_at));
        $this->travel(5)->minutes();
        $this->withHeaders($headers)
            ->postJson('/api/v1/discord/milcom/objectives/attach-room', $callbackPayload)
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame('323456789012345678', $objective->fresh()->discord_channel_id);
        $this->assertTrue($declarationDeadline->equalTo($objective->fresh()->deadline_at));
        $this->assertSame(DispatchStatus::Sent, $dispatch->fresh()->status);
        $this->assertSame(1, MilcomEvent::query()
            ->where('event_type', 'objective.discord_room_attached')
            ->count());
    }

    public function test_live_wave_can_create_every_remaining_discord_room_at_once(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendlies = Nation::factory()->count(2)->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $targets = Nation::factory()->count(2)->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'discord_forum_id' => '223456789012345678',
        ]);
        $this->addFriendlyScope($operation, $friendlyAlliance);

        foreach ($targets as $index => $target) {
            $objective = $this->createMilcomObjective($operation, $target);
            $this->createAssignment($objective, $friendlies[$index], [
                'status' => AssignmentStatus::Approved,
                'approved_at' => now(),
            ]);
            $this->attachSuccessfulRecommendation($objective, [$friendlies[$index]]);
            $objective->forceFill([
                'status' => ObjectiveStatus::Approved,
                'approved_at' => now(),
            ])->save();
        }

        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/dispatch-ready", [
            'generation_version' => 1,
        ])->assertOk()
            ->assertJsonPath('meta.attempted_count', 2)
            ->assertJsonPath('meta.dispatched_count', 2)
            ->assertJsonCount(2, 'data.dispatched_objective_ids')
            ->assertJsonPath('message', 'Queued rooms for 2 targets.');

        $this->assertDatabaseCount('milcom_dispatches', 2);
        $this->assertDatabaseCount('discord_queue', 2);
    }

    public function test_failed_dispatch_retry_reuses_the_original_queue_checkpoint(): void
    {
        [$friendlyAlliance, $enemyAlliance] = $this->alliances();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
            'num_cities' => 10,
        ]);
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'discord_forum_id' => '223456789012345678',
        ]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $this->createAssignment($objective, $friendly);
        $this->attachSuccessfulRecommendation($objective, [$friendly]);
        $this->authenticateMilcomManager();

        $response = $this->postJson("/api/v1/milcom/objectives/{$objective->id}/dispatch", [
            'generation_version' => 1,
        ])->assertOk();
        $dispatch = MilcomDispatch::query()->findOrFail($response->json('data.dispatch.id'));
        $queueItem = DiscordQueue::query()->findOrFail($dispatch->queue_id);
        $queueItem->forceFill([
            'status' => DiscordQueueStatus::Failed,
            'attempts' => 3,
            'result' => ['discord_channel_id' => '323456789012345678'],
            'last_error' => ['code' => 'discord_send_failed', 'message' => 'Follow-up message failed.'],
        ])->save();
        $dispatch->forceFill([
            'status' => DispatchStatus::Failed,
            'errors' => $queueItem->last_error,
            'failed_at' => now(),
        ])->save();

        $this->postJson("/api/v1/milcom/objectives/{$objective->id}/dispatch/retry", [
            'generation_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.dispatch.id', $dispatch->id)
            ->assertJsonPath('data.dispatch.queue_id', $queueItem->id);

        $this->assertDatabaseCount('milcom_dispatches', 1);
        $this->assertDatabaseCount('discord_queue', 1);
        $this->assertSame(DispatchStatus::Queued, $dispatch->fresh()->status);
        $this->assertSame(DiscordQueueStatus::Pending, $queueItem->fresh()->status);
        $this->assertSame(0, $queueItem->fresh()->attempts);
        $this->assertSame('323456789012345678', data_get($queueItem->fresh()->result, 'discord_channel_id'));
        $this->assertSame(1, $objective->fresh()->dispatch_version);
        $this->assertDatabaseHas('milcom_events', [
            'objective_id' => $objective->id,
            'event_type' => 'objective.discord_retry_queued',
        ]);
    }

    public function test_mysql_lock_interleaving_is_explicitly_outside_the_sqlite_suite(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'SQLite does not implement SELECT ... FOR UPDATE; process-level concurrent approval requires MySQL.'
            );
        }

        $this->markTestSkipped('Run the dedicated multi-process capacity-lock scenario in the MySQL integration job.');
    }

    /** @return array{Alliance, Alliance} */
    private function alliances(): array
    {
        return [Alliance::factory()->create(), Alliance::factory()->create()];
    }
}
