<?php

namespace Tests\Feature\Security;

use App\Exceptions\ProfitabilityContextUnavailable;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\LeaderboardDirectoryService;
use App\Services\NationProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class ProfitabilityAccessControlTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', 777);
        Cache::flush();
        app(AllianceMembershipService::class)->clear();
    }

    public function test_former_member_cannot_view_profitability_leaderboard_or_live_api(): void
    {
        $user = $this->userWithNation(999, 'NOALLIANCE');
        $profitabilityService = Mockery::mock(NationProfitabilityService::class);
        $profitabilityService->shouldNotReceive('calculateLiveNationProfitabilityById');
        $this->app->instance(NationProfitabilityService::class, $profitabilityService);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('leaderboards.index', ['board' => 'profitability']))
            ->assertForbidden();

        $this->actingAsSanctum($user);
        $this->getJson('/api/v1/nations/1234/profitability')->assertForbidden();
    }

    public function test_current_member_can_view_profitability_leaderboard_and_live_api(): void
    {
        $user = $this->userWithNation(777);
        $directoryService = Mockery::mock(LeaderboardDirectoryService::class);
        $directoryService->shouldReceive('getPageData')
            ->once()
            ->with('profitability', null, null, $user->nation_id)
            ->andReturn($this->leaderboardViewData());
        $this->app->instance(LeaderboardDirectoryService::class, $directoryService);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('leaderboards.index', ['board' => 'profitability']))
            ->assertOk();

        $profitabilityService = Mockery::mock(NationProfitabilityService::class);
        $profitabilityService->shouldReceive('calculateLiveNationProfitabilityById')
            ->once()
            ->with(1234)
            ->andReturn([
                'nation_id' => 1234,
                'converted_profit_per_day' => 500000,
                'calculation_context' => [
                    'world_snapshot_id' => 18,
                    'game_date' => '2126-09-21',
                    'season_month' => 9,
                    'economy_context_synced_at' => '2026-08-02T12:16:00+00:00',
                    'economy_context_stale' => false,
                    'treasure_income_modifier' => 0.05,
                    'color_turn_bonus' => 195255,
                    'military_research' => ['air_capacity' => 12],
                ],
            ]);
        $this->app->instance(NationProfitabilityService::class, $profitabilityService);

        $this->actingAsSanctum($user);
        $this->getJson('/api/v1/nations/1234/profitability')
            ->assertOk()
            ->assertJsonPath('nation_id', 1234)
            ->assertJsonPath('calculation_context.world_snapshot_id', 18)
            ->assertJsonPath('calculation_context.game_date', '2126-09-21')
            ->assertJsonPath('calculation_context.season_month', 9)
            ->assertJsonPath('calculation_context.economy_context_stale', false)
            ->assertJsonMissingPath('calculation_context.treasure_income_modifier')
            ->assertJsonMissingPath('calculation_context.color_turn_bonus')
            ->assertJsonMissingPath('calculation_context.military_research');
    }

    public function test_live_profitability_api_is_rate_limited_per_user(): void
    {
        $user = $this->userWithNation(777);
        $profitabilityService = Mockery::mock(NationProfitabilityService::class);
        $profitabilityService->shouldReceive('calculateLiveNationProfitabilityById')
            ->times(10)
            ->andReturn(['nation_id' => 1234]);
        $this->app->instance(NationProfitabilityService::class, $profitabilityService);
        $this->actingAsSanctum($user);

        foreach (range(1, 10) as $attempt) {
            $this->getJson('/api/v1/nations/1234/profitability')->assertOk();
        }

        $this->getJson('/api/v1/nations/1234/profitability')->assertTooManyRequests();
    }

    public function test_live_profitability_api_reports_unavailable_pricing(): void
    {
        $user = $this->userWithNation(777);
        $profitabilityService = Mockery::mock(NationProfitabilityService::class);
        $profitabilityService->shouldReceive('calculateLiveNationProfitabilityById')
            ->once()
            ->andThrow(new ProfitabilityPricingUnavailable);
        $this->app->instance(NationProfitabilityService::class, $profitabilityService);
        $this->actingAsSanctum($user);

        $this->getJson('/api/v1/nations/1234/profitability')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'PROFITABILITY_PRICING_UNAVAILABLE');
    }

    public function test_live_profitability_api_reports_unavailable_economy_context(): void
    {
        $user = $this->userWithNation(777);
        $profitabilityService = Mockery::mock(NationProfitabilityService::class);
        $profitabilityService->shouldReceive('calculateLiveNationProfitabilityById')
            ->once()
            ->andThrow(new ProfitabilityContextUnavailable);
        $this->app->instance(NationProfitabilityService::class, $profitabilityService);
        $this->actingAsSanctum($user);

        $this->getJson('/api/v1/nations/1234/profitability')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'PROFITABILITY_CONTEXT_UNAVAILABLE');
    }

    private function userWithNation(int $allianceId, string $position = 'MEMBER'): User
    {
        $nation = Nation::factory()->create([
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
        ]);

        return $this->createVerifiedUser(['nation_id' => $nation->id]);
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
    private function leaderboardViewData(): array
    {
        $activeBoard = [
            'slug' => 'profitability',
            'title' => 'Daily nation profitability',
            'description' => 'Current member profitability.',
            'eyebrow' => 'Economy',
            'name' => 'Profitability',
            'icon' => '$',
            'partial' => 'leaderboards.partials.profitability',
        ];

        return [
            'activeBoard' => $activeBoard,
            'activePayload' => ['rows' => []],
            'liveBoards' => [$activeBoard],
            'dashboardBoards' => [],
            'plannedBoards' => [],
        ];
    }
}
