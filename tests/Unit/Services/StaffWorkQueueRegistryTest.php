<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\PendingRequestsService;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class StaffWorkQueueRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pending_requests.cache_key' => 'testing.staff-work-queue',
            'pending_requests.projection_cache_key' => 'testing.staff-work-queue.projection',
            'pending_requests.cache_ttl_seconds' => 600,
            'pending_requests.failure_cache_ttl_seconds' => 15,
        ]);
        Cache::forget('testing.staff-work-queue');
        Cache::forget('testing.staff-work-queue.projection');
    }

    public function test_one_source_failure_preserves_healthy_items_and_is_observable(): void
    {
        $healthy = new InMemoryStaffWorkQueueSource(
            type: 'loans',
            label: 'Loans',
            ability: 'manage-loans',
            items: [$this->item('loans', 41, 'Visible loan')],
        );
        $failed = new InMemoryStaffWorkQueueSource(
            type: 'applications',
            label: 'Applications',
            ability: 'manage-applications',
            fail: true,
        );
        Log::spy();

        $registry = new StaffWorkQueueRegistry([$healthy, $failed]);
        $snapshot = $registry->snapshot();
        $cachedSnapshot = $registry->snapshot();

        $this->assertFalse($snapshot['complete']);
        $this->assertSame(['loans' => 1], $snapshot['counts']);
        $this->assertSame('Visible loan', $snapshot['items'][0]['subject']);
        $this->assertSame(['applications' => ['label' => 'Applications']], $snapshot['failures']);
        $this->assertSame($snapshot, $cachedSnapshot);
        $this->assertSame(1, $healthy->loads);
        $this->assertSame(1, $failed->loads);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Staff work queue source failed.'
                && $context['source'] === 'applications'
                && $context['exception'] === RuntimeException::class,
        );
    }

    public function test_pending_counts_and_items_share_the_same_permission_filtered_projection(): void
    {
        $loans = new InMemoryStaffWorkQueueSource(
            type: 'loans',
            label: 'Loans',
            ability: 'manage-loans',
            items: [
                $this->item('loans', 1, 'Loan one'),
                $this->item('loans', 2, 'Loan two'),
            ],
        );
        $applications = new InMemoryStaffWorkQueueSource(
            type: 'applications',
            label: 'Applications',
            ability: 'manage-applications',
            items: [$this->item('applications', 3, 'Restricted application')],
        );
        $user = User::factory()->make();
        $gate = new class
        {
            public function allows(string $ability): bool
            {
                return $ability === 'manage-loans';
            }
        };
        Gate::shouldReceive('forUser')->twice()->with($user)->andReturn($gate);

        $registry = new StaffWorkQueueRegistry([$loans, $applications]);
        $projection = $registry->forUser($user);
        $counts = (new PendingRequestsService($registry))->getCountsForUser($user);

        $this->assertSame(['loans' => 2], $projection['counts']);
        $this->assertSame(['loans' => 2], $counts['counts']);
        $this->assertSame(2, $projection['total']);
        $this->assertSame(2, $counts['total']);
        $this->assertSame(['loans:1', 'loans:2'], array_column($projection['items'], 'key'));
        $this->assertSame(1, $loans->loads);
        $this->assertSame(0, $applications->loads);
    }

    public function test_flush_cache_rebuilds_the_projection_once(): void
    {
        $source = new InMemoryStaffWorkQueueSource(
            type: 'loans',
            label: 'Loans',
            ability: 'manage-loans',
            items: [$this->item('loans', 1, 'Loan one')],
        );
        $registry = new StaffWorkQueueRegistry([$source]);

        $registry->snapshot();
        $registry->snapshot();
        $registry->flushCache();
        $registry->snapshot();

        $this->assertSame(2, $source->loads);
    }

    public function test_user_without_queue_permissions_does_not_execute_domain_queries(): void
    {
        $source = new InMemoryStaffWorkQueueSource(
            type: 'loans',
            label: 'Loans',
            ability: 'manage-loans',
            items: [$this->item('loans', 1, 'Restricted loan')],
        );
        $user = User::factory()->make();
        Gate::shouldReceive('forUser')->once()->with($user)->andReturn(new class
        {
            public function allows(string $ability): bool
            {
                return false;
            }
        });

        $projection = (new StaffWorkQueueRegistry([$source]))->forUser($user);

        $this->assertSame([], $projection['items']);
        $this->assertSame([], $projection['counts']);
        $this->assertSame(0, $source->loads);
    }

    public function test_permission_matrix_exposes_only_the_matching_workflow_type(): void
    {
        $matrix = [
            'applications' => 'manage-applications',
            'withdrawals' => 'manage-accounts',
            'member_transfers' => 'manage-accounts',
            'city_grants' => 'manage-city-grants',
            'grants' => 'manage-grants',
            'loans' => 'manage-loans',
            'war_aid' => 'manage-war-aid',
            'rebuilding' => 'manage-rebuilding',
            'blockade_relief' => 'manage-war-room',
            'audit_remediation' => 'manage-audits',
        ];
        $sources = collect($matrix)
            ->map(fn (string $ability, string $type): InMemoryStaffWorkQueueSource => new InMemoryStaffWorkQueueSource(
                type: $type,
                label: str($type)->headline()->toString(),
                ability: $ability,
                items: [$this->item($type, 1, str($type)->headline()->toString())],
            ))
            ->values()
            ->all();
        $registry = new StaffWorkQueueRegistry($sources);
        Gate::shouldReceive('forUser')
            ->times(count(array_unique($matrix)))
            ->andReturnUsing(fn (User $user): object => new class((string) $user->getAttribute('queue_ability'))
            {
                public function __construct(private readonly string $allowedAbility) {}

                public function allows(string $ability): bool
                {
                    return $ability === $this->allowedAbility;
                }
            });

        foreach (array_unique($matrix) as $ability) {
            $user = User::factory()->make();
            $user->setAttribute('queue_ability', $ability);
            $expectedTypes = array_keys(array_filter($matrix, fn (string $candidate): bool => $candidate === $ability));
            $projection = $registry->forUser($user);

            $this->assertEqualsCanonicalizing($expectedTypes, array_keys($projection['counts']));
            $this->assertEqualsCanonicalizing($expectedTypes, array_column($projection['items'], 'type'));
        }
    }

    private function item(string $type, int $id, string $subject): StaffWorkItem
    {
        return new StaffWorkItem(
            type: $type,
            id: $id,
            typeLabel: str($type)->headline()->toString(),
            subject: $subject,
            createdAt: now()->subHour(),
            ownerKey: null,
            ownerLabel: null,
            statusLabel: 'Pending review',
            statusIntent: 'pending',
            statusIcon: 'clock',
            nextActionLabel: 'Review',
            url: 'https://example.test/work/'.$id,
        );
    }
}

final class InMemoryStaffWorkQueueSource implements StaffWorkQueueSource
{
    public int $loads = 0;

    /**
     * @param  list<StaffWorkItem>  $items
     */
    public function __construct(
        private readonly string $type,
        private readonly string $label,
        private readonly string $ability,
        private readonly array $items = [],
        private readonly bool $fail = false,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function ability(): string
    {
        return $this->ability;
    }

    public function load(): array
    {
        $this->loads++;

        if ($this->fail) {
            throw new RuntimeException('Simulated provider outage.');
        }

        return $this->items;
    }
}
