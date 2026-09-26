<?php

namespace Tests\Feature\Raids;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationAccount;
use App\Models\NationMilitary;
use App\Models\RadiationSnapshot;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\Economy\EconomyRules;
use App\Services\Raids\RaidTargetProfileBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\TestCase;

class RaidTargetProfileBuilderTest extends TestCase
{
    use BuildsRaidFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        $this->seedRaidMarketPrices(100);
        RadiationSnapshot::query()->create([
            'snapshot_at' => now()->subHour(),
            'game_date' => '2026-06-01',
            ...array_fill_keys(['global', 'north_america', 'south_america', 'europe', 'africa', 'asia', 'australia', 'antarctica'], 0),
        ]);
    }

    public function test_profile_is_built_from_nation_cities_military_and_account(): void
    {
        $nation = $this->nation(['score' => 1_234.56, 'num_cities' => 2, 'war_policy' => 'TURTLE', 'defensive_wars_count' => 2, 'created_at' => now()->subYear()]);
        NationMilitary::query()->create(['nation_id' => $nation->id, 'soldiers' => 5_000, 'tanks' => 100, 'aircraft' => 10, 'ships' => 2]);
        NationAccount::query()->create(['nation_id' => $nation->id, 'last_active' => now()->subDays(4)]);

        $this->assertSame(1, $this->builder()->build([$nation->id]));

        $profile = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame((string) $nation->leader_name, $profile->leader_name);
        $this->assertSame((int) $nation->alliance_id, $profile->alliance_id);
        $this->assertSame(1_234.56, $profile->score);
        $this->assertSame(2, $profile->num_cities);
        $this->assertSame(5_000, $profile->soldiers);
        $this->assertSame(100, $profile->tanks);
        $this->assertSame(2, $profile->defensive_wars);
        $this->assertSame('TURTLE', $profile->war_policy);
        $this->assertSame(2_000.0, $profile->highest_city_infra);
        $this->assertSame(1_500.0, $profile->avg_infra);
        $this->assertGreaterThan(0, $profile->highest_city_population);
        $this->assertTrue($profile->last_active->equalTo(now()->subDays(4)));
        $this->assertSame(RaidTargetProfile::BASELINE_PRODUCTION_ONLY, $profile->baseline_kind);
        $this->assertTrue($profile->baseline_at->equalTo(now()->subDays(14)));
        $this->assertNotSame(0.0, $profile->daily_net_money);
        $this->assertNotNull($profile->economy_hash);
        $this->assertNotNull($profile->computed_at);
        $this->assertNull($profile->dirty_at);
        $this->assertGreaterThan(0, $profile->projected_value);
    }

    public function test_latest_victory_with_a_fraction_becomes_the_loot_baseline(): void
    {
        $nation = $this->nation();
        $this->victory($nation, 11, now()->subDays(6), 500, 0.1);
        $this->victory($nation, 12, now()->subDays(2), 100, 0.1);
        $this->victory($nation, 13, now()->subDay(), 999, null);

        $this->builder()->build([$nation->id]);

        $profile = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(RaidTargetProfile::BASELINE_LOOT, $profile->baseline_kind);
        $this->assertSame(12, $profile->baseline_attack_id);
        $this->assertTrue($profile->baseline_at->equalTo(now()->subDays(2)));
        $this->assertSame(900.0, $profile->baseline_money);
    }

    public function test_new_nations_fall_back_to_production_since_creation(): void
    {
        $nation = $this->nation(['created_at' => now()->subDays(3)]);

        $this->builder()->build([$nation->id]);

        $profile = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(RaidTargetProfile::BASELINE_PRODUCTION_ONLY, $profile->baseline_kind);
        $this->assertTrue($profile->baseline_at->equalTo(now()->subDays(3)));
        $this->assertSame(0.0, $profile->baseline_money);
    }

    public function test_economy_is_recomputed_only_when_its_inputs_change(): void
    {
        $nation = $this->nation();
        $this->builder()->build([$nation->id]);
        $first = RaidTargetProfile::query()->findOrFail($nation->id);

        $this->travel(2)->hours();
        $this->builder()->build([$nation->id]);
        $unchanged = RaidTargetProfile::query()->findOrFail($nation->id);

        $this->assertTrue($unchanged->economy_computed_at->equalTo($first->economy_computed_at));
        $this->assertTrue($unchanged->computed_at->greaterThan($first->computed_at));

        City::query()->where('nation_id', $nation->id)->first()->update(['infrastructure' => 2_500]);
        $this->builder()->build([$nation->id]);
        $changed = RaidTargetProfile::query()->findOrFail($nation->id);

        $this->assertTrue($changed->economy_computed_at->greaterThan($first->economy_computed_at));
        $this->assertNotSame($first->economy_hash, $changed->economy_hash);
    }

    public function test_retention_is_measured_between_consecutive_victories(): void
    {
        $nation = $this->nation();
        $this->builder()->build([$nation->id]);
        RaidTargetProfile::query()->whereKey($nation->id)->update(
            collect(EconomyRules::RESOURCE_KEYS)->mapWithKeys(fn (string $resource): array => ['daily_net_'.$resource => $resource === 'money' ? 200 : 0])->all(),
        );
        $this->victory($nation, 21, now()->subDays(5), 100, 0.1);
        $this->victory($nation, 22, now()->subDays(3), 110, 0.1);
        $this->victory($nation, 23, now()->subDays(3)->addHours(2), 100, 0.1);

        $this->builder()->build([$nation->id]);

        $profile = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(1, $profile->retention_samples);
        $this->assertSame(0.5, $profile->retention_observed);
        $this->assertSame(200.0, $profile->daily_net_money);
    }

    public function test_deleted_nations_lose_their_profile(): void
    {
        $nation = $this->nation();
        $this->builder()->build([$nation->id]);
        $nation->delete();
        RaidTargetProfile::factory()->create(['nation_id' => 999_999]);

        $this->assertSame(0, $this->builder()->build([$nation->id, 999_999]));

        $this->assertDatabaseCount('raid_target_profiles', 0);
    }

    public function test_batch_query_count_does_not_grow_with_the_number_of_nations(): void
    {
        $single = [$this->nation()->id];
        $many = collect(range(1, 50))->map(fn (): int => $this->nation()->id)->all();

        $singleQueries = $this->countQueries(fn () => $this->builder()->build($single));
        $manyQueries = $this->countQueries(fn () => $this->builder()->build($many));

        $this->assertSame($singleQueries, $manyQueries);
        $this->assertSame(50, RaidTargetProfile::query()->whereIn('nation_id', $many)->whereNotNull('computed_at')->count());
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nation(array $attributes = []): Nation
    {
        $nation = Nation::factory()->create(['num_cities' => 2, 'created_at' => now()->subYear(), ...$attributes]);

        foreach ([1_000, 2_000] as $index => $infrastructure) {
            City::query()->create([
                'nation_id' => $nation->id,
                'name' => "City {$index}",
                'date' => now()->subYear()->toDateString(),
                'infrastructure' => $infrastructure,
                'land' => 1_500,
                'powered' => true,
                ...array_fill_keys([
                    'oil_power', 'wind_power', 'coal_power', 'uranium_mine', 'barracks', 'police_station',
                    'hospital', 'recycling_center', 'subway', 'supermarket', 'bank', 'shopping_mall', 'stadium',
                    'lead_mine', 'iron_mine', 'bauxite_mine', 'oil_refinery', 'aluminum_refinery', 'steel_mill',
                    'munitions_factory', 'factory', 'hangar', 'drydock', 'coal_mine', 'oil_well',
                ], 0),
                'nuclear_power' => 1,
                'farm' => 5,
            ]);
        }

        return $nation;
    }

    private function victory(Nation $nation, int $id, CarbonInterface $at, float $money, ?float $fraction): void
    {
        RaidLootEvent::factory()->create([
            ...$this->raidResources(['money' => $money]),
            'id' => $id,
            'loser_nation_id' => $nation->id,
            'occurred_at' => $at,
            'loot_fraction' => $fraction,
            'fraction_source' => $fraction === null ? 'pending' : 'modifiers',
        ]);
    }

    private function builder(): RaidTargetProfileBuilder
    {
        $this->app->forgetScopedInstances();

        return app(RaidTargetProfileBuilder::class);
    }
}
