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
use App\Services\Raids\RaidProfileDirtyMarker;
use App\Services\Raids\RaidTargetProfileBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\Concerns\RecordsDatabaseWrites;
use Tests\TestCase;

class RaidTargetProfileBuilderTest extends TestCase
{
    use BuildsRaidFixtures;
    use RecordsDatabaseWrites;
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

    public function test_rebuilding_an_unchanged_clean_profile_writes_nothing(): void
    {
        $nation = $this->nation();
        $this->builder()->build([$nation->id]);
        $before = RaidTargetProfile::query()->findOrFail($nation->id)->getAttributes();

        $writes = $this->recordWrites('raid_target_profiles', fn () => $this->builder()->build([$nation->id]));

        $this->assertSame(0, $writes['rows']);
        $this->assertSame([], $writes['statements']);
        $this->assertSame($before, RaidTargetProfile::query()->findOrFail($nation->id)->getAttributes());
    }

    public function test_rebuilding_an_unchanged_dirty_profile_writes_only_time_derived_columns_and_clears_the_mark(): void
    {
        $nation = $this->nation();
        $this->builder()->build([$nation->id]);
        app(RaidProfileDirtyMarker::class)->mark([$nation->id]);
        $before = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();

        $writes = $this->recordWrites('raid_target_profiles', fn () => $this->builder()->build([$nation->id]));

        $after = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(1, $writes['rows']);
        $this->assertCount(1, $writes['statements']);
        $this->assertStringStartsWith('update', strtolower($writes['statements'][0]->sql));
        $this->assertNull($after->dirty_at);
        $this->assertTrue($after->computed_at->equalTo(now()));
        $this->assertEqualsCanonicalizing(
            ['baseline_at', 'computed_at', 'updated_at', 'dirty_at'],
            array_keys(array_diff_assoc($after->getAttributes(), $before->getAttributes())),
        );
    }

    public function test_changed_nations_update_only_their_changed_columns(): void
    {
        $nation = $this->nation(['score' => 1_000]);
        $this->builder()->build([$nation->id]);
        $nation->update(['score' => 1_100.5]);

        $writes = $this->recordWrites('raid_target_profiles', fn () => $this->builder()->build([$nation->id]));

        $this->assertSame(1, $writes['rows']);
        $this->assertCount(1, $writes['statements']);
        $this->assertStringNotContainsString('insert', strtolower($writes['statements'][0]->sql));
        $this->assertStringNotContainsString('nation_name', $writes['statements'][0]->sql);
        $this->assertSame(1_100.5, RaidTargetProfile::query()->findOrFail($nation->id)->score);
    }

    public function test_write_statements_do_not_grow_with_the_number_of_settled_dirty_profiles(): void
    {
        $small = collect(range(1, 3))->map(fn (): int => $this->nation()->id)->all();
        $large = collect(range(1, 30))->map(fn (): int => $this->nation()->id)->all();
        $this->builder()->build([...$small, ...$large]);
        app(RaidProfileDirtyMarker::class)->mark([...$small, ...$large]);
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();

        $smallWrites = $this->recordWrites('raid_target_profiles', fn () => $this->builder()->build($small));
        $largeWrites = $this->recordWrites('raid_target_profiles', fn () => $this->builder()->build($large));

        $this->assertCount(1, $smallWrites['statements']);
        $this->assertCount(1, $largeWrites['statements']);
        $this->assertSame(3, $smallWrites['rows']);
        $this->assertSame(30, $largeWrites['rows']);
        $this->assertSame(0, RaidTargetProfile::query()->whereNotNull('dirty_at')->count());
    }

    public function test_changed_profiles_are_written_in_one_bulk_update(): void
    {
        $nations = collect(range(1, 5))->map(fn (): Nation => $this->nation(['score' => 1_000]));
        $this->builder()->build($nations->pluck('id')->all());
        $nations->each(fn (Nation $nation, int $index) => $nation->update(['score' => 1_000 + ($index + 1) * 10.25]));
        $unchanged = $this->nation();
        $this->builder()->build([$unchanged->id]);

        $writes = $this->recordWrites('raid_target_profiles', fn () => $this->builder()->build([...$nations->pluck('id')->all(), $unchanged->id]));

        $this->assertCount(1, $writes['statements']);
        $this->assertSame(5, $writes['rows']);
        $nations->each(fn (Nation $nation, int $index) => $this->assertSame(
            1_000 + ($index + 1) * 10.25,
            RaidTargetProfile::query()->findOrFail($nation->id)->score,
        ));
    }

    public function test_fresh_dirty_marks_are_not_cleared(): void
    {
        $nation = $this->nation();
        $this->builder()->build([$nation->id]);
        app(RaidProfileDirtyMarker::class)->mark([$nation->id]);
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS - 1)->seconds();

        $this->builder()->build([$nation->id]);

        $this->assertNotNull(RaidTargetProfile::query()->findOrFail($nation->id)->dirty_at);
    }

    public function test_a_mark_placed_while_a_profile_is_rebuilding_keeps_it_dirty(): void
    {
        $nation = $this->nation(['score' => 1_000]);
        $this->builder()->build([$nation->id]);
        app(RaidProfileDirtyMarker::class)->mark([$nation->id]);
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS + 5)->seconds();
        $interleaved = false;

        DB::listen(function (QueryExecuted $query) use ($nation, &$interleaved): void {
            if ($interleaved || ! str_contains($query->sql, 'from "nations"')) {
                return;
            }

            $interleaved = true;
            Nation::query()->whereKey($nation->id)->update(['score' => 1_200]);
            app(RaidProfileDirtyMarker::class)->mark([$nation->id]);
        });

        $this->builder()->build([$nation->id]);

        $this->assertTrue($interleaved);
        $racing = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(1_000.0, $racing->score);
        $this->assertTrue($racing->dirty_at?->equalTo(now()));

        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();
        $this->artisan('raids:process-profiles')->assertSuccessful();

        $settled = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(1_200.0, $settled->score);
        $this->assertNull($settled->dirty_at);
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
