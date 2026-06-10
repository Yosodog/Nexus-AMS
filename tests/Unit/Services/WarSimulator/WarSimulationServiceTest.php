<?php

namespace Tests\Unit\Services\WarSimulator;

use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Models\TradePrice;
use App\Services\TradePriceService;
use App\Services\WarSimulator\Simulators\AirstrikeSimulator;
use App\Services\WarSimulator\Simulators\GroundAttackSimulator;
use App\Services\WarSimulator\Simulators\NavalAttackSimulator;
use App\Services\WarSimulator\WarSimulationService;
use Mockery;
use Tests\UnitTestCase;

class WarSimulationServiceTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_run_clamps_low_iterations_and_reports_ground_consumables(): void
    {
        $result = $this->service()->run(WarSimRequestData::fromArray([
            'iterations' => 5,
            'seed' => 12345,
            'nation_attacker' => $this->nationPayload(),
            'nation_defender' => $this->nationPayload([
                'soldiers' => 60000,
                'tanks' => 800,
                'aircraft' => 400,
                'ships' => 20,
                'money' => 5000000,
            ]),
            'context' => [
                'war_type' => 'RAID',
                'attacker_policy' => 'PIRATE',
                'defender_policy' => 'MONEYBAGS',
                'air_superiority_owner' => 'none',
                'ground_control_owner' => 'none',
                'blockade_owner' => 'none',
            ],
            'action' => [
                'type' => 'ground',
                'attacking_soldiers' => 50000,
                'attacking_tanks' => 1000,
                'arm_soldiers_with_munitions' => true,
            ],
        ]));

        $this->assertSame(100, $result['meta']['iterations']);
        $this->assertEqualsWithDelta(100.0, array_sum($result['outcomes']['probabilities']), 0.0001);
        $this->assertSame(10.0, $result['metrics']['resources_consumed_attacker']['gasoline']['p50']);
        $this->assertSame(20.0, $result['metrics']['resources_consumed_attacker']['munitions']['p50']);
        $this->assertSame(8.0, $result['metrics']['resources_consumed_defender']['gasoline']['p50']);
        $this->assertSame(20.0, $result['metrics']['resources_consumed_defender']['munitions']['p50']);
        $this->assertNotNull($result['metrics']['money_looted']);
        $this->assertNotNull($result['metrics']['improvement_destroy_chance']);
        $this->assertNull($result['metrics']['money_destroyed']);
    }

    public function test_run_clamps_high_iterations_and_handles_empty_ground_attacks(): void
    {
        $result = $this->service()->run(WarSimRequestData::fromArray([
            'iterations' => 25000,
            'seed' => 777,
            'nation_attacker' => $this->nationPayload(),
            'nation_defender' => $this->nationPayload(),
            'action' => [
                'type' => 'ground',
                'attacking_soldiers' => 0,
                'attacking_tanks' => 0,
                'arm_soldiers_with_munitions' => true,
            ],
        ]));

        $this->assertSame(20000, $result['meta']['iterations']);
        $this->assertSame(100.0, $result['outcomes']['probabilities']['UF']);
        $this->assertSame(0.0, $result['metrics']['attacker_losses']['soldiers']['mean']);
        $this->assertSame(0.0, $result['metrics']['defender_losses']['tanks']['mean']);
        $this->assertSame(0.0, $result['metrics']['infra_destroyed']['mean']);
    }

    private function service(): WarSimulationService
    {
        $tradePriceService = Mockery::mock(TradePriceService::class);
        $tradePriceService
            ->shouldReceive('get24hAverage')
            ->andReturn($this->tradePrices());

        return new WarSimulationService(
            $tradePriceService,
            new GroundAttackSimulator,
            new AirstrikeSimulator,
            new NavalAttackSimulator,
        );
    }

    private function tradePrices(): TradePrice
    {
        return new TradePrice([
            'gasoline' => 100,
            'munitions' => 200,
            'steel' => 300,
            'aluminum' => 400,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function nationPayload(array $overrides = []): array
    {
        return [
            'nation_id' => 123,
            'soldiers' => 50000,
            'tanks' => 1000,
            'aircraft' => 500,
            'ships' => 30,
            'war_policy' => 'NONE',
            'is_fortified' => false,
            'money' => 10000000,
            'cities' => 20,
            'highest_city_infra' => 2500,
            'highest_city_population' => 150000,
            'avg_infra' => 2000,
            ...$overrides,
        ];
    }
}
