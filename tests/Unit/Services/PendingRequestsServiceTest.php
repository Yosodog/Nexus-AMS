<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\PendingRequestsService;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\FeatureTestCase;

class PendingRequestsServiceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pending_requests.cache_key', 'testing.pending-requests');
        config()->set('pending_requests.projection_cache_key', 'testing.pending-requests.projection');
        Cache::forget('testing.pending-requests');
        Cache::forget('testing.pending-requests.projection');
    }

    public function test_get_counts_for_user_filters_using_gate_permissions(): void
    {
        $user = User::factory()->make(['is_admin' => false]);
        $gate = new class
        {
            public function allows(string $ability): bool
            {
                return $ability === 'manage-loans';
            }
        };
        Gate::shouldReceive('forUser')->once()->with($user)->andReturn($gate);
        $service = $this->service([
            $this->source('grants', 'manage-grants', 2),
            $this->source('loans', 'manage-loans', 3),
            $this->source('war_aid', 'manage-war-aid', 4),
        ]);

        $counts = $service->getCountsForUser($user);

        $this->assertSame(['loans' => 3], $counts['counts']);
        $this->assertSame(3, $counts['total']);
        $this->assertTrue($counts['complete']);
    }

    public function test_admin_flag_does_not_bypass_pending_count_permissions(): void
    {
        $user = User::factory()->make(['is_admin' => true]);
        Gate::shouldReceive('forUser')->once()->with($user)->andReturn(new class
        {
            public function allows(string $ability): bool
            {
                return false;
            }
        });
        $service = $this->service([
            $this->source('grants', 'manage-grants', 2),
            $this->source('loans', 'manage-loans', 3),
        ]);

        $counts = $service->getCountsForUser($user);

        $this->assertSame([], $counts['counts']);
        $this->assertSame(0, $counts['total']);
    }

    public function test_get_raw_counts_uses_cache_key_and_flush_cache_clears_it(): void
    {
        $source = $this->source('loans', 'manage-loans', 5);
        $service = $this->service([$source]);

        $first = $service->getRawCounts();
        $second = $service->getRawCounts();

        $this->assertSame(['loans' => 5], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $source->loads);
        $this->assertSame(['loans' => 5], Cache::get('testing.pending-requests'));
        $this->assertIsArray(Cache::get('testing.pending-requests.projection'));
        $service->flushCache();
        $this->assertNull(Cache::get('testing.pending-requests'));
        $this->assertNull(Cache::get('testing.pending-requests.projection'));
    }

    /**
     * @param  list<StaffWorkQueueSource>  $sources
     */
    private function service(array $sources): PendingRequestsService
    {
        return new PendingRequestsService(new StaffWorkQueueRegistry($sources));
    }

    private function source(string $type, string $ability, int $count): PendingRequestTestSource
    {
        return new PendingRequestTestSource($type, $ability, $count);
    }
}

final class PendingRequestTestSource implements StaffWorkQueueSource
{
    public int $loads = 0;

    public function __construct(
        private readonly string $type,
        private readonly string $ability,
        private readonly int $count,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return str($this->type)->headline()->toString();
    }

    public function ability(): string
    {
        return $this->ability;
    }

    public function load(): array
    {
        $this->loads++;

        return collect(range(1, $this->count))
            ->map(fn (int $id): StaffWorkItem => new StaffWorkItem(
                type: $this->type,
                id: $id,
                typeLabel: $this->label(),
                subject: $this->label().' '.$id,
                createdAt: now(),
                ownerKey: null,
                ownerLabel: null,
                statusLabel: 'Pending review',
                statusIntent: 'pending',
                statusIcon: 'clock',
                nextActionLabel: 'Review',
                url: 'https://example.test/'.$this->type.'/'.$id,
            ))
            ->all();
    }
}
