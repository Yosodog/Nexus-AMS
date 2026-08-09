<?php

namespace Tests\Feature\Discord;

use App\Enums\ApplicationStatus;
use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Models\Application;
use App\Models\DiscordConnection;
use App\Models\DiscordQueue;
use App\Services\Discord\ApplicationDiscordReconciliationException;
use App\Services\Discord\ApplicationDiscordReconciliationService;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Discord\DiscordQueueLeaseService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationDiscordReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION_ID = '019fe8de-d604-7b0e-95eb-eb70429b8f6a';

    private const APPLICATION_ID = '123456789012345678';

    private const GUILD_ID = '223456789012345678';

    private const USER_ID = '323456789012345678';

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::setApplicationsDiscordApplicantRoleId('423456789012345678');
        SettingService::setApplicationsDiscordIaRoleId('523456789012345678');
        SettingService::setApplicationsDiscordMemberRoleId('623456789012345678');
        SettingService::setApplicationsDiscordInterviewCategoryId('723456789012345678');
        SettingService::setApplicationsApprovalAnnouncementChannelId('823456789012345678');
        SettingService::setApplicationsApprovalMessageTemplate('A new member was approved in Nexus.');
    }

    public function test_it_queues_and_reuses_one_connection_scoped_reconciliation_revision(): void
    {
        $connection = $this->connection();
        $application = $this->application();

        $first = app(ApplicationDiscordReconciliationService::class)->reconcile($application);
        $second = app(ApplicationDiscordReconciliationService::class)->reconcile($first);

        $this->assertSame(1, $second->discord_reconcile_revision);
        $this->assertSame($first->discord_reconcile_queue_id, $second->discord_reconcile_queue_id);
        $this->assertSame(self::CONNECTION_ID, $second->discord_connection_id);
        $this->assertSame(7, $second->discord_connection_generation);
        $this->assertSame(self::APPLICATION_ID, $second->discord_application_id);
        $this->assertSame(self::GUILD_ID, $second->discord_guild_id);
        $this->assertDatabaseCount('discord_queue', 1);

        $queue = DiscordQueue::query()->findOrFail($second->discord_reconcile_queue_id);
        $this->assertSame(ApplicationDiscordReconciliationService::ACTION, $queue->action);
        $this->assertSame(DiscordQueueLane::SideEffects, $queue->lane);
        $this->assertSame(DiscordQueueStatus::Pending, $queue->status);
        $this->assertSame($connection->id, $queue->connection_id);
        $this->assertSame($connection->generation, $queue->connection_generation);
        $this->assertSame($connection->application_id, $queue->application_id);
        $this->assertSame($connection->guild_id, $queue->guild_id);
        $this->assertSame($connection->id.':'.$connection->generation, $queue->dedupe_scope);
        $this->assertSame(1, data_get($queue->payload, 'application.revision'));
    }

    public function test_forced_repair_supersedes_pending_work_and_suppresses_one_time_messages(): void
    {
        $this->connection();
        $application = app(ApplicationDiscordReconciliationService::class)->reconcile($this->application());
        $firstQueue = DiscordQueue::query()->findOrFail($application->discord_reconcile_queue_id);

        $repaired = app(ApplicationDiscordReconciliationService::class)->reconcile($application, force: true);

        $this->assertSame(2, $repaired->discord_reconcile_revision);
        $this->assertNotSame($firstQueue->id, $repaired->discord_reconcile_queue_id);
        $this->assertSame(DiscordQueueStatus::Failed, $firstQueue->fresh()->status);
        $this->assertSame('superseded_application_revision', $firstQueue->fresh()->last_error['code']);
        $this->assertSame(
            [],
            data_get(DiscordQueue::query()->findOrFail($repaired->discord_reconcile_queue_id)->payload, 'desired.channel.intro_messages'),
        );
    }

    public function test_failed_work_is_replaced_but_completed_matching_work_is_reused(): void
    {
        $this->connection();
        $application = app(ApplicationDiscordReconciliationService::class)->reconcile($this->application());
        $queue = DiscordQueue::query()->findOrFail($application->discord_reconcile_queue_id);
        $queue->forceFill(['status' => DiscordQueueStatus::Failed])->save();

        $retried = app(ApplicationDiscordReconciliationService::class)->reconcile($application->fresh());
        $replacement = DiscordQueue::query()->findOrFail($retried->discord_reconcile_queue_id);
        $replacement->forceFill(['status' => DiscordQueueStatus::Complete])->save();

        $completed = app(ApplicationDiscordReconciliationService::class)->reconcile($retried->fresh());

        $this->assertSame(2, $completed->discord_reconcile_revision);
        $this->assertSame($replacement->id, $completed->discord_reconcile_queue_id);
        $this->assertDatabaseCount('discord_queue', 2);
    }

    public function test_it_fails_closed_for_an_unsupported_or_ambiguous_connection(): void
    {
        $this->connection(actions: []);

        try {
            app(ApplicationDiscordReconciliationService::class)->reconcile($this->application());
            $this->fail('An unsupported queue action should fail closed.');
        } catch (ApplicationDiscordReconciliationException $exception) {
            $this->assertSame('discord_queue_action_unsupported', $exception->errorCode);
        }

        Application::query()->delete();
        DiscordConnection::query()->delete();
        $this->connection();
        $this->connection(
            id: '019fe8de-d604-7b0e-95eb-eb70429b8f6b',
            applicationId: '133456789012345678',
            guildId: '233456789012345678',
        );

        try {
            app(ApplicationDiscordReconciliationService::class)->reconcile($this->application(9002));
            $this->fail('An ambiguous legacy application should fail closed.');
        } catch (ApplicationDiscordReconciliationException $exception) {
            $this->assertSame('ambiguous_discord_connection', $exception->errorCode);
        }
    }

    public function test_an_explicit_foreign_connection_cannot_rebind_an_application(): void
    {
        $this->connection();
        $application = app(ApplicationDiscordReconciliationService::class)->reconcile($this->application());
        $foreign = $this->connection(
            id: '019fe8de-d604-7b0e-95eb-eb70429b8f6b',
            applicationId: '133456789012345678',
            guildId: '233456789012345678',
        );
        $context = app(DiscordConnectionResolver::class)->resolveForVerification($foreign->id);

        try {
            app(ApplicationDiscordReconciliationService::class)->reconcile($application, $context, true);
            $this->fail('A foreign connection should not be able to rebind the application.');
        } catch (ApplicationDiscordReconciliationException $exception) {
            $this->assertSame('application_discord_binding_mismatch', $exception->errorCode);
        }

        $this->assertSame(self::CONNECTION_ID, $application->fresh()->discord_connection_id);
        $this->assertDatabaseCount('discord_queue', 1);
    }

    public function test_checkpoint_preflight_fences_revision_and_persists_the_authoritative_channel(): void
    {
        $connection = $this->connection();
        $application = app(ApplicationDiscordReconciliationService::class)->reconcile($this->application());
        [$queue, $leaseToken] = $this->lease($application);
        $context = app(DiscordConnectionResolver::class)->resolveForVerification($connection->id);
        $service = app(DiscordQueueLeaseService::class);

        $service->checkpoint($queue, $leaseToken, [
            'application_reconcile' => $this->checkpoint($application->discord_reconcile_revision),
        ], $context);
        $this->assertNull($application->fresh()->discord_channel_id);

        $channelId = '923456789012345678';
        $service->checkpoint($queue, $leaseToken, [
            'application_reconcile' => $this->checkpoint(
                $application->discord_reconcile_revision,
                channelId: $channelId,
            ),
        ], $context);

        $this->assertSame($channelId, $application->fresh()->discord_channel_id);
        $this->assertSame(
            $channelId,
            data_get($queue->fresh()->result, 'application_reconcile.channel_id'),
        );
    }

    public function test_checkpoint_rejects_stale_revisions_and_operations_outside_the_desired_state(): void
    {
        $connection = $this->connection();
        $application = app(ApplicationDiscordReconciliationService::class)->reconcile($this->application());
        [$queue, $leaseToken] = $this->lease($application);
        $context = app(DiscordConnectionResolver::class)->resolveForVerification($connection->id);
        $service = app(DiscordQueueLeaseService::class);

        try {
            $service->checkpoint($queue, $leaseToken, [
                'application_reconcile' => $this->checkpoint(
                    $application->discord_reconcile_revision,
                    rolesAdded: ['999456789012345678'],
                ),
            ], $context);
            $this->fail('A checkpoint may not claim a role outside the desired state.');
        } catch (DiscordQueueLeaseException $exception) {
            $this->assertSame('application_reconcile_checkpoint_conflict', $exception->error);
        }

        $application->forceFill(['discord_reconcile_revision' => 2])->save();
        try {
            $service->checkpoint($queue, $leaseToken, [
                'application_reconcile' => $this->checkpoint(1),
            ], $context);
            $this->fail('A stale application revision should fail before Discord mutation.');
        } catch (DiscordQueueLeaseException $exception) {
            $this->assertSame('stale_application_reconciliation', $exception->error);
        }

        $this->assertNull($queue->fresh()->result);
        $this->assertNull($application->fresh()->discord_channel_id);
    }

    /** @param list<string> $actions */
    private function connection(
        string $id = self::CONNECTION_ID,
        string $applicationId = self::APPLICATION_ID,
        string $guildId = self::GUILD_ID,
        array $actions = [ApplicationDiscordReconciliationService::ACTION],
    ): DiscordConnection {
        return DiscordConnection::query()->create([
            'id' => $id,
            'mode' => DiscordConnectionMode::OfficialShared,
            'state' => DiscordConnectionState::Active,
            'application_id' => $applicationId,
            'guild_id' => $guildId,
            'generation' => 7,
            'protocol_version' => 2,
            'relay_current_key_id' => 'relay-current',
            'relay_current_public_key' => str_repeat('a', 43),
            'capability_version' => 3,
            'capabilities' => [
                'keys' => ['relay.proof.v2', 'queue.connection-context.v1'],
                'supported_queue_actions' => $actions,
            ],
            'v1_reader_enabled' => false,
            'activated_at' => now(),
        ]);
    }

    private function application(int $nationId = 9001): Application
    {
        return Application::query()->create([
            'nation_id' => $nationId,
            'leader_name_snapshot' => 'Example Leader',
            'discord_user_id' => self::USER_ID,
            'discord_username' => 'example-user',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ]);
    }

    /** @return array{DiscordQueue, string} */
    private function lease(Application $application): array
    {
        $leaseToken = (string) Str::uuid();
        $queue = DiscordQueue::query()->findOrFail($application->discord_reconcile_queue_id);
        $queue->forceFill([
            'status' => DiscordQueueStatus::Processing,
            'lease_token' => $leaseToken,
            'leased_until' => now()->addMinutes(5),
        ])->save();

        return [$queue->fresh(), $leaseToken];
    }

    /**
     * @param  list<string>  $rolesAdded
     * @return array<string, mixed>
     */
    private function checkpoint(
        int $revision,
        ?string $channelId = null,
        array $rolesAdded = [],
    ): array {
        return [
            'application_revision' => $revision,
            'channel_id' => $channelId,
            'channel_deleted' => false,
            'roles_added' => $rolesAdded,
            'roles_removed' => [],
            'intro_messages' => [],
            'notifications' => [],
        ];
    }
}
