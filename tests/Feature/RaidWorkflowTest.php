<?php

namespace Tests\Feature;

use App\Jobs\EvaluateRaidPredictionJob;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Models\RaidPrediction;
use App\Models\War;
use App\Services\Economy\EconomyRules;
use App\Services\RaidFinderService;
use App\Services\RaidPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RaidWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_stockpile_and_simulation_services_produce_personalized_finder_results(): void
    {
        config(['raids.simulation_iterations' => 16]);
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0]);
        $priceSnapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'recorded test market', 'window_started_at' => now()->subWeek(),
            'window_ended_at' => now()->subMinute(), 'calculated_at' => now()->subMinute(),
        ]);
        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $priceSnapshot->items()->create(['resource' => $resource, 'acquisition_price' => 100, 'liquidation_price' => 100]);
        }
        foreach ([$own, $target] as $nation) {
            RaidNationObservation::factory()->create([
                'nation_id' => $nation->id, 'current_key' => 1, 'observed_at' => now()->subMinute(),
                'payload' => [
                    'id' => $nation->id, 'score' => 1000, 'alliance_id' => $nation->alliance_id,
                    'num_cities' => 10, 'cities' => [['id' => 1, 'infrastructure' => 1000, 'population' => 100000]],
                    'last_active' => now()->subDays(15)->toIso8601String(),
                    'soldiers' => $nation->id === $own->id ? 50000 : 100,
                    'tanks' => 0, 'aircraft' => 0, 'ships' => 0,
                    'daily_output' => EconomyRules::emptyResourceBuffer(),
                    'daily_expenses' => EconomyRules::emptyResourceBuffer(),
                    'daily_net' => EconomyRules::emptyResourceBuffer(),
                    'color' => 'blue', 'beige_turns' => 0, 'vacation_mode_turns' => 0,
                    'defensive_wars_count' => 0, 'offensive_wars_count' => 0,
                ],
            ]);
        }
        $loot = array_fill_keys(array_map(fn ($resource) => $resource.'_looted', EconomyRules::RESOURCE_KEYS), 0.0);
        $loot['money_looted'] = 1000000;
        RaidAttackObservation::factory()->create([
            'id' => 80, 'war_id' => 70, 'att_id' => 900, 'def_id' => $target->id,
            'occurred_at' => now()->subDay(), 'observed_at' => now()->subMinute(),
            'payload' => $loot + ['id' => 80, 'war_id' => 70, 'att_id' => 900, 'def_id' => $target->id,
                'victor' => 900, 'type' => 'VICTORY', 'date' => now()->subDay()->toIso8601String(), 'loot_fraction' => 0.1],
        ]);
        $target->update(['score' => 99999, 'beige_turns' => 12, 'vacation_mode_turns' => 3, 'color' => 'beige', 'alliance_id' => 777, 'updated_at' => now()->subHour()]);
        $results = app(RaidFinderService::class)->findTargets($own->id);
        $this->assertCount(1, $results);
        $calculation = $results->first()->get('calculation');
        $this->assertSame(9000000.0, $calculation['resources']['money']['post_loot']);
        $this->assertSame('loot_report', $calculation['resources']['money']['fraction_source']);
        $this->assertSame(100.0, $calculation['prices']['liquidation']['munitions']);
        $prediction = $results->first()->get('prediction');
        $this->assertNotNull($prediction['expected_net'], json_encode($prediction));
        $this->assertGreaterThan(0, $prediction['gross_loot']);
        $this->assertNotEmpty($prediction['approach']);
        $this->assertArrayHasKey('nation_loot', $prediction['components']);
        $this->assertTrue($results->first()->get('availability')['eligible']);
        $this->assertNull($results->first()->get('nation')['alliance']);

        Queue::fake();
        $war = War::query()->create([
            'id' => 91, 'date' => now(), 'reason' => 'Recorded raid workflow',
            'war_type' => 'RAID', 'turns_left' => 60,
            'att_id' => $own->id, 'att_alliance_id' => 777, 'att_alliance_position' => 'MEMBER',
            'def_id' => $target->id, 'def_alliance_id' => null, 'def_alliance_position' => 'NOALLIANCE',
        ]);
        $service = app(RaidPredictionService::class);
        $capture = $service->captureWar($war);
        $this->assertSame(RaidPrediction::CAPTURE_READY, $capture->capture_status);
        Queue::assertPushed(EvaluateRaidPredictionJob::class);
        $capture->refresh();
        $frozen = $capture->frozen_payload;
        $this->assertSame(0.0, (float) ($frozen['context']['hours_since_observation'] ?? -1));

        RaidNationObservation::factory()->create([
            'nation_id' => $target->id, 'observed_at' => now()->addMinute(),
            'payload' => ['id' => $target->id, 'soldiers' => 9999999], 'provenance_war_ids' => [91],
        ]);
        $priceSnapshot->items()->update(['acquisition_price' => 999999, 'liquidation_price' => 999999]);
        (new EvaluateRaidPredictionJob($capture->id))->handle($service);

        $capture->refresh();
        $this->assertSame(RaidPrediction::EVALUATION_COMPLETE, $capture->evaluation_status);
        $this->assertNotNull($capture->expected_net);
        $this->assertSame($frozen, $capture->frozen_payload);
        $this->assertSame(100, $capture->target_snapshot['soldiers']);
        $this->assertEquals(100, $capture->price_snapshot['acquisition']['coal']);
        $this->assertSame($frozen, $service->captureWar($war)->frozen_payload);
    }
}
