<?php

namespace Tests\Feature\Raids;

use App\Exceptions\PWQueryFailedException;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\User;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class RaidAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const ATTACKER = 1001;

    private const TARGET = 2002;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forever('alliances:membership:ids', [777]);
        SettingService::setTopRaidable(1);
        Alliance::factory()->create(['score' => 10_000_000]);
        Alliance::factory()->create(['id' => 777, 'score' => 100]);
        Nation::factory()->create(['id' => self::ATTACKER, 'alliance_id' => 777]);
        $this->user = User::factory()->verified()->create(['nation_id' => self::ATTACKER]);
    }

    public function test_an_open_target_is_eligible(): void
    {
        $this->fakeApi();

        $this->check()
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('planning_only', false)
            ->assertJsonPath('reasons', [])
            ->assertJsonPath('defensive_wars', 1)
            ->assertJsonPath('offensive_capacity', 5)
            ->assertJsonStructure(['checked_at']);
    }

    public function test_full_defensive_slots_block_the_declaration(): void
    {
        $this->fakeApi(target: ['defensive_wars_count' => 3]);

        $this->check()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('reasons', ['All defensive slots are occupied.']);
    }

    public function test_an_existing_war_with_the_target_blocks_the_declaration(): void
    {
        $this->fakeApi(wars: [['id' => 1, 'att_id' => self::TARGET, 'def_id' => self::ATTACKER]]);

        $this->check()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('reasons', ['You are already fighting this target.']);
    }

    public function test_full_offensive_slots_leave_the_target_for_planning_only(): void
    {
        $this->fakeApi(attacker: ['offensive_wars_count' => 6, 'pirate_economy' => true]);

        $this->check()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('planning_only', true)
            ->assertJsonPath('offensive_capacity', 6)
            ->assertJsonPath('reasons', ['All your offensive slots are occupied.']);
    }

    public function test_protected_and_out_of_range_targets_report_every_reason(): void
    {
        $this->fakeApi(target: ['alliance_id' => 777, 'score' => 10_000, 'beige_turns' => 4]);

        $this->check()->assertJsonPath('reasons', [
            'Target is outside your declaration range.',
            'Alliance raid policy protects this target.',
            'Target is protected or in vacation mode.',
        ]);
    }

    public function test_rate_limits_map_to_429_with_retry_after(): void
    {
        $queries = Mockery::mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->andThrow(new PWQueryFailedException('Too many requests', 429, retryAfterSeconds: 30));
        $this->app->instance(QueryService::class, $queries);

        $this->check()
            ->assertStatus(429)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('state', 'rate_limited')
            ->assertJsonStructure(['message', 'support_id']);
    }

    public function test_non_member_nations_cannot_check_availability(): void
    {
        $outsider = Nation::factory()->create();

        $this->actingAs($this->user)
            ->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->getJson(route('api.raid-finder.availability', ['nation_id' => $outsider->id, 'target_id' => self::TARGET]))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  list<array<string, int>>  $wars
     */
    private function fakeApi(array $attacker = [], array $target = [], array $wars = []): void
    {
        $nations = [
            ['id' => self::ATTACKER, 'score' => 1_000, 'alliance_id' => 777, 'vacation_mode_turns' => 0, 'beige_turns' => 0, 'color' => 'blue', 'defensive_wars_count' => 0, 'offensive_wars_count' => 1, 'pirate_economy' => false, 'advanced_pirate_economy' => false, ...$attacker],
            ['id' => self::TARGET, 'score' => 1_200, 'alliance_id' => 0, 'vacation_mode_turns' => 0, 'beige_turns' => 0, 'color' => 'gray', 'defensive_wars_count' => 1, 'offensive_wars_count' => 0, 'pirate_economy' => false, 'advanced_pirate_economy' => false, ...$target],
        ];
        $queries = Mockery::mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->andReturnUsing(fn (GraphQLQueryBuilder $builder): object => json_decode(json_encode(
            $builder->getRootField() === 'nations' ? $nations : $wars,
            JSON_FORCE_OBJECT,
        )));
        $this->app->instance(QueryService::class, $queries);
    }

    private function check(): TestResponse
    {
        return $this->actingAs($this->user)
            ->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->getJson(route('api.raid-finder.availability', ['nation_id' => self::ATTACKER, 'target_id' => self::TARGET]));
    }
}
