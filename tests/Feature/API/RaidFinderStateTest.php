<?php

namespace Tests\Feature\API;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Nation;
use App\Models\User;
use App\Services\RaidFinderCache;
use App\Services\RaidFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class RaidFinderStateTest extends TestCase
{
    use RefreshDatabase;

    private Nation $nation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);

        $this->nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $this->user = User::factory()->verified()->create([
            'nation_id' => $this->nation->id,
        ]);
    }

    public function test_successful_results_include_freshness_metadata_and_are_cached(): void
    {
        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findTargets')
                ->once()
                ->with($this->nation->id)
                ->andReturn(collect([$this->target()]));
        });

        $firstResponse = $this->raidFinderRequest()
            ->assertOk()
            ->assertHeader('X-Nexus-Async-State', 'success')
            ->assertHeader('X-Nexus-Data-Stale', 'false')
            ->assertJsonPath('0.nation.id', 9876)
            ->assertJsonPath('0.value', 42157764);

        $this->assertNotEmpty($firstResponse->headers->get('X-Nexus-Data-Updated-At'));

        $this->raidFinderRequest()
            ->assertOk()
            ->assertExactJson($firstResponse->json());
    }

    public function test_empty_result_is_a_successful_fresh_snapshot(): void
    {
        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findTargets')->once()->andReturn(collect());
        });

        $this->raidFinderRequest()
            ->assertOk()
            ->assertHeader('X-Nexus-Async-State', 'success')
            ->assertHeader('X-Nexus-Data-Stale', 'false')
            ->assertExactJson([]);
    }

    public function test_temporary_failure_serves_a_stale_snapshot_when_available(): void
    {
        $cache = app(RaidFinderCache::class);
        $cache->store($this->nation->id, [$this->serializedTarget()]);
        $this->travel(31)->minutes();

        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findTargets')
                ->once()
                ->andThrow(new ServiceUnavailableHttpException(null, 'Unavailable'));
        });

        $this->raidFinderRequest()
            ->assertOk()
            ->assertHeader('X-Nexus-Async-State', 'temporary_failure')
            ->assertHeader('X-Nexus-Data-Stale', 'true')
            ->assertHeader('Warning', '110 '.config('app.name').' "Response is stale"')
            ->assertJsonPath('0.nation.id', 9876);
    }

    public function test_rate_limit_serves_stale_data_and_preserves_retry_timing(): void
    {
        $cache = app(RaidFinderCache::class);
        $cache->store($this->nation->id, [$this->serializedTarget()]);
        $this->travel(31)->minutes();

        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findTargets')
                ->once()
                ->andThrow(new TooManyRequestsHttpException(45, 'Rate limited'));
        });

        $this->raidFinderRequest()
            ->assertOk()
            ->assertHeader('X-Nexus-Async-State', 'rate_limited')
            ->assertHeader('X-Nexus-Data-Stale', 'true')
            ->assertHeader('Retry-After', '45')
            ->assertJsonPath('0.nation.id', 9876);
    }

    public function test_temporary_failure_without_saved_data_returns_a_recoverable_error(): void
    {
        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findTargets')
                ->once()
                ->andThrow(new ServiceUnavailableHttpException(null, 'Unavailable'));
        });

        $this->raidFinderRequest()
            ->assertServiceUnavailable()
            ->assertHeader('X-Nexus-Async-State', 'temporary_failure')
            ->assertJsonPath('state', 'temporary_failure')
            ->assertJsonStructure(['message', 'state', 'support_id']);
    }

    public function test_rate_limit_without_saved_data_returns_retry_timing(): void
    {
        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findTargets')
                ->once()
                ->andThrow(new TooManyRequestsHttpException(30, 'Rate limited'));
        });

        $this->raidFinderRequest()
            ->assertTooManyRequests()
            ->assertHeader('X-Nexus-Async-State', 'rate_limited')
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('state', 'rate_limited');
    }

    public function test_duplicate_refresh_does_not_start_another_external_request(): void
    {
        $cache = app(RaidFinderCache::class);
        $lock = Cache::lock($cache->lockKey($this->nation->id), 45);
        $this->assertTrue($lock->get());

        $this->mock(RaidFinderService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('findTargets');
        });

        try {
            $this->raidFinderRequest()
                ->assertServiceUnavailable()
                ->assertHeader('Retry-After', '2')
                ->assertJsonPath('state', 'temporary_failure');
        } finally {
            $lock->release();
        }
    }

    public function test_member_page_uses_inline_live_states_without_native_alerts(): void
    {
        $this->actingAs($this->user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('defense.raid-finder'))
            ->assertOk()
            ->assertSee('data-raid-state-panel="loading"', false)
            ->assertSee('data-raid-state-panel="filtered_empty"', false)
            ->assertSee('data-raid-state-panel="rate_limited"', false)
            ->assertSee('data-raid-state-panel="stale"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertDontSee('alert(', false);
    }

    private function raidFinderRequest()
    {
        return $this->actingAs($this->user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->getJson(route('api.raid-finder.show', ['nation_id' => $this->nation->id]));
    }

    /**
     * @return array<int, class-string>
     */
    private function optionalIdentityMiddleware(): array
    {
        return [
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function target(): array
    {
        return [
            'nation' => (object) [
                'id' => 9876,
                'leader_name' => 'Target Leader',
                'alliance' => (object) [
                    'id' => 456,
                    'name' => 'Target Alliance',
                ],
                'num_cities' => 31,
                'last_active' => '2026-08-05T12:00:00Z',
                'score' => 7654.32,
            ],
            'value' => 42157764,
            'last_beige' => 38750000,
            'defensive_wars' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedTarget(): array
    {
        return [
            'nation' => [
                'id' => 9876,
                'leader_name' => 'Target Leader',
                'alliance' => [
                    'id' => 456,
                    'name' => 'Target Alliance',
                ],
                'num_cities' => 31,
                'last_active' => '2026-08-05T12:00:00Z',
                'score' => 7654.32,
            ],
            'value' => 42157764,
            'last_beige' => 38750000,
            'defensive_wars' => 1,
        ];
    }
}
