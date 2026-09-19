<?php

namespace Tests\Feature;

use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Services\Economy\EconomyRules;
use App\Services\QueryService;
use App\Services\RaidIntelligenceRefreshService;
use App\Services\RaidIntelligenceService;
use App\Services\RaidNationSnapshotCompactor;
use App\Services\RaidSimulationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidNationSnapshotCompactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_and_display_only_refreshes_update_one_current_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC'));
        $this->mockRefreshes([
            ['id' => 10, 'nation_name' => 'First Name', 'score' => 1000, 'num_cities' => 0, 'cities' => []],
            ['id' => 10, 'nation_name' => 'Second Name', 'score' => 1000, 'num_cities' => 0, 'cities' => []],
        ]);
        $service = app(RaidIntelligenceRefreshService::class);

        $service->refresh([10], true);
        $first = RaidNationObservation::query()->where('nation_id', 10)->firstOrFail();
        $this->travel(5)->minutes();
        $service->refresh([10], true);

        $current = RaidNationObservation::query()->where('nation_id', 10)->firstOrFail();
        $this->assertSame($first->id, $current->id);
        $this->assertSame(1, $current->current_key);
        $this->assertSame('Second Name', $current->payload['nation_name']);
        $this->assertTrue($first->valid_from->equalTo($current->valid_from));
        $this->assertTrue(now()->equalTo($current->confirmed_through));
        $this->assertDatabaseCount('raid_nation_observations', 1);
    }

    public function test_irrelevant_state_changes_overwrite_current_without_history(): void
    {
        $this->mockRefreshes([
            ['id' => 20, 'score' => 1000, 'num_cities' => 0, 'cities' => []],
            ['id' => 20, 'score' => 1001, 'num_cities' => 0, 'cities' => []],
        ]);
        $service = app(RaidIntelligenceRefreshService::class);

        $service->refresh([20], true);
        $first = RaidNationObservation::query()->where('nation_id', 20)->firstOrFail();
        $this->travel(1)->minute();
        $service->refresh([20], true);

        $current = RaidNationObservation::query()->where('nation_id', 20)->firstOrFail();
        $this->assertSame($first->id, $current->id);
        $this->assertSame(1001, $current->payload['score']);
        $this->assertSame(now()->timestamp, $current->valid_from->timestamp);
        $this->assertDatabaseCount('raid_nation_observations', 1);
    }

    public function test_calculation_change_with_recent_evidence_creates_a_checkpoint(): void
    {
        RaidAttackObservation::factory()->create([
            'id' => 991,
            'att_id' => 30,
            'def_id' => 300,
            'war_id' => 90,
            'occurred_at' => now()->subDay(),
        ]);
        $this->mockRefreshes([
            ['id' => 30, 'score' => 1000, 'num_cities' => 0, 'cities' => []],
            ['id' => 30, 'score' => 1001, 'num_cities' => 0, 'cities' => []],
        ]);
        $service = app(RaidIntelligenceRefreshService::class);

        $service->refresh([30], true);
        $this->travel(1)->minute();
        $service->refresh([30], true);

        $rows = RaidNationObservation::query()->where('nation_id', 30)->orderBy('valid_from')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->current_key);
        $this->assertSame(1, $rows[1]->current_key);
        $this->assertSame(1000, $rows[0]->payload['score']);
        $this->assertSame(1001, $rows[1]->payload['score']);
    }

    public function test_first_active_war_preserves_the_clean_pre_war_state(): void
    {
        $activeWar = [
            'id' => 81,
            'att_id' => 400,
            'def_id' => 40,
            'turns_left' => 60,
            'winner_id' => 0,
            'end_date' => null,
            'def_fortify' => true,
        ];
        $this->mockRefreshes(
            [
                ['id' => 40, 'score' => 1000, 'num_cities' => 0, 'cities' => []],
                ['id' => 40, 'score' => 1000, 'num_cities' => 0, 'cities' => []],
            ],
            [[], [$activeWar]],
        );
        $service = app(RaidIntelligenceRefreshService::class);

        $service->refresh([40], true);
        $this->travel(1)->minute();
        $service->refresh([40], true);

        $rows = RaidNationObservation::query()->where('nation_id', 40)->orderBy('valid_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([], $rows[0]->payload['active_wars']);
        $this->assertSame(81, $rows[1]->payload['active_wars'][0]['id']);
        $this->assertTrue($rows[1]->payload['is_fortified']);
    }

    public function test_compactor_removes_cities_and_preserves_exact_aggregates_and_vectors(): void
    {
        $payload = array_replace($this->nationPayload(), [
            'nation_name' => 'Compact Me',
            'daily_output' => ['money' => 1.25],
            'daily_expenses' => ['money' => 0.125],
            'daily_net' => ['money' => 1.125],
            'production_processes' => [['output' => ['steel' => 3.5], 'inputs' => ['coal' => 3.0]]],
        ]);

        $compact = app(RaidNationSnapshotCompactor::class)->compact($payload);

        $this->assertArrayNotHasKey('cities', $compact);
        $this->assertSame(2, $compact['num_cities']);
        $this->assertSame(1500.5, $compact['highest_city_infra']);
        $this->assertSame(1250.25, $compact['avg_infra']);
        $this->assertSame(125000, $compact['highest_city_population']);
        $this->assertSame(['money' => 1.125], $compact['daily_net']);
    }

    public function test_compact_payload_produces_the_same_simulation_result_as_full_payload(): void
    {
        $full = $this->nationPayload();
        $compact = app(RaidNationSnapshotCompactor::class)->compact($full);
        $resources = array_fill_keys(EconomyRules::RESOURCE_KEYS, 100000.0);
        $prices = array_fill_keys(EconomyRules::RESOURCE_KEYS, 1.0);
        $stockpile = [
            'resources' => $resources,
            'scenarios' => ['expected' => $resources, 'conservative' => $resources, 'optimistic' => $resources],
            'confidence' => 'medium',
        ];
        $context = ['seed' => 42, 'iterations' => 16, 'as_of' => now()->toIso8601String(), 'defensive_wars' => 0];
        $service = app(RaidSimulationService::class);

        $fullResult = $service->evaluate($full, $full, $stockpile, ['acquisition' => $prices, 'liquidation' => $prices], $context);
        $compactResult = $service->evaluate($compact, $compact, $stockpile, ['acquisition' => $prices, 'liquidation' => $prices], $context);

        $this->assertSame($fullResult['expected_net'], $compactResult['expected_net']);
        $this->assertSame($fullResult['gross_loot'], $compactResult['gross_loot']);
        $this->assertSame($fullResult['scenarios'], $compactResult['scenarios']);
        $this->assertSame($fullResult['confidence'], $compactResult['confidence']);
    }

    public function test_historical_lookup_uses_the_interval_start_and_falls_back_to_prior_clean_state(): void
    {
        $start = CarbonImmutable::parse('2026-09-19 10:00:00', 'UTC');
        RaidNationObservation::factory()->create([
            'nation_id' => 50,
            'observed_at' => $start->addHour(),
            'valid_from' => $start,
            'confirmed_through' => $start->addHour(),
            'payload' => ['id' => 50, 'score' => 1000],
        ]);
        RaidNationObservation::factory()->create([
            'nation_id' => 50,
            'current_key' => 1,
            'observed_at' => $start->addHours(3),
            'valid_from' => $start->addHours(2),
            'confirmed_through' => $start->addHours(3),
            'provenance_war_ids' => [777],
            'payload' => ['id' => 50, 'score' => 1100],
        ]);
        $service = app(RaidIntelligenceService::class);

        $this->assertSame(1000, $service->nationAt(50, $start->addMinutes(90))['score']);
        $this->assertSame(1100, $service->nationAt(50, $start->addHours(4))['score']);
        $this->assertSame(1000, $service->nationAt(50, $start->addHours(4), 777)['score']);
    }

    public function test_compaction_command_merges_legacy_duplicates_and_discards_irrelevant_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC'));
        RaidAttackObservation::factory()->create(['att_id' => 60, 'def_id' => 600, 'occurred_at' => now()->subDay()]);
        foreach ([
            ['nation_id' => 60, 'observed_at' => now()->subHours(3), 'payload' => ['id' => 60, 'nation_name' => 'A', 'score' => 1000, 'cities' => []]],
            ['nation_id' => 60, 'observed_at' => now()->subHours(2), 'payload' => ['id' => 60, 'nation_name' => 'B', 'score' => 1000, 'cities' => []]],
            ['nation_id' => 60, 'observed_at' => now()->subHour(), 'current_key' => 1, 'payload' => ['id' => 60, 'nation_name' => 'B', 'score' => 1100, 'cities' => []]],
            ['nation_id' => 70, 'observed_at' => now()->subHours(2), 'payload' => ['id' => 70, 'score' => 1000, 'cities' => []]],
            ['nation_id' => 70, 'observed_at' => now()->subHour(), 'current_key' => 1, 'payload' => ['id' => 70, 'score' => 1100, 'cities' => []]],
        ] as $attributes) {
            RaidNationObservation::factory()->create($attributes);
        }

        $this->artisan('raids:compact-intelligence', ['--batch' => 1])->assertSuccessful();

        $relevant = RaidNationObservation::query()->where('nation_id', 60)->orderBy('valid_from')->get();
        $this->assertCount(2, $relevant);
        $this->assertSame('B', $relevant[0]->payload['nation_name']);
        $this->assertNull($relevant[0]->current_key);
        $this->assertSame(1, $relevant[1]->current_key);
        $this->assertCount(1, RaidNationObservation::query()->where('nation_id', 70)->get());
        $this->assertSame(1, RaidNationObservation::query()->where('nation_id', 70)->value('current_key'));
        $this->assertSame(0, RaidNationObservation::query()->whereNotNull('payload->cities')->count());
    }

    public function test_nullable_unique_key_prevents_duplicate_current_rows_but_allows_history(): void
    {
        RaidNationObservation::factory()->create(['nation_id' => 80, 'current_key' => 1]);
        RaidNationObservation::factory()->count(2)->create(['nation_id' => 80, 'current_key' => null]);

        $this->expectException(QueryException::class);
        RaidNationObservation::factory()->create(['nation_id' => 80, 'current_key' => 1]);
    }

    /**
     * @param  list<array<string, mixed>>  $nations
     * @param  list<list<array<string, mixed>>>|null  $warResponses
     */
    private function mockRefreshes(array $nations, ?array $warResponses = null): void
    {
        config(['raids.history_pages' => 1, 'raids.history_page_size' => 100]);
        $nationIndex = 0;
        $warIndex = 0;
        $warResponses ??= array_fill(0, count($nations), []);
        $queries = $this->mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->times(count($nations) * 2)
            ->andReturnUsing(function ($query, ...$arguments) use (&$nationIndex, &$warIndex, $nations, $warResponses): object {
                if ($query->getRootField() === 'wars') {
                    return (object) array_map(static fn (array $war): object => (object) $war, $warResponses[$warIndex++] ?? []);
                }

                return (object) [(object) $nations[$nationIndex++]];
            });
    }

    /** @return array<string, mixed> */
    private function nationPayload(): array
    {
        return [
            'id' => 90,
            'alliance_id' => 9,
            'alliance' => ['id' => 9, 'name' => 'Alliance', 'score' => 50000.5],
            'score' => 1500.5,
            'last_active' => now()->subHours(2)->toIso8601String(),
            'color' => 'gray',
            'beige_turns' => 0,
            'vacation_mode_turns' => 0,
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
            'active_wars' => [],
            'bounties' => [],
            'history_complete' => true,
            'soldiers' => 50000,
            'tanks' => 2500,
            'aircraft' => 1500,
            'ships' => 100,
            'missiles' => 5,
            'nukes' => 2,
            'war_policy' => 'PIRATE',
            'is_fortified' => false,
            'military_research' => ['ground_capacity' => 1.5, 'air_capacity' => 2.5],
            'projects' => [],
            'pirate_economy' => true,
            'advanced_pirate_economy' => false,
            'num_cities' => 2,
            'cities' => [
                ['id' => 1, 'infrastructure' => 1000.0, 'population' => 100000],
                ['id' => 2, 'infrastructure' => 1500.5, 'population' => 125000],
            ],
            'daily_output' => array_fill_keys(EconomyRules::RESOURCE_KEYS, 10.0),
            'daily_expenses' => array_fill_keys(EconomyRules::RESOURCE_KEYS, 2.0),
            'daily_net' => array_fill_keys(EconomyRules::RESOURCE_KEYS, 8.0),
            'production_processes' => [],
        ];
    }
}
