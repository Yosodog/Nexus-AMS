<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\RaidNationObservation;
use App\Services\RaidFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RaidFinderProfitTest extends TestCase
{
    use RefreshDatabase;

    public function test_alliance_less_target_is_allowed_but_stale_availability_is_not_confirmed(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0, 'defensive_wars_count' => 0]);
        $service = app(RaidFinderService::class);
        $this->assertTrue($service->availability($own->id, $target->id)['eligible']);
        foreach ([$own, $target] as $nation) {
            RaidNationObservation::factory()->create(['nation_id' => $nation->id, 'payload' => ['id' => $nation->id, 'score' => 1000, 'alliance_id' => $nation->alliance_id, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0, 'defensive_wars_count' => 0]]);
        }
        $this->assertTrue($service->availability($own->id, $target->id)['eligible']);
        $this->travel(6)->minutes();
        $this->assertNull($service->availability($own->id, $target->id)['eligible']);
    }

    public function test_fresh_full_slots_and_existing_wars_are_rejected(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0, 'defensive_wars_count' => 0]);
        RaidNationObservation::factory()->create(['nation_id' => $own->id, 'payload' => ['score' => 1000]]);
        $observation = RaidNationObservation::factory()->create(['nation_id' => $target->id, 'payload' => ['score' => 1000, 'defensive_wars_count' => 3]]);
        $this->assertFalse(app(RaidFinderService::class)->availability($own->id, $target->id)['eligible']);
        $observation->update(['payload' => ['score' => 1000, 'defensive_wars_count' => 1, 'active_wars' => [['att_id' => $own->id, 'def_id' => $target->id]]]]);
        $this->assertContains('You are already fighting this target.', app(RaidFinderService::class)->availability($own->id, $target->id)['reasons']);
    }

    public function test_general_nation_update_cannot_erase_observed_full_defensive_slots(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0, 'defensive_wars_count' => 0]);
        RaidNationObservation::factory()->create([
            'nation_id' => $target->id, 'observed_at' => now()->subHour(),
            'payload' => ['score' => 1000, 'defensive_wars_count' => 3, 'active_wars' => [
                ['id' => 1, 'att_id' => 900, 'def_id' => $target->id],
                ['id' => 2, 'att_id' => 901, 'def_id' => $target->id],
                ['id' => 3, 'att_id' => 902, 'def_id' => $target->id],
            ]],
        ]);
        $availability = app(RaidFinderService::class)->availability($own->id, $target->id);
        $this->assertFalse($availability['eligible']);
        $this->assertFalse($availability['planning_only']);
        $this->assertSame(3, $availability['defensive_wars']);

        RaidNationObservation::factory()->create([
            'nation_id' => $target->id, 'observed_at' => now()->addSecond(),
            'payload' => ['score' => 1000, 'defensive_wars_count' => 2, 'active_wars' => []],
        ]);
        $this->travel(2)->seconds();
        $this->assertTrue(app(RaidFinderService::class)->availability($own->id, $target->id)['eligible']);
    }

    public function test_newer_subscription_state_overrides_old_raid_availability(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000, 'vacation_mode_turns' => 0, 'offensive_wars_count' => 0]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0, 'defensive_wars_count' => 0]);
        RaidNationObservation::factory()->create(['nation_id' => $target->id, 'observed_at' => now()->subHour(), 'payload' => [
            'score' => 1000, 'color' => 'beige', 'beige_turns' => 10, 'defensive_wars_count' => 0,
        ]]);
        $this->assertTrue(app(RaidFinderService::class)->availability($own->id, $target->id)['eligible']);
        $target->update(['defensive_wars_count' => 3]);
        $this->assertFalse(app(RaidFinderService::class)->availability($own->id, $target->id)['eligible']);
    }

    public function test_finder_keeps_targets_for_planning_when_offensive_slots_are_full(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000, 'vacation_mode_turns' => 0]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0, 'defensive_wars_count' => 0]);
        RaidNationObservation::factory()->create(['nation_id' => $own->id, 'observed_at' => now(), 'payload' => [
            'id' => $own->id, 'score' => 1000, 'offensive_wars_count' => 5,
            'pirate_economy' => false, 'advanced_pirate_economy' => false,
        ]]);
        Queue::fake();
        $result = app(RaidFinderService::class)->findTargets($own->id)->firstWhere('nation.id', $target->id);
        $this->assertNotNull($result);
        $this->assertTrue(data_get($result, 'availability.planning_only'));
        $this->assertFalse(data_get($result, 'availability.eligible'));
    }

    public function test_live_availability_checks_current_policy_protection_and_offensive_capacity(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000, 'vacation_mode_turns' => 0]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0]);
        $current = [
            $own->id => ['score' => 1000, 'offensive_wars_count' => 5, 'pirate_economy' => false, 'advanced_pirate_economy' => false, 'observed_at' => now()->toIso8601String()],
            $target->id => ['score' => 1000, 'alliance_id' => 0, 'vacation_mode_turns' => 0, 'beige_turns' => 0, 'color' => 'blue', 'defensive_wars_count' => 0, 'observed_at' => now()->toIso8601String()],
        ];
        $service = app(RaidFinderService::class);
        $this->assertContains('All your offensive slots are occupied.', $service->availability($own->id, $target->id, $current)['reasons']);
        $planning = $service->availability($own->id, $target->id, $current);
        $this->assertFalse($planning['eligible']);
        $this->assertTrue($planning['planning_only']);
        $this->assertSame(5, $planning['offensive_wars']);
        $current[$target->id]['beige_turns'] = 1;
        $this->assertFalse($service->availability($own->id, $target->id, $current)['planning_only']);
        $current[$target->id]['beige_turns'] = 0;
        $current[$own->id]['pirate_economy'] = true;
        $this->assertTrue($service->availability($own->id, $target->id, $current)['eligible']);
        $current[$target->id]['beige_turns'] = 1;
        $this->assertFalse($service->availability($own->id, $target->id, $current)['eligible']);
        $current[$target->id]['beige_turns'] = 0;
        $current[$target->id]['alliance_id'] = 777;
        $this->assertFalse($service->availability($own->id, $target->id, $current)['eligible']);
    }
}
