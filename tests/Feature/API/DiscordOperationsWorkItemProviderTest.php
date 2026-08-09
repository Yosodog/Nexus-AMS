<?php

namespace Tests\Feature\API;

use App\Enums\OperationsAttentionReason;
use App\Enums\OperationsNextActor;
use App\Enums\OperationsPriority;
use App\Enums\OperationsSensitivity;
use App\Enums\OperationsSeverity;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\StaffWorkQueue\OperationsReadStore;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueActor;
use App\Services\StaffWorkQueue\StaffWorkQueueContext;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceDescriptor;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceResult;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordOperationsWorkItemProviderTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config([
            'app.url' => 'https://nexus.test',
            'services.discord_bot_key' => 'operations-provider-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'pending_requests.projection_cache_key' => 'testing.discord.operations.projection',
        ]);
        Cache::flush();

        $nation = Nation::factory()->create(['alliance_id' => 777]);
        $this->actor = $this->createVerifiedAdmin(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);
    }

    public function test_provider_routes_coexist_with_the_legacy_v1_staff_route(): void
    {
        $getRoutes = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (RoutingRoute $route): string => $route->uri())
            ->values();

        $this->assertContains('api/v1/discord/staff/requests', $getRoutes);
        $this->assertContains('api/v1/discord/staff/work-items', $getRoutes);
        $this->assertContains('api/v1/discord/staff/work-items/{type}/{id}', $getRoutes);
    }

    public function test_list_is_actor_permission_filtered_and_characterizes_the_safe_contract(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-loans']);
        $loan = $this->item(
            type: 'loans',
            id: 42,
            title: 'Review Acme loan',
            url: 'https://nexus.test/admin/loans/42?from=operations',
            createdAt: now()->subHours(4),
            dueAt: now()->subMinute(),
            priority: OperationsPriority::P1,
            severity: OperationsSeverity::High,
            blocked: true,
        );
        $loans = $this->source('loans', 'Loans', 'finance', 'view-loans', [$loan]);
        $applications = $this->source(
            'applications',
            'Applications',
            'internal_affairs',
            'view-applications',
            [$this->item('applications', 9, 'Classified applicant', 'https://nexus.test/admin/applications/9')],
        );
        $this->bindSources([$loans, $applications]);

        $response = $this->withHeaders($this->headers('345678901234567890'))
            ->getJson('/api/v1/discord/staff/work-items');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('meta.provider', 'nexus_operations')
            ->assertJsonPath('meta.projection_schema_version', 2)
            ->assertJsonPath('meta.complete', true)
            ->assertJsonPath('meta.authorized_sources.loans', 'Loans')
            ->assertJsonMissingPath('meta.authorized_sources.applications')
            ->assertJsonPath('meta.sources.loans.team', 'finance')
            ->assertJsonPath('meta.sources.loans.freshness', 'fresh')
            ->assertJsonPath('data.0.work_key', 'loans:42')
            ->assertJsonPath('data.0.source.type', 'loans')
            ->assertJsonPath('data.0.routing.responsible_team', 'finance')
            ->assertJsonPath('data.0.status.code', 'pending_review')
            ->assertJsonPath('data.0.actors.owner.key', 'nation:700')
            ->assertJsonPath('data.0.actors.requester.key', 'nation:701')
            ->assertJsonPath('data.0.actors.requester.deep_link_path', null)
            ->assertJsonPath('data.0.actors.next_actor', 'staff')
            ->assertJsonPath('data.0.attention.priority', 'p1')
            ->assertJsonPath('data.0.attention.severity', 'high')
            ->assertJsonPath('data.0.attention.urgency', 'urgent')
            ->assertJsonPath('data.0.attention.overdue', true)
            ->assertJsonPath('data.0.attention.blocked', true)
            ->assertJsonPath('data.0.attention.blocker_summary', 'Awaiting verified documents')
            ->assertJsonPath('data.0.freshness.state', 'fresh')
            ->assertJsonPath('data.0.freshness.source_complete', true)
            ->assertJsonPath('data.0.facts.risk_band', 'high')
            ->assertJsonPath('data.0.next_action.deep_link_path', '/admin/loans/42?from=operations');

        $payload = $response->json('data.0');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('url', $payload);
        $this->assertArrayNotHasKey('source_fingerprint', $payload);
        $this->assertArrayNotHasKey('capability_keys', $payload);
        $this->assertSame(1, $loans->loads);
        $this->assertSame(0, $applications->loads);
    }

    public function test_list_returns_healthy_items_with_source_scoped_partial_failure_metadata(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-loans', 'view-applications']);
        $loans = $this->source('loans', 'Loans', 'finance', 'view-loans', [
            $this->item('loans', 5, 'Healthy loan', 'https://nexus.test/admin/loans/5'),
        ]);
        $applications = $this->source(
            'applications',
            'Applications',
            'internal_affairs',
            'view-applications',
            fail: true,
        );
        $this->bindSources([$loans, $applications]);

        $this->withHeaders($this->headers('456789012345678901'))
            ->getJson('/api/v1/discord/staff/work-items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_key', 'loans:5')
            ->assertJsonPath('meta.complete', false)
            ->assertJsonPath('meta.sources.loans.unavailable', false)
            ->assertJsonPath('meta.sources.applications.unavailable', true)
            ->assertJsonPath('meta.sources.applications.complete', false)
            ->assertJsonPath('meta.sources.applications.freshness', 'stale')
            ->assertJsonPath('meta.sources.applications.item_count', null)
            ->assertJsonPath('meta.unavailable_sources.0.type', 'applications')
            ->assertJsonPath('meta.unavailable_sources.0.label', 'Applications');

        $this->assertSame(1, $loans->loads);
        $this->assertSame(1, $applications->loads);
    }

    public function test_list_reuses_operations_filters_and_validates_actor_local_pagination(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-loans']);
        $loans = $this->source('loans', 'Loans', 'finance', 'view-loans', [
            $this->item('loans', 1, 'Old blocked loan', 'https://nexus.test/admin/loans/1', now()->subDays(2), priority: OperationsPriority::P1, blocked: true),
            $this->item('loans', 2, 'Moderate loan', 'https://nexus.test/admin/loans/2', now()->subDay(), priority: OperationsPriority::P2),
            $this->item('loans', 3, 'New blocked loan', 'https://nexus.test/admin/loans/3', now()->subHours(2), priority: OperationsPriority::P1, blocked: true),
        ]);
        $applications = $this->source(
            'applications',
            'Applications',
            'internal_affairs',
            'view-applications',
            [$this->item('applications', 8, 'Hidden applicant', 'https://nexus.test/admin/applications/8')],
        );
        $this->bindSources([$loans, $applications]);

        $this->withHeaders($this->headers('567890123456789012'))
            ->getJson('/api/v1/discord/staff/work-items?priority=p1&blocked=1&sort=age&direction=desc&per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_key', 'loans:3')
            ->assertJsonPath('meta.filters.priority', 'p1')
            ->assertJsonPath('meta.filters.blocked', true)
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.has_more', false);

        $this->withHeaders($this->headers('678901234567890123'))
            ->getJson('/api/v1/discord/staff/work-items?per_page=101&sort=unsupported')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonStructure(['error' => ['details' => ['per_page', 'sort']]]);

        $this->withHeaders($this->headers('789012345678901234'))
            ->getJson('/api/v1/discord/staff/work-items?type=applications')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['type']]]);

        $this->assertSame(1, $loans->loads);
        $this->assertSame(0, $applications->loads);
    }

    public function test_detail_is_permission_filtered_and_never_emits_an_external_deep_link(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-loans']);
        $loans = $this->source('loans', 'Loans', 'finance', 'view-loans', [
            $this->item('loans', 42, 'Visible loan', 'https://outside.test/unsafe'),
        ]);
        $applications = $this->source('applications', 'Applications', 'internal_affairs', 'view-applications', [
            $this->item('applications', 9, 'Hidden applicant', 'https://nexus.test/admin/applications/9'),
        ]);
        $this->bindSources([$loans, $applications]);

        $this->withHeaders($this->headers('890123456789012345'))
            ->getJson('/api/v1/discord/staff/work-items/loans/42')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('meta.provider', 'nexus_operations')
            ->assertJsonPath('data.work_key', 'loans:42')
            ->assertJsonPath('data.next_action.deep_link_path', null);

        $this->withHeaders($this->headers('901234567890123456'))
            ->getJson('/api/v1/discord/staff/work-items/applications/9')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('meta.contract_version', 1);

        $this->withHeaders($this->headers('912345678901234567'))
            ->getJson('/api/v1/discord/staff/work-items/loans/missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->withHeaders($this->headers('923456789012345678'))
            ->getJson('/api/v1/discord/staff/work-items/loans/bad:id')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['work_item_id']]]);

        $this->assertSame(1, $loans->loads);
        $this->assertSame(0, $applications->loads);
    }

    public function test_list_requires_actor_local_source_permission(): void
    {
        $loans = $this->source('loans', 'Loans', 'finance', 'view-loans', [
            $this->item('loans', 4, 'Restricted loan', 'https://nexus.test/admin/loans/4'),
        ]);
        $this->bindSources([$loans]);

        $this->withHeaders($this->headers('934567890123456789'))
            ->getJson('/api/v1/discord/staff/work-items')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('meta.contract_version', 1);

        $this->assertSame(0, $loans->loads);
    }

    public function test_list_requires_a_nexus_administrator_even_with_source_permission(): void
    {
        $nation = Nation::factory()->create(['alliance_id' => 777]);
        $member = $this->grantPermissions(
            $this->createVerifiedUser(['nation_id' => $nation->id]),
            ['view-loans'],
        );
        $discordId = '345678901234567890';
        DiscordAccount::factory()->create([
            'user_id' => $member->id,
            'discord_id' => $discordId,
            'unlinked_at' => null,
        ]);
        $loans = $this->source('loans', 'Loans', 'finance', 'view-loans', [
            $this->item('loans', 4, 'Restricted loan', 'https://nexus.test/admin/loans/4'),
        ]);
        $this->bindSources([$loans]);

        $this->withHeaders($this->headers('945678901234567890', $discordId))
            ->getJson('/api/v1/discord/staff/work-items')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('meta.contract_version', 1);

        $this->assertSame(0, $loans->loads);
    }

    /** @param  list<CharacterizedOperationsSource>  $sources */
    private function bindSources(array $sources): void
    {
        $registry = new StaffWorkQueueRegistry($sources);
        $this->app->instance(StaffWorkQueueRegistry::class, $registry);
        $this->app->instance(OperationsReadStore::class, $registry);
    }

    /** @return array<string, string> */
    private function headers(string $interactionId, string $discordId = self::DISCORD_ID): array
    {
        return $this->signedDiscordInteractionHeaders(
            'operations-provider-test-key',
            self::GUILD_ID,
            $discordId,
            $interactionId,
            'operations.work-items',
        );
    }

    private function item(
        string $type,
        int $id,
        string $title,
        string $url,
        ?DateTimeInterface $createdAt = null,
        ?DateTimeInterface $dueAt = null,
        OperationsPriority $priority = OperationsPriority::P3,
        OperationsSeverity $severity = OperationsSeverity::Unknown,
        bool $blocked = false,
    ): StaffWorkItem {
        return new StaffWorkItem(
            type: $type,
            id: $id,
            typeLabel: str($type)->headline()->toString(),
            subject: $title,
            createdAt: $createdAt ?? now()->subHours(2),
            ownerKey: null,
            ownerLabel: null,
            statusLabel: 'Pending review',
            statusIntent: 'pending',
            statusIcon: 'clock',
            nextActionLabel: 'Review in Nexus',
            url: $url,
            dueAt: $dueAt,
            occurrenceKey: $type.'-'.$id.'-cycle-1',
            summary: 'A safe operational summary.',
            domainStatusCode: 'pending_review',
            requester: new StaffWorkQueueActor(
                'nation',
                'nation:701',
                'Requesting Nation',
                'https://outside.test/nations/701',
            ),
            domainOwner: new StaffWorkQueueActor(
                'nation',
                'nation:700',
                'Owning Nation',
                'https://nexus.test/admin/nations/700',
            ),
            waitingOn: $blocked
                ? new StaffWorkQueueActor('participant', 'documents', 'Document verification')
                : null,
            nextActor: OperationsNextActor::Staff,
            priority: $priority,
            severity: $severity,
            attentionReasons: $blocked ? [OperationsAttentionReason::Blocked] : [],
            blocked: $blocked,
            blockerSummary: $blocked ? 'Awaiting verified documents' : null,
            sourceUpdatedAt: now()->subMinute(),
            contexts: [
                new StaffWorkQueueContext('alliance', 'alliance:777', 'Test Alliance'),
            ],
            safeFacts: ['risk_band' => 'high'],
        );
    }

    /** @param  list<StaffWorkItem>  $items */
    private function source(
        string $type,
        string $label,
        string $team,
        string $ability,
        array $items = [],
        bool $fail = false,
    ): CharacterizedOperationsSource {
        return new CharacterizedOperationsSource(
            descriptor: new StaffWorkQueueSourceDescriptor(
                type: $type,
                label: $label,
                teamKey: $team,
                viewAbilities: [$ability],
                freshSeconds: 60,
                staleSeconds: 300,
                sensitivity: OperationsSensitivity::Restricted,
            ),
            items: $items,
            fail: $fail,
        );
    }
}

final class CharacterizedOperationsSource implements StaffWorkQueueSourceV2
{
    public int $loads = 0;

    /** @param  list<StaffWorkItem>  $items */
    public function __construct(
        private readonly StaffWorkQueueSourceDescriptor $descriptor,
        private readonly array $items,
        private readonly bool $fail = false,
    ) {}

    public function type(): string
    {
        return $this->descriptor->type;
    }

    public function label(): string
    {
        return $this->descriptor->label;
    }

    public function ability(): string
    {
        return $this->descriptor->viewAbilities[0];
    }

    public function descriptor(): StaffWorkQueueSourceDescriptor
    {
        return $this->descriptor;
    }

    public function load(): array
    {
        return $this->items;
    }

    public function loadResult(): StaffWorkQueueSourceResult
    {
        $this->loads++;

        if ($this->fail) {
            throw new RuntimeException('Simulated Operations source outage.');
        }

        return new StaffWorkQueueSourceResult(
            items: $this->items,
            observedAt: now(),
            upstreamObservedAt: now()->subSeconds(5),
        );
    }
}
