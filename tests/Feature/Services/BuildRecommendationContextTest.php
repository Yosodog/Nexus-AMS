<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyRules;
use App\Services\NationBuildRecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildRecommendationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-02 18:00:00 UTC');
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function pinned_inputs_republish_a_legacy_recommendation_with_calendar_context(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
            'continent' => 'NA',
            'num_cities' => 1,
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 0,
            'economy_context_synced_at' => now(),
        ]);
        City::query()->create([
            'nation_id' => $nation->id,
            'name' => 'Build City',
            'date' => '2025-08-02',
            'nuke_date' => '2126-09-20',
            'infrastructure' => 100,
            'land' => 500,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            'wind_power' => 1,
        ]);
        $marketSnapshot = $this->marketSnapshot();
        $worldSnapshot = RadiationSnapshot::query()->create([
            'snapshot_at' => now()->subHours(4),
            'game_date' => '2126-09-21',
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);
        $legacy = NationBuildRecommendation::query()->create([
            'nation_id' => $nation->id,
            'alliance_id' => 777,
            'model_version' => 1,
            'recommended_build_json' => [],
            'resource_profit_per_day' => [],
            'calculated_at' => now()->subDay(),
        ]);

        $recommendation = app(NationBuildRecommendationService::class)
            ->refreshStoredRecommendationForNationId(
                $nation->id,
                $marketSnapshot->id,
                $worldSnapshot->id
            );

        $this->assertNotNull($recommendation);
        $this->assertSame($legacy->id, $recommendation->id);
        $this->assertSame(EconomyRules::MODEL_VERSION, $recommendation->model_version);
        $this->assertSame($worldSnapshot->id, $recommendation->calculation_context['world_snapshot_id']);
        $this->assertSame('2126-09-21', $recommendation->calculation_context['game_date']);
        $this->assertSame(9, $recommendation->calculation_context['season_month']);
        $this->assertSame(1, $recommendation->calculation_context['city_count']);
        $this->assertSame('highest_recovered_city', $recommendation->calculation_context['target_strategy']);
        $this->assertFalse($recommendation->calculation_context['economy_context_stale']);
        $this->assertLessThan(400, $recommendation->pollution);
    }

    private function marketSnapshot(): MarketPriceSnapshot
    {
        $snapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'test completed-market prices',
            'window_started_at' => now()->subDays(7),
            'window_ended_at' => now(),
            'calculated_at' => now(),
        ]);
        $snapshot->items()->createMany(collect(EconomyRules::TRADE_RESOURCES)
            ->map(fn (string $resource): array => [
                'resource' => $resource,
                'acquisition_price' => 100,
                'liquidation_price' => 90,
            ])->all());

        return $snapshot;
    }
}
