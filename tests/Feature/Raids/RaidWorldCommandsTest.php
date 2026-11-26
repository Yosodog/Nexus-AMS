<?php

namespace Tests\Feature\Raids;

use App\Enums\NexusRuntime;
use App\Models\Nation;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\Economy\MarketValuationService;
use App\Services\Raids\RaidProfileDirtyMarker;
use App\Services\Raids\RaidResources;
use App\Services\RaidStockpileEstimator;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\Concerns\RecordsDatabaseWrites;
use Tests\TestCase;

class RaidWorldCommandsTest extends TestCase
{
    use BuildsRaidFixtures;
    use RecordsDatabaseWrites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        $this->seedRaidMarketPrices(100);
    }

    public function test_project_profiles_rewrites_projected_values_in_bulk(): void
    {
        $profiles = RaidTargetProfile::factory()->count(3)->create([
            'last_active' => now()->subDays(10),
            'baseline_at' => now()->subDays(2),
            'projected_value' => 0,
            ...collect($this->raidResources(['money' => 1_000, 'coal' => 10]))->mapWithKeys(fn ($amount, $resource) => ['baseline_'.$resource => $amount])->all(),
            ...collect($this->raidResources(['money' => 500]))->mapWithKeys(fn ($amount, $resource) => ['daily_net_'.$resource => $amount])->all(),
        ]);

        $this->artisan('raids:project-profiles')->assertSuccessful();

        foreach ($profiles as $profile) {
            $this->assertSame(3_000.0, $profile->refresh()->projected_value);
            $this->assertTrue($profile->projected_at->equalTo(now()));
        }
    }

    public function test_project_profiles_writes_only_changed_values(): void
    {
        $growing = $this->projectable(3, ['money' => 500], 0);
        $flat = $this->projectable(2, [], 1_000);
        $projectedAt = now()->subHour();
        RaidTargetProfile::query()->update(['projected_at' => $projectedAt]);

        $writes = $this->recordWrites('raid_target_profiles', fn () => $this->artisan('raids:project-profiles')->assertSuccessful());

        $this->assertSame(3, $writes['rows']);
        $this->assertCount(1, $writes['statements']);
        $this->assertSame(
            $growing->pluck('nation_id')->sort()->values()->all(),
            collect(array_slice($writes['statements'][0]->bindings, -3))->sort()->values()->all(),
        );

        foreach ($growing as $profile) {
            $this->assertSame(2_000.0, $profile->refresh()->projected_value);
            $this->assertTrue($profile->projected_at->equalTo(now()));
        }

        foreach ($flat as $profile) {
            $this->assertSame(1_000.0, $profile->refresh()->projected_value);
            $this->assertTrue($profile->projected_at->equalTo($projectedAt));
        }

        $repeat = $this->recordWrites('raid_target_profiles', fn () => $this->artisan('raids:project-profiles')->assertSuccessful());

        $this->assertSame(0, $repeat['rows']);
        $this->assertSame([], $repeat['statements']);
    }

    public function test_skipped_projections_keep_the_finder_ordering_and_eligibility(): void
    {
        $profiles = $this->projectable(2, ['money' => 500], 0)
            ->merge($this->projectable(2, [], 1_000))
            ->merge($this->projectable(1, ['money' => 2_000], 0));
        $placeholder = RaidTargetProfile::factory()->create(['computed_at' => null, 'projected_value' => 0]);
        $computedAt = RaidTargetProfile::query()->pluck('computed_at', 'nation_id');

        $this->artisan('raids:project-profiles')->assertSuccessful();

        $estimator = app(RaidStockpileEstimator::class);
        $prices = app(MarketValuationService::class)->current();
        $expected = $profiles
            ->mapWithKeys(fn (RaidTargetProfile $profile): array => [
                $profile->nation_id => round(RaidResources::liquidationValue($estimator->project($profile->refresh(), CarbonImmutable::now())['resources'], $prices), 2),
            ])
            ->sortKeys()
            ->sortDesc();
        $finderOrder = RaidTargetProfile::query()
            ->whereNotNull('computed_at')
            ->orderByDesc('projected_value')
            ->orderBy('nation_id')
            ->pluck('projected_value', 'nation_id');

        $this->assertSame($expected->keys()->all(), $finderOrder->keys()->all());
        $this->assertEquals($expected->all(), $finderOrder->all());
        $this->assertSame([5_000.0, 2_000.0, 2_000.0, 1_000.0, 1_000.0], $finderOrder->values()->all());
        $this->assertSame(0.0, $placeholder->refresh()->projected_value);
        $this->assertEquals($computedAt->all(), RaidTargetProfile::query()->pluck('computed_at', 'nation_id')->all());
    }

    public function test_marking_an_already_dirty_profile_is_a_no_op_until_the_mark_settles(): void
    {
        $profile = RaidTargetProfile::factory()->create(['dirty_at' => null]);
        $marker = app(RaidProfileDirtyMarker::class);
        $marker->mark([$profile->nation_id]);
        $markedAt = $profile->refresh()->dirty_at;
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS - 1)->seconds();

        $writes = $this->recordWrites('raid_target_profiles', fn () => $marker->mark([$profile->nation_id, $profile->nation_id]));

        $this->assertSame(0, $writes['rows']);
        $this->assertTrue($profile->refresh()->dirty_at->equalTo($markedAt));

        $this->travel(1)->seconds();
        $settled = $this->recordWrites('raid_target_profiles', fn () => $marker->mark([$profile->nation_id]));

        $this->assertSame(1, $settled['rows']);
        $this->assertTrue($profile->refresh()->dirty_at->equalTo(now()));
    }

    public function test_marking_creates_placeholders_for_nations_without_a_profile(): void
    {
        $existing = RaidTargetProfile::factory()->create(['dirty_at' => null]);
        $nation = Nation::factory()->create();

        app(RaidProfileDirtyMarker::class)->mark([$existing->nation_id, $nation->id]);

        $placeholder = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertTrue($placeholder->dirty_at->equalTo(now()));
        $this->assertNull($placeholder->computed_at);
        $this->assertTrue($existing->refresh()->dirty_at->equalTo(now()));
    }

    public function test_process_profiles_waits_for_marks_to_settle(): void
    {
        $nation = Nation::factory()->create();
        RaidTargetProfile::factory()->create(['nation_id' => $nation->id, 'computed_at' => null]);
        app(RaidProfileDirtyMarker::class)->mark([$nation->id]);

        $this->artisan('raids:process-profiles')->assertSuccessful();
        $this->assertNull(RaidTargetProfile::query()->findOrFail($nation->id)->computed_at);

        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();
        $this->artisan('raids:process-profiles')->assertSuccessful();

        $profile = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertNotNull($profile->computed_at);
        $this->assertNull($profile->dirty_at);
    }

    public function test_prune_keeps_recent_events_and_current_baselines(): void
    {
        $old = RaidLootEvent::factory()->create(['id' => 1, 'occurred_at' => now()->subDays(200)]);
        $baseline = RaidLootEvent::factory()->create(['id' => 2, 'occurred_at' => now()->subDays(200)]);
        RaidLootEvent::factory()->create(['id' => 3, 'occurred_at' => now()->subDays(5)]);
        RaidTargetProfile::factory()->create(['baseline_attack_id' => $baseline->id]);

        $this->artisan('raids:prune-loot-events')->assertSuccessful();

        $this->assertDatabaseMissing('raid_loot_events', ['id' => $old->id]);
        $this->assertDatabaseHas('raid_loot_events', ['id' => 2]);
        $this->assertDatabaseHas('raid_loot_events', ['id' => 3]);
    }

    public function test_rebuild_resolves_pending_fractions_and_builds_every_nation(): void
    {
        $winner = Nation::factory()->create(['war_policy' => 'PIRATE']);
        $loser = Nation::factory()->create();
        RaidLootEvent::factory()->create([
            'winner_nation_id' => $winner->id,
            'loser_nation_id' => $loser->id,
            'war_type' => 'RAID',
            'loot_fraction' => null,
            'fraction_source' => 'pending',
        ]);

        $this->artisan('raids:rebuild', ['--resolve-fractions' => true])->assertSuccessful();

        $event = RaidLootEvent::query()->firstOrFail();
        $this->assertSame('modifiers', $event->fraction_source);
        $this->assertEqualsWithDelta(0.14, $event->loot_fraction, 1e-9);
        $this->assertSame(2, RaidTargetProfile::query()->whereNotNull('computed_at')->whereNull('dirty_at')->count());
        $this->assertSame(RaidTargetProfile::BASELINE_LOOT, RaidTargetProfile::query()->findOrFail($loser->id)->baseline_kind);
    }

    public function test_process_profiles_only_builds_dirty_rows(): void
    {
        $dirty = Nation::factory()->create();
        $clean = Nation::factory()->create();
        RaidTargetProfile::factory()->create(['nation_id' => $dirty->id, 'dirty_at' => now()->subMinute(), 'computed_at' => null]);
        RaidTargetProfile::factory()->create(['nation_id' => $clean->id, 'computed_at' => now()->subDay()]);

        $this->artisan('raids:process-profiles', ['--limit' => 10])->assertSuccessful();

        $this->assertNotNull(RaidTargetProfile::query()->findOrFail($dirty->id)->computed_at);
        $this->assertNull(RaidTargetProfile::query()->findOrFail($dirty->id)->dirty_at);
        $this->assertTrue(RaidTargetProfile::query()->findOrFail($clean->id)->computed_at->equalTo(now()->subDay()));
    }

    public function test_world_commands_do_nothing_without_world_write_access(): void
    {
        $this->app->instance(RuntimeCapabilities::class, new RuntimeCapabilities(NexusRuntime::HostedTenant));
        RaidTargetProfile::factory()->create(['dirty_at' => now()]);

        foreach (['raids:process-profiles', 'raids:project-profiles', 'raids:refresh-alliance-profiles', 'raids:prune-loot-events', 'raids:rebuild', 'raids:backfill-loot-events'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }

        $this->assertNotNull(RaidTargetProfile::query()->firstOrFail()->dirty_at);
    }

    /**
     * Profiles with a two-day-old loot baseline of 1,000 money and the given daily production.
     *
     * @param  array<string, float|int>  $dailyNet
     * @return Collection<int, RaidTargetProfile>
     */
    private function projectable(int $count, array $dailyNet, float $storedValue): Collection
    {
        return RaidTargetProfile::factory()->count($count)->create([
            'last_active' => now()->subDays(10),
            'baseline_at' => now()->subDays(2),
            'projected_value' => $storedValue,
            ...collect($this->raidResources(['money' => 1_000]))->mapWithKeys(fn ($amount, $resource) => ['baseline_'.$resource => $amount])->all(),
            ...collect($this->raidResources($dailyNet))->mapWithKeys(fn ($amount, $resource) => ['daily_net_'.$resource => $amount])->all(),
        ]);
    }
}
