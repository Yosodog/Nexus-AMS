<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Jobs\GenerateMilcomRecommendationsJob;
use App\Jobs\SendMilcomAssignmentMessageJob;
use App\Models\DiscordQueue;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomDispatch;
use App\Models\MilcomObjective;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordQueueLeaseService;
use App\Services\Milcom\AssignmentDeliveryService;
use App\Services\Milcom\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class FederationHoldEnforcementFeatureTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
        config()->set('services.discord_bot_key', 'hold-discord-test-token');
        config()->set('services.discord.guild_id', '123456789012345678');
        $this->configureDiscordInteractionSigning();
    }

    public function test_held_error_is_stable_and_does_not_include_the_hold_reason(): void
    {
        $operation = $this->createMilcomOperation([
            'federation_action_required' => true,
            'federation_hold_reason' => 'private-source-payload-detail',
        ]);

        try {
            app(FederationOperationGuard::class)->assertMutable($operation, 'test_mutation');
            $this->fail('A held operation was treated as mutable.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                FederationOperationGuard::HELD_ERROR_MESSAGE,
                $exception->errors()['operation'][0],
            );
            $this->assertSame(
                FederationOperationGuard::HELD_ERROR_CODE,
                $exception->errors()['federation_error'][0],
            );
            $this->assertStringNotContainsString(
                'private-source-payload-detail',
                json_encode($exception->errors(), JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_v2_claims_suppress_held_milcom_commands_but_claim_unrelated_work(): void
    {
        [$operation, $objective] = $this->heldOperation();
        $heldCommand = $this->createMilcomRoomCommand($operation, $objective);
        $unrelated = $this->createQueueCommand('CITY_TIER_SYNC');

        $claimed = app(DiscordQueueLeaseService::class)->claim(
            '11111111-2222-4333-8444-555555555555',
            '11111111-2222-4333-8444-555555555556',
            [DiscordQueueLane::SideEffects],
            $this->connection(),
        );

        $this->assertNotNull($claimed);
        $this->assertSame($unrelated->id, $claimed->id);
        $this->assertSame(DiscordQueueStatus::Processing, $claimed->fresh()->status);
        $this->assertSame(DiscordQueueStatus::Failed, $heldCommand->fresh()->status);
        $this->assertSame(
            FederationOperationGuard::HELD_ERROR_CODE,
            $heldCommand->fresh()->last_error['code'],
        );
        $this->assertSame(
            FederationOperationGuard::HELD_ERROR_MESSAGE,
            $heldCommand->fresh()->last_error['message'],
        );

        [$secondOperation, $secondObjective] = $this->heldOperation();
        $secondHeldCommand = $this->createMilcomRoomCommand($secondOperation, $secondObjective);
        $secondUnrelated = $this->createQueueCommand('CITY_TIER_SYNC');

        $secondClaim = app(DiscordQueueLeaseService::class)->claim(
            '21111111-2222-4333-8444-555555555555',
            '21111111-2222-4333-8444-555555555556',
            [DiscordQueueLane::SideEffects],
            $this->connection(),
        );

        $this->assertSame($secondUnrelated->id, $secondClaim?->id);
        $this->assertSame(DiscordQueueStatus::Failed, $secondHeldCommand->fresh()->status);
    }

    public function test_held_leased_command_is_rejected_before_renewal_and_reaped_as_terminal(): void
    {
        [$operation, $objective] = $this->heldOperation();
        $command = $this->createMilcomRoomCommand($operation, $objective, [
            'status' => DiscordQueueStatus::Processing,
            'lease_token' => '11111111-2222-4333-8444-555555555555',
            'leased_until' => Carbon::now()->addMinute(),
        ]);

        try {
            app(DiscordQueueLeaseService::class)->renew(
                $command,
                (string) $command->lease_token,
                $this->connection(),
            );
            $this->fail('A held Discord command lease was renewed.');
        } catch (DiscordQueueLeaseException $exception) {
            $this->assertSame(FederationOperationGuard::HELD_ERROR_CODE, $exception->error);
            $this->assertSame(FederationOperationGuard::HELD_ERROR_MESSAGE, $exception->getMessage());
        }

        $this->assertSame(DiscordQueueStatus::Failed, $command->fresh()->status);

        [$secondOperation, $secondObjective] = $this->heldOperation();
        $expired = $this->createMilcomRoomCommand($secondOperation, $secondObjective, [
            'status' => DiscordQueueStatus::Processing,
            'lease_token' => '11111111-2222-4333-8444-555555555557',
            'leased_until' => Carbon::now()->subSecond(),
        ]);

        $this->assertSame(1, app(DiscordQueueLeaseService::class)->reapExpiredLeases());
        $this->assertSame(DiscordQueueStatus::Failed, $expired->fresh()->status);
        $this->assertSame(
            FederationOperationGuard::HELD_ERROR_CODE,
            $expired->fresh()->last_error['code'],
        );
    }

    public function test_recommendation_job_supersedes_a_queued_run_under_a_federation_hold(): void
    {
        $operation = $this->createMilcomOperation([
            'federation_action_required' => true,
            'federation_hold_reason' => 'resource_revoked',
        ]);
        $run = MilcomRecommendationRun::query()->create([
            'operation_id' => $operation->id,
            'status' => RecommendationRunStatus::Queued,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', 'federation-hold-job'),
            'trigger' => 'test',
            'progress_percent' => 0,
            'generation_version' => $operation->generation_version,
            'objectives_total' => 1,
        ]);
        $engine = $this->mock(RecommendationEngine::class);
        $engine->shouldNotReceive('execute');

        (new GenerateMilcomRecommendationsJob($run->id))->handle($engine);

        $this->assertSame(RecommendationRunStatus::Superseded, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_assignment_delivery_job_marks_a_held_delivery_without_sending(): void
    {
        $friendly = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'federation_action_required' => true,
            'federation_hold_reason' => 'resource_expired',
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Dispatched,
        ]);
        $assignment = $this->createAssignment($objective, $friendly);
        $delivery = MilcomAssignmentDelivery::query()->create([
            'operation_id' => $operation->id,
            'assignment_id' => $assignment->id,
            'channel' => 'in_game',
            'status' => 'pending',
            'dedupe_key' => 'federation-hold-delivery-'.$assignment->id,
            'subject' => 'Held delivery',
            'payload_snapshot' => [
                'nation_id' => $friendly->id,
                'message' => 'private payload must never be sent',
            ],
            'queued_at' => now(),
        ]);
        $deliveries = $this->mock(AssignmentDeliveryService::class);
        $deliveries->shouldNotReceive('deliver');

        (new SendMilcomAssignmentMessageJob($delivery->id))->handle(
            $deliveries,
            app(FederationOperationGuard::class),
        );

        $delivery = $delivery->fresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(FederationOperationGuard::HELD_ERROR_CODE, $delivery->last_error);
        $this->assertNull($delivery->sent_at);
    }

    public function test_alternative_selection_is_rejected_before_direct_assignment_mutation(): void
    {
        $friendly = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation([
            'federation_action_required' => true,
            'federation_hold_reason' => 'coalition_removed',
        ]);
        $objective = $this->createMilcomObjective($operation, $target);
        $run = MilcomRecommendationRun::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'status' => RecommendationRunStatus::Succeeded,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', 'federation-hold-alternative'),
            'trigger' => 'test',
            'progress_percent' => 100,
            'generation_version' => $operation->generation_version,
            'objectives_total' => 1,
            'objectives_processed' => 1,
        ]);
        MilcomObjectiveRecommendation::query()->create([
            'recommendation_run_id' => $run->id,
            'objective_id' => $objective->id,
            'team_score' => 90,
            'confidence' => 90,
            'proposed_team' => ['nation_ids' => [$friendly->id]],
            'alternatives' => [['nation_ids' => [$friendly->id], 'score' => 90]],
        ]);
        $objective->forceFill(['latest_recommendation_run_id' => $run->id])->save();
        $this->authenticateMilcomManager();

        $response = $this->putJson(
            "/api/v1/milcom/objectives/{$objective->id}/assignments",
            ['generation_version' => 1, 'alternative_index' => 0],
        );

        $response->assertUnprocessable()
            ->assertJsonPath(
                'errors.federation_error.0',
                FederationOperationGuard::HELD_ERROR_CODE,
            );
        $this->assertDatabaseCount('milcom_assignments', 0);
    }

    public function test_discord_room_callback_is_rejected_before_attaching_a_held_operation(): void
    {
        [$operation, $objective] = $this->heldOperation([
            'status' => OperationStatus::Active,
        ], [
            'status' => ObjectiveStatus::Dispatched,
            'dispatch_version' => 1,
        ]);
        $dispatch = MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Queued,
            'dedupe_key' => 'federation-hold-callback-'.$objective->id,
            'payload_snapshot' => [],
            'queued_at' => now(),
        ]);

        $response = $this->withHeaders($this->signedDiscordServiceHeaders(
            'hold-discord-test-token',
            '123456789012345678',
            'milcom.objectives.attach-room',
        ))->postJson('/api/v1/discord/milcom/objectives/attach-room', [
            'objective_id' => $objective->id,
            'dispatch_id' => $dispatch->id,
            'discord_channel_id' => '323456789012345678',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath(
                'error.details.federation_error.0',
                FederationOperationGuard::HELD_ERROR_CODE,
            );
        $this->assertNull($objective->fresh()->discord_channel_id);
        $this->assertSame(DispatchStatus::Queued, $dispatch->fresh()->status);
    }

    /** @return array{0: MilcomOperation, 1: MilcomObjective} */
    private function heldOperation(array $operationAttributes = [], array $objectiveAttributes = []): array
    {
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation(array_merge([
            'federation_action_required' => true,
            'federation_hold_reason' => 'resource_revoked',
        ], $operationAttributes));
        $objective = $this->createMilcomObjective($operation, $target, $objectiveAttributes);

        return [$operation, $objective];
    }

    private function createMilcomRoomCommand(
        MilcomOperation $operation,
        MilcomObjective $objective,
        array $attributes = [],
    ): DiscordQueue {
        $command = $this->createQueueCommand('WAR_ROOM_CREATE', array_merge([
            'priority' => 100,
            'payload' => [
                'source' => [
                    'type' => 'milcom_objective',
                    'operation_id' => $operation->id,
                    'id' => $objective->id,
                ],
            ],
        ], $attributes));
        MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Queued,
            'queue_id' => $command->id,
            'dedupe_key' => 'federation-hold-command-'.$command->id,
            'payload_snapshot' => [],
        ]);

        return $command;
    }

    private function createQueueCommand(string $action, array $attributes = []): DiscordQueue
    {
        $queueAction = DiscordQueueAction::from($action);

        return DiscordQueue::query()->create(array_merge([
            'action' => $action,
            'payload' => ['message' => 'unrelated test command'],
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => Carbon::now(),
            'lane' => $queueAction->allowedLanes()[0],
            'priority' => 50,
            'connection_id' => $this->connection()->connectionId,
            'application_id' => $this->connection()->applicationId,
            'connection_generation' => $this->connection()->generation,
            'guild_id' => $this->connection()->guildId,
            'dedupe_scope' => $this->connection()->dedupeScope(),
        ], $attributes));
    }

    private function connection(): DiscordConnectionContext
    {
        return new DiscordConnectionContext(
            connectionId: '31111111-2222-4333-8444-555555555555',
            mode: DiscordConnectionMode::Dedicated,
            applicationId: '223456789012345678',
            guildId: '123456789012345678',
            generation: 7,
            protocolVersion: 2,
            relayCurrentKeyId: 'relay-current',
            relayCurrentPublicKey: str_repeat('a', 43),
            capabilities: [
                'capabilities' => ['relay.proof.v2', 'queue.connection-context.v1'],
                'supported_queue_actions' => array_column(DiscordQueueAction::cases(), 'value'),
            ],
        );
    }
}
