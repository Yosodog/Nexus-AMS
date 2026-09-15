<?php

namespace Tests\Unit\Services;

use App\Services\Calculators\GamePurchaseCostCalculator;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\Calculators\ProjectCostCatalog;
use App\Services\RaidSimulationService;
use App\Services\TradePriceService;
use App\Services\WarSimulator\Simulators\AirstrikeSimulator;
use App\Services\WarSimulator\Simulators\GroundAttackSimulator;
use App\Services\WarSimulator\Simulators\NavalAttackSimulator;
use App\Services\WarSimulator\Support\RaidLootFormula;
use App\Services\WarSimulator\WarSimulationService;
use Mockery;
use Tests\UnitTestCase;

class RaidSimulationServiceTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_expected_bank_fraction_integrates_the_clipped_distribution(): void
    {
        $this->assertEqualsWithDelta(
            1 / 18,
            RaidLootFormula::expectedBankLootFraction(10_000, 30_000),
            0.0000001,
        );
        $this->assertEqualsWithDelta(
            0.33 - ((0.33 ** 2) / 2),
            RaidLootFormula::expectedBankLootFraction(90_000, 30_000),
            0.0000001,
        );
        $this->assertNull(RaidLootFormula::expectedBankLootFraction(10_000, 0));
    }

    public function test_evaluation_is_seeded_and_returns_the_complete_plan_contract(): void
    {
        $service = $this->service();
        $input = [
            'attacker' => [
                'nation_id' => 1,
                'score' => 10_000,
                'soldiers' => 100_000,
                'tanks' => 2_000,
                'aircraft' => 500,
                'ships' => 50,
                'cities' => 20,
                'highest_city_infra' => 2_500,
                'highest_city_population' => 150_000,
            ],
            'target' => [
                'nation_id' => 2,
                'score' => 9_000,
                'alliance' => ['id' => 7, 'score' => 30_000],
                'soldiers' => 5_000,
                'tanks' => 100,
                'aircraft' => 20,
                'ships' => 5,
                'cities' => 10,
                'highest_city_infra' => 1_500,
                'highest_city_population' => 100_000,
            ],
            'stockpile' => [
                'resources' => [
                    'money' => 20_000_000,
                    'coal' => 10,
                    'oil' => 10,
                    'uranium' => 10,
                    'iron' => 10,
                    'bauxite' => 10,
                    'lead' => 10,
                    'gasoline' => 10,
                    'munitions' => 10,
                    'steel' => 10,
                    'aluminum' => 10,
                    'food' => 10,
                ],
                'bank_resources' => ['money' => 2_000_000],
                'confidence' => 'high',
            ],
            'prices' => [
                'acquisition' => [
                    'gasoline' => 100,
                    'munitions' => 200,
                    'steel' => 300,
                    'aluminum' => 400,
                ],
                'liquidation' => [
                    'coal' => 10,
                    'oil' => 20,
                    'uranium' => 30,
                    'iron' => 40,
                    'bauxite' => 50,
                    'lead' => 60,
                    'gasoline' => 100,
                    'munitions' => 200,
                    'steel' => 300,
                    'aluminum' => 400,
                    'food' => 10,
                ],
            ],
            'context' => [
                'war_type' => 'RAID',
                'iterations' => 16,
                'seed' => 42,
                'attacker_map' => 3,
                'resistance' => 3,
                'bounties' => [],
            ],
        ];

        $first = $service->evaluate(...array_values($input));
        $second = $service->evaluate(...array_values($input));

        $this->assertSame($first, $second);
        $this->assertSame('raid-simulation-v2', $first['model_version']);
        $this->assertContains($first['status'], ['ready', 'degraded']);
        $this->assertArrayHasKey('expected_net', $first);
        $this->assertArrayHasKey('gross_loot', $first);
        $this->assertArrayHasKey('conservative_net', $first);
        $this->assertArrayHasKey('slot_efficiency', $first);
        $this->assertArrayHasKey('duration_hours', $first);
        $this->assertArrayHasKey('win_probability', $first);
        $this->assertArrayHasKey('approach', $first);
        $this->assertNotEmpty($first['approaches']);
        $this->assertArrayHasKey('ground_loot', $first['components']);
        $this->assertArrayHasKey('nation_loot', $first['components']);
        $this->assertArrayHasKey('bank_loot', $first['components']);
        $this->assertArrayHasKey('military_suitability', $first);
    }

    public function test_bank_loot_stays_unknown_without_scores_or_a_verified_fraction(): void
    {
        $result = $this->service()->evaluate(
            [
                'soldiers' => 100_000,
                'tanks' => 2_000,
                'aircraft' => 0,
                'ships' => 0,
                'cities' => 20,
                'highest_city_infra' => 2_500,
                'highest_city_population' => 150_000,
            ],
            [
                'soldiers' => 5_000,
                'tanks' => 100,
                'aircraft' => 0,
                'ships' => 0,
                'cities' => 10,
                'highest_city_infra' => 1_500,
                'highest_city_population' => 100_000,
            ],
            [
                'resources' => ['money' => 20_000_000],
                'bank_resources' => ['money' => 2_000_000],
                'confidence' => 'medium',
            ],
            ['liquidation' => [], 'acquisition' => ['gasoline' => 100, 'munitions' => 200, 'steel' => 300]],
            ['iterations' => 16, 'seed' => 7, 'resistance' => 3, 'bounties' => []],
        );

        $this->assertSame('degraded', $result['status']);
        $this->assertNull($result['components']['bank_loot']);
        $this->assertSame('lower_bound', $result['valuation']['basis']);
        $this->assertContains('bank_loot', $result['valuation']['unknown_components']);
        $this->assertNotEmpty($result['assumptions']);
        $this->assertTrue(collect($result['assumptions'])->contains(fn (string $assumption): bool => str_contains($assumption, 'Alliance-bank holdings')));
    }

    public function test_no_ground_force_returns_an_unavailable_result(): void
    {
        $result = $this->service()->evaluate(
            ['soldiers' => 0, 'tanks' => 0, 'aircraft' => 100, 'ships' => 0],
            ['soldiers' => 1_000, 'tanks' => 0, 'aircraft' => 0, 'ships' => 0],
            ['resources' => ['money' => 1_000_000]],
            ['acquisition' => [], 'liquidation' => []],
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['expected_net']);
        $this->assertNull($result['approach']);
    }

    public function test_missing_military_observations_are_not_treated_as_zero(): void
    {
        $attacker = $this->completeNation(['soldiers' => 10_000, 'tanks' => 100]);
        unset($attacker['ships']);

        $result = $this->service()->evaluate(
            $attacker,
            $this->completeNation(['soldiers' => 5_000, 'tanks' => 50]),
            ['resources' => ['money' => 1_000_000], 'bounties' => []],
            $this->completePrices(),
            ['iterations' => 16, 'bounties' => []],
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['expected_net']);
        $this->assertTrue(collect($result['assumptions'])->contains(
            fn (string $assumption): bool => str_contains($assumption, 'Required military observations'),
        ));
    }

    public function test_supplied_consumable_budget_blocks_an_unaffordable_plan(): void
    {
        $result = $this->service()->evaluate(
            $this->completeNation([
                'soldiers' => 10_000,
                'tanks' => 100,
                'resources' => array_fill_keys(['money', 'gasoline', 'munitions'], 0),
            ]),
            $this->completeNation(['soldiers' => 5_000, 'tanks' => 50]),
            ['resources' => $this->completeStockpile(), 'bounties' => []],
            $this->completePrices(),
            ['iterations' => 16, 'bounties' => []],
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['expected_net']);
        $this->assertTrue(collect($result['assumptions'])->contains(
            fn (string $assumption): bool => str_contains($assumption, 'insufficient or unknown'),
        ));
    }

    public function test_unfinished_plan_uses_the_verified_war_expiration_for_slot_time(): void
    {
        $result = $this->service()->evaluate(
            $this->completeNation(['soldiers' => 10_000, 'tanks' => 100, 'aircraft' => 100, 'ships' => 20]),
            $this->completeNation(['soldiers' => 5_000, 'tanks' => 50, 'aircraft' => 20, 'ships' => 5]),
            ['resources' => $this->completeStockpile(), 'bounties' => []],
            $this->completePrices(),
            ['iterations' => 16, 'attacker_map' => 3, 'resistance' => 99, 'bounties' => [], 'action_map_costs' => ['ground' => 12, 'air' => 12, 'naval' => 12]],
        );

        $this->assertSame(120.0, (float) $result['duration_hours']);
        $this->assertArrayHasKey('counter_risk', $result);
        $this->assertSame('heuristic', $result['counter_risk']['basis']);
    }

    public function test_full_resistance_plan_runs_bounded_multi_attack_sequence_with_controls_and_costs(): void
    {
        $attacker = $this->completeNation([
            'soldiers' => 100_000,
            'tanks' => 2_000,
            'aircraft' => 500,
            'ships' => 50,
        ]);
        $target = $this->completeNation([
            'soldiers' => 0,
            'tanks' => 0,
            'aircraft' => 20,
            'ships' => 5,
            'highest_city_population' => 0,
        ]);
        $stockpile = ['resources' => array_replace(
            $this->completeStockpile(),
            array_fill_keys([
                'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline',
                'munitions', 'steel', 'aluminum', 'food',
            ], 0.0),
        ), 'bounties' => []];
        $context = [
            'iterations' => 100,
            'seed' => 901,
            'attacker_map' => 3,
            'defender_return_probability' => 0,
            'competition_probability' => 0,
            'competition_depletion_fraction' => 0,
            'bounties' => [],
        ];

        $first = $this->service()->evaluate($attacker, $target, $stockpile, $this->completePrices(), $context);
        $second = $this->service()->evaluate($attacker, $target, $stockpile, $this->completePrices(), $context);
        $ground = collect($first['approaches'])->firstWhere('key', 'ground_focused');

        $this->assertSame($first, $second);
        $this->assertNotNull($ground);
        $this->assertSame(10.0, (float) $ground['mechanics']['actions_executed']);
        $this->assertSame(54.0, (float) $ground['duration_hours']);
        $this->assertGreaterThanOrEqual(0.0, (float) $ground['mechanics']['map_remaining']);
        $this->assertLessThanOrEqual(12.0, (float) $ground['mechanics']['map_remaining']);
        $this->assertGreaterThanOrEqual(0.99, (float) $ground['mechanics']['controls']['ground_control']['attacker']);
        $this->assertGreaterThanOrEqual(0.0, (float) ($first['components']['infrastructure_losses'] ?? 0.0));
        foreach ($first['cost_resources'] as $amount) {
            $this->assertGreaterThanOrEqual(0.0, (float) $amount);
        }
    }

    public function test_guaranteed_victory_does_not_double_count_ground_cash_before_nation_loot(): void
    {
        $initialMoney = 20_000_000.0;
        $resources = array_fill_keys([
            'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead',
            'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        ], 0.0);
        $resources['money'] = $initialMoney;
        $context = [
            'iterations' => 100,
            'seed' => 902,
            'resistance' => 3,
            'attacker_map' => 3,
            'defender_return_probability' => 0,
            'competition_probability' => 0,
            'competition_depletion_fraction' => 0,
            'bounties' => [],
        ];

        $result = $this->service()->evaluate(
            $this->completeNation(['soldiers' => 100_000, 'tanks' => 2_000, 'aircraft' => 0, 'ships' => 0]),
            $this->completeNation(['soldiers' => 0, 'tanks' => 0, 'aircraft' => 0, 'ships' => 0, 'highest_city_population' => 0]),
            ['resources' => $resources, 'bounties' => []],
            $this->completePrices(),
            $context,
        );

        $groundLoot = (float) $result['components']['ground_loot'];
        $nationLoot = (float) $result['components']['nation_loot'];

        $this->assertSame(1.0, (float) $result['approach']['mechanics']['controls']['ground_control']['attacker']);
        $this->assertSame(0.0, (float) $result['approach']['mechanics']['map_remaining']);
        $this->assertSame(0.0, (float) $result['duration_hours']);
        $this->assertSame(1.0, (float) $result['approach']['mechanics']['actions_executed']);
        $this->assertEqualsWithDelta(($initialMoney - $groundLoot) * RaidLootFormula::DEFAULT_VICTORY_LOOT_FRACTION, $nationLoot, 0.01);
        $this->assertEqualsWithDelta($groundLoot + $nationLoot, (float) $result['loot_resources']['money'], 0.01);
        $this->assertGreaterThanOrEqual(0.0, (float) ($result['components']['infrastructure_losses'] ?? 0.0));
        $this->assertNotNull($result['expected_net']);
    }

    public function test_signed_daily_net_applies_consumption_to_the_projected_victory_balance(): void
    {
        $initialMoney = 20_000_000.0;
        $resources = array_fill_keys([
            'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead',
            'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        ], 0.0);
        $resources['money'] = $initialMoney;

        $result = $this->service()->evaluate(
            $this->completeNation(['soldiers' => 100_000, 'tanks' => 2_000, 'aircraft' => 0, 'ships' => 0]),
            $this->completeNation(['soldiers' => 0, 'tanks' => 0, 'aircraft' => 0, 'ships' => 0, 'highest_city_population' => 0]),
            ['resources' => $resources, 'bounties' => []],
            $this->completePrices(),
            [
                'iterations' => 100,
                'seed' => 903,
                'resistance' => 3,
                'attacker_map' => 3,
                'hours_since_observation' => 24,
                'production_per_day' => ['money' => -1_000_000],
                'defender_return_probability' => 0,
                'competition_probability' => 0,
                'competition_depletion_fraction' => 0,
                'bounties' => [],
            ],
        );

        $groundLoot = (float) $result['components']['ground_loot'];
        $nationLoot = (float) $result['components']['nation_loot'];

        $this->assertEqualsWithDelta(($initialMoney - 1_000_000 - $groundLoot) * RaidLootFormula::DEFAULT_VICTORY_LOOT_FRACTION, $nationLoot, 0.01);
    }

    public function test_unknown_stockpile_balance_is_not_made_known_by_projection(): void
    {
        $resources = array_fill_keys([
            'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead',
            'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        ], 0.0);
        $resources['money'] = 20_000_000.0;
        $resources['coal'] = null;

        $result = $this->service()->evaluate(
            $this->completeNation(['soldiers' => 100_000, 'tanks' => 2_000, 'aircraft' => 0, 'ships' => 0]),
            $this->completeNation(['soldiers' => 0, 'tanks' => 0, 'aircraft' => 0, 'ships' => 0, 'highest_city_population' => 0]),
            ['resources' => $resources, 'bounties' => []],
            $this->completePrices(),
            [
                'iterations' => 16,
                'seed' => 905,
                'resistance' => 3,
                'hours_since_observation' => 24,
                'production_per_day' => ['coal' => 1_000_000],
                'defender_return_probability' => 0,
                'competition_probability' => 0,
                'competition_depletion_fraction' => 0,
                'bounties' => [],
            ],
        );

        $this->assertNull($result['components']['nation_loot']);
        $this->assertArrayNotHasKey('coal', $result['loot_resources']);
        $this->assertSame('lower_bound', $result['valuation']['basis']);
        $this->assertContains('nation_loot', $result['valuation']['unknown_components']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function completeNation(array $overrides = []): array
    {
        return array_replace([
            'soldiers' => 10_000,
            'tanks' => 100,
            'aircraft' => 100,
            'ships' => 20,
            'cities' => 10,
            'highest_city_infra' => 1_000,
            'highest_city_population' => 100_000,
        ], $overrides);
    }

    /** @return array<string, float> */
    private function completeStockpile(): array
    {
        return array_fill_keys([
            'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead',
            'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        ], 1_000.0);
    }

    /** @return array<string, array<string, float>> */
    private function completePrices(): array
    {
        return [
            'acquisition' => array_fill_keys([
                'gasoline', 'munitions', 'steel', 'aluminum', 'coal', 'oil',
                'uranium', 'iron', 'bauxite', 'lead', 'food',
            ], 100.0),
            'liquidation' => array_fill_keys([
                'gasoline', 'munitions', 'steel', 'aluminum', 'coal', 'oil',
                'uranium', 'iron', 'bauxite', 'lead', 'food',
            ], 100.0),
        ];
    }

    private function service(): RaidSimulationService
    {
        $tradePriceService = Mockery::mock(TradePriceService::class);

        return new RaidSimulationService(
            new WarSimulationService(
                $tradePriceService,
                new GroundAttackSimulator,
                new AirstrikeSimulator,
                new NavalAttackSimulator,
            ),
            new MilitaryCostCalculator,
            new GamePurchaseCostCalculator(new ProjectCostCatalog),
        );
    }
}
