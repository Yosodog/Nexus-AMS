<?php

namespace Tests\Feature\API;

use App\Enums\OperationsNextActor;
use App\Enums\OperationsPriority;
use App\Enums\OperationsSensitivity;
use App\Enums\OperationsSeverity;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkEvent;
use App\Models\User;
use App\Services\StaffWorkQueue\OperationsReadStore;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceDescriptor;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceResult;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordOperationsCoordinationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const GUILD_ID = '123456789012345678';

    private const PRIMARY_DISCORD_ID = '234567890123456789';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config([
            'app.url' => 'https://nexus.test',
            'operations.features.coordination' => true,
            'operations.coordination.assignment_ttl_minutes' => 30,
            'services.discord_bot_key' => 'operations-coordination-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
        Cache::flush();

        $this->actor = $this->createStaffActor(
            self::PRIMARY_DISCORD_ID,
            ['view-loans', 'coordinate-operations'],
        );
    }

    public function test_claim_and_release_are_actor_bound_audited_and_idempotent(): void
    {
        $this->bindSource($this->source([$this->item()]));
        $projection = $this->show('300000000000000001', self::PRIMARY_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.capabilities.0', 'operations.claim')
            ->json('data.coordination');

        $claimPayload = [
            'occurrence_key' => 'loans-42-cycle-1',
            'source_revision' => $projection['source_revision'],
            'lock_version' => $projection['lock_version'],
        ];
        $claim = $this->withHeaders($this->headers('300000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', $claimPayload)
            ->assertOk()
            ->assertJsonPath('data.work_key', 'loans:42')
            ->assertJsonPath('data.coordination.assignee.id', $this->actor->id)
            ->assertJsonPath('data.coordination.assignee.is_me', true)
            ->assertJsonPath('data.coordination.lock_version', 2)
            ->assertJsonPath('meta.provider', 'nexus_operations')
            ->assertJsonPath('meta.idempotent_replay', false);

        $coordination = OperationsWorkCoordination::query()->firstOrFail();
        $this->assertSame($this->actor->id, $coordination->assignee_user_id);
        $this->assertTrue($coordination->assignment_expires_at->isAfter($coordination->assigned_at));
        $this->assertTrue($coordination->assignment_expires_at->between(
            now()->addMinutes(29),
            now()->addMinutes(31),
        ));
        $this->assertSame(
            ['discovered', 'claimed'],
            OperationsWorkEvent::query()->orderBy('id')->pluck('event_type')->all(),
        );

        $this->withHeaders($this->headers('300000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', $claimPayload)
            ->assertOk()
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('meta.idempotent_replay', true);
        $this->assertSame(2, OperationsWorkEvent::query()->count());

        $this->show('300000000000000003', self::PRIMARY_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.capabilities.0', 'operations.release')
            ->assertJsonPath('data.coordination.assignee.id', $this->actor->id);

        $this->withHeaders($this->headers('300000000000000004', self::PRIMARY_DISCORD_ID, 'work.release'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/release', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $projection['source_revision'],
                'lock_version' => $claim->json('data.coordination.lock_version'),
            ])
            ->assertOk()
            ->assertJsonPath('data.coordination.assignee', null)
            ->assertJsonPath('data.coordination.assignment_expires_at', null)
            ->assertJsonPath('data.coordination.lock_version', 3);

        $coordination->refresh();
        $this->assertNull($coordination->assignee_user_id);
        $this->assertNull($coordination->assignment_expires_at);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $coordination->id,
            'event_type' => 'released',
            'actor_user_id' => $this->actor->id,
            'subject_user_id' => $this->actor->id,
            'correlation_id' => '300000000000000004',
        ]);
    }

    public function test_only_one_actor_can_own_an_unexpired_occurrence(): void
    {
        $otherActor = $this->createStaffActor(
            '345678901234567890',
            ['view-loans', 'coordinate-operations'],
        );
        $this->bindSource($this->source([$this->item()]));
        $projection = $this->show('310000000000000001', self::PRIMARY_DISCORD_ID)->json('data.coordination');
        $payload = [
            'occurrence_key' => 'loans-42-cycle-1',
            'source_revision' => $projection['source_revision'],
        ];

        $this->withHeaders($this->headers('310000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', $payload)
            ->assertOk();

        $this->withHeaders($this->headers('310000000000000003', '345678901234567890', 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'already_claimed')
            ->assertJsonPath('error.details.assignee.id', $this->actor->id);

        $this->assertSame(1, OperationsWorkCoordination::query()->active()->count());
        $this->assertSame($this->actor->id, OperationsWorkCoordination::query()->value('assignee_user_id'));
        $this->assertSame(1, OperationsWorkEvent::query()->where('event_type', 'claimed')->count());
        $this->assertSame(0, OperationsWorkEvent::query()
            ->where('event_type', 'claimed')
            ->where('actor_user_id', $otherActor->id)
            ->count());
    }

    public function test_expired_ownership_is_cleaned_and_audited_before_reclaim(): void
    {
        $formerActor = $this->createStaffActor(
            '456789012345678901',
            ['view-loans', 'coordinate-operations'],
        );
        $item = $this->item();
        $this->bindSource($this->source([$item]));
        $coordination = OperationsWorkCoordination::factory()->create([
            'work_key' => 'loans:42',
            'occurrence_key' => 'loans-42-cycle-1',
            'source_type' => 'loans',
            'source_fingerprint' => $item->sourceFingerprint(),
            'assignee_user_id' => $formerActor->id,
            'assigned_by_user_id' => $formerActor->id,
            'assigned_at' => now()->subHour(),
            'assignment_expires_at' => now()->subMinute(),
        ]);

        $projection = $this->show('320000000000000001', self::PRIMARY_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.coordination.assignee', null)
            ->assertJsonPath('data.coordination.assignment_expired', true)
            ->assertJsonPath('data.capabilities.0', 'operations.claim')
            ->json('data.coordination');

        $this->withHeaders($this->headers('320000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $projection['source_revision'],
                'lock_version' => $projection['lock_version'],
            ])
            ->assertOk()
            ->assertJsonPath('data.coordination.assignee.id', $this->actor->id)
            ->assertJsonPath('data.coordination.lock_version', 2);

        $coordination->refresh();
        $this->assertSame($this->actor->id, $coordination->assignee_user_id);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $coordination->id,
            'event_type' => 'assignment_expired',
            'subject_user_id' => $formerActor->id,
        ]);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $coordination->id,
            'event_type' => 'claimed',
            'actor_user_id' => $this->actor->id,
        ]);
    }

    public function test_existing_unassigned_occurrence_requires_its_current_lock_version(): void
    {
        $item = $this->item();
        $this->bindSource($this->source([$item]));
        $coordination = OperationsWorkCoordination::factory()->create([
            'work_key' => 'loans:42',
            'occurrence_key' => 'loans-42-cycle-1',
            'source_type' => 'loans',
            'source_fingerprint' => $item->sourceFingerprint(),
            'lock_version' => 5,
        ]);
        $projection = $this->show('325000000000000001', self::PRIMARY_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.coordination.lock_version', 5)
            ->json('data.coordination');
        $payload = [
            'occurrence_key' => 'loans-42-cycle-1',
            'source_revision' => $projection['source_revision'],
        ];

        $this->withHeaders($this->headers('325000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'coordination_conflict')
            ->assertJsonPath('error.details.lock_version', 5);
        $this->assertNull($coordination->fresh()->assignee_user_id);

        $this->withHeaders($this->headers('325000000000000003', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                ...$payload,
                'lock_version' => 4,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'coordination_conflict')
            ->assertJsonPath('error.details.lock_version', 5);
        $this->assertNull($coordination->fresh()->assignee_user_id);

        $this->withHeaders($this->headers('325000000000000004', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                ...$payload,
                'lock_version' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.coordination.assignee.id', $this->actor->id)
            ->assertJsonPath('data.coordination.lock_version', 6);
    }

    public function test_stale_revision_cannot_mutate_coordination(): void
    {
        $item = $this->item();
        $this->bindSource($this->source([$item]));
        $projection = $this->show('330000000000000001', self::PRIMARY_DISCORD_ID)->json('data.coordination');

        $this->withHeaders($this->headers('330000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => str_repeat('0', 64),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'work_item_changed');
        $this->assertDatabaseCount('operations_work_coordination', 0);
    }

    public function test_closed_occurrence_cannot_be_recreated_from_a_stale_projection(): void
    {
        $item = $this->item();
        $this->bindSource($this->source([$item]));
        $closed = OperationsWorkCoordination::factory()->create([
            'work_key' => 'loans:42',
            'occurrence_key' => 'loans-42-cycle-1',
            'source_type' => 'loans',
            'source_fingerprint' => $item->sourceFingerprint(),
            'active_key' => null,
            'closed_at' => now(),
            'lock_version' => 2,
        ]);
        $projection = $this->show('335000000000000001', self::PRIMARY_DISCORD_ID)
            ->assertOk()
            ->json('data.coordination');

        $this->withHeaders($this->headers('335000000000000002', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $projection['source_revision'],
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'work_item_changed');

        $this->assertSame(0, OperationsWorkCoordination::query()->active()->count());
        $this->assertSame(1, OperationsWorkCoordination::query()
            ->whereKey($closed->id)
            ->whereNotNull('closed_at')
            ->count());
        $this->assertDatabaseCount('operations_work_events', 0);
    }

    public function test_incomplete_source_cannot_mutate_coordination(): void
    {
        $item = $this->item();
        $this->bindSource($this->source([$item], complete: false));
        $incomplete = $this->show('330000000000000003', self::PRIMARY_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.freshness.source_complete', false)
            ->assertJsonPath('data.capabilities', [])
            ->json('data.coordination');

        $this->withHeaders($this->headers('330000000000000004', self::PRIMARY_DISCORD_ID, 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $incomplete['source_revision'],
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'source_unavailable');
        $this->assertDatabaseCount('operations_work_coordination', 0);
    }

    public function test_non_coordinator_cannot_claim_and_non_owner_cannot_release(): void
    {
        $this->createStaffActor('567890123456789012', ['view-loans']);
        $this->createStaffActor(
            '678901234567890123',
            ['view-loans', 'coordinate-operations'],
        );
        $manager = $this->createStaffActor(
            '789012345678901234',
            ['view-loans', 'manage-operations'],
        );
        $item = $this->item();
        $this->bindSource($this->source([$item]));
        $projection = $this->show('340000000000000001', '567890123456789012')
            ->assertOk()
            ->assertJsonPath('data.capabilities', [])
            ->json('data.coordination');

        $this->withHeaders($this->headers('340000000000000002', '567890123456789012', 'work.claim'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $projection['source_revision'],
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->assertDatabaseCount('operations_work_coordination', 0);

        $coordination = OperationsWorkCoordination::factory()->create([
            'work_key' => 'loans:42',
            'occurrence_key' => 'loans-42-cycle-1',
            'source_type' => 'loans',
            'source_fingerprint' => $item->sourceFingerprint(),
            'assignee_user_id' => $this->actor->id,
            'assigned_by_user_id' => $this->actor->id,
            'assigned_at' => now(),
            'assignment_expires_at' => now()->addMinutes(30),
        ]);

        $this->withHeaders($this->headers('340000000000000003', '678901234567890123', 'work.release'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/release', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $item->sourceFingerprint(),
                'lock_version' => $coordination->lock_version,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame($this->actor->id, $coordination->fresh()->assignee_user_id);
        $this->assertSame(0, OperationsWorkEvent::query()->where('event_type', 'released')->count());

        $this->withHeaders($this->headers('340000000000000004', '789012345678901234', 'work.release'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/release', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $item->sourceFingerprint(),
                'lock_version' => $coordination->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('data.coordination.assignee', null);

        $this->assertNull($coordination->fresh()->assignee_user_id);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $coordination->id,
            'event_type' => 'released',
            'actor_user_id' => $manager->id,
            'subject_user_id' => $this->actor->id,
        ]);
    }

    public function test_signed_interaction_must_match_the_coordination_action(): void
    {
        $item = $this->item();
        $this->bindSource($this->source([$item]));

        $this->withHeaders($this->headers('350000000000000001', self::PRIMARY_DISCORD_ID, 'work.queue'))
            ->postJson('/api/v1/discord/staff/work-items/loans/42/claim', [
                'occurrence_key' => 'loans-42-cycle-1',
                'source_revision' => $item->sourceFingerprint(),
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_interaction_action_mismatch');

        $this->assertDatabaseCount('operations_work_coordination', 0);
        $this->assertDatabaseCount('operations_work_events', 0);
    }

    private function createStaffActor(string $discordId, array $permissions): User
    {
        $nation = Nation::factory()->create(['alliance_id' => 777]);
        $actor = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => $nation->id]),
            $permissions,
        );
        DiscordAccount::factory()->create([
            'user_id' => $actor->id,
            'discord_id' => $discordId,
            'unlinked_at' => null,
        ]);

        return $actor;
    }

    private function bindSource(CoordinatedOperationsSource $source): void
    {
        $registry = new StaffWorkQueueRegistry([$source]);
        $this->app->instance(StaffWorkQueueRegistry::class, $registry);
        $this->app->instance(OperationsReadStore::class, $registry);
    }

    private function show(string $interactionId, string $discordId): TestResponse
    {
        return $this->withHeaders($this->headers($interactionId, $discordId, 'work.show'))
            ->getJson('/api/v1/discord/staff/work-items/loans/42');
    }

    /** @return array<string, string> */
    private function headers(string $interactionId, string $discordId, string $command): array
    {
        return $this->signedDiscordInteractionHeaders(
            'operations-coordination-test-key',
            self::GUILD_ID,
            $discordId,
            $interactionId,
            $command,
        );
    }

    private function item(): StaffWorkItem
    {
        return new StaffWorkItem(
            type: 'loans',
            id: 42,
            typeLabel: 'Loans',
            subject: 'Review coordinated loan',
            createdAt: now()->subHours(2),
            ownerKey: null,
            ownerLabel: null,
            statusLabel: 'Pending review',
            statusIntent: 'pending',
            statusIcon: 'clock',
            nextActionLabel: 'Review in Nexus',
            url: 'https://nexus.test/admin/loans/42',
            occurrenceKey: 'loans-42-cycle-1',
            summary: 'A safe coordination test summary.',
            domainStatusCode: 'pending_review',
            teamKey: 'finance',
            nextActor: OperationsNextActor::Staff,
            priority: OperationsPriority::P2,
            severity: OperationsSeverity::Moderate,
            sourceUpdatedAt: now()->subMinute(),
        );
    }

    /** @param  list<StaffWorkItem>  $items */
    private function source(array $items, bool $complete = true): CoordinatedOperationsSource
    {
        return new CoordinatedOperationsSource($items, $complete);
    }
}

final class CoordinatedOperationsSource implements StaffWorkQueueSourceV2
{
    /** @param  list<StaffWorkItem>  $items */
    public function __construct(
        private readonly array $items,
        private readonly bool $complete,
    ) {}

    public function type(): string
    {
        return 'loans';
    }

    public function label(): string
    {
        return 'Loans';
    }

    public function ability(): string
    {
        return 'view-loans';
    }

    public function descriptor(): StaffWorkQueueSourceDescriptor
    {
        return new StaffWorkQueueSourceDescriptor(
            type: 'loans',
            label: 'Loans',
            teamKey: 'finance',
            viewAbilities: ['view-loans'],
            freshSeconds: 60,
            staleSeconds: 300,
            sensitivity: OperationsSensitivity::Restricted,
        );
    }

    public function load(): array
    {
        return $this->items;
    }

    public function loadResult(): StaffWorkQueueSourceResult
    {
        return new StaffWorkQueueSourceResult(
            items: $this->items,
            observedAt: now(),
            upstreamObservedAt: now()->subSeconds(5),
            complete: $this->complete,
        );
    }
}
