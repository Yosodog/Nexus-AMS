<?php

namespace Tests\Feature\Raids;

use App\Enums\NexusRuntime;
use App\Models\Nation;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\TestCase;

class RaidWorldCommandsTest extends TestCase
{
    use BuildsRaidFixtures;
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
}
