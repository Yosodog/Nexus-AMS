<?php

namespace Tests\Unit\DataTransferObjects\WarSim;

use App\DataTransferObjects\WarSim\WarSimActionData;
use App\DataTransferObjects\WarSim\WarSimContextData;
use App\DataTransferObjects\WarSim\WarSimNationData;
use App\DataTransferObjects\WarSim\WarSimRequestData;
use Tests\UnitTestCase;

class WarSimDataTransferObjectTest extends UnitTestCase
{
    public function test_request_from_array_applies_supported_defaults(): void
    {
        $request = WarSimRequestData::fromArray([]);

        $this->assertSame(5000, $request->iterations);
        $this->assertNull($request->seed);
        $this->assertSame(0, $request->nationAttacker->soldiers);
        $this->assertSame(0, $request->nationDefender->tanks);
        $this->assertSame('ORDINARY', $request->context->warType);
        $this->assertSame('ground', $request->action->type);
        $this->assertSame('infra', $request->action->target);
    }

    public function test_request_from_array_casts_nested_payload_values(): void
    {
        $request = WarSimRequestData::fromArray([
            'iterations' => '750',
            'seed' => '12345',
            'nation_attacker' => [
                'nation_id' => '101',
                'soldiers' => '50000',
                'tanks' => '1200',
                'aircraft' => '900',
                'ships' => '35',
                'war_policy' => 'PIRATE',
                'is_fortified' => 1,
                'money' => '12345.67',
                'cities' => '20',
                'highest_city_infra' => '2500.5',
                'highest_city_population' => '150000',
                'avg_infra' => '2100.75',
            ],
            'nation_defender' => [
                'soldiers' => '40000',
            ],
            'context' => [
                'war_type' => 'RAID',
                'attacker_policy' => 'PIRATE',
                'defender_policy' => 'MONEYBAGS',
                'air_superiority_owner' => 'attacker',
                'ground_control_owner' => 'defender',
                'blockade_owner' => 'none',
                'blitz_active_attacker' => 1,
                'blitz_active_defender' => 0,
            ],
            'action' => [
                'type' => 'air',
                'attacking_soldiers' => '1000',
                'attacking_tanks' => '50',
                'arm_soldiers_with_munitions' => 1,
                'attacking_aircraft' => '300',
                'target' => 'soldiers',
                'attacking_ships' => '12',
            ],
        ]);

        $this->assertSame(750, $request->iterations);
        $this->assertSame(12345, $request->seed);
        $this->assertSame(101, $request->nationAttacker->nationId);
        $this->assertSame(50000, $request->nationAttacker->soldiers);
        $this->assertTrue($request->nationAttacker->isFortified);
        $this->assertSame(12345.67, $request->nationAttacker->money);
        $this->assertSame(2500.5, $request->nationAttacker->highestCityInfra);
        $this->assertSame(2100.75, $request->nationAttacker->avgInfra);
        $this->assertSame(40000, $request->nationDefender->soldiers);
        $this->assertSame('RAID', $request->context->warType);
        $this->assertTrue($request->context->blitzActiveAttacker);
        $this->assertFalse($request->context->blitzActiveDefender);
        $this->assertSame('air', $request->action->type);
        $this->assertSame(300, $request->action->attackingAircraft);
        $this->assertTrue($request->action->armSoldiersWithMunitions);
    }

    public function test_nested_war_sim_dto_to_array_preserves_payload_keys(): void
    {
        $nation = new WarSimNationData(
            nationId: 44,
            soldiers: 1000,
            tanks: 50,
            aircraft: 25,
            ships: 5,
            warPolicy: 'TACTICIAN',
            isFortified: true,
            money: 100000.5,
            cities: 12,
            highestCityInfra: 1999.9,
            highestCityPopulation: 125000,
            avgInfra: 1500.25,
        );
        $context = new WarSimContextData(
            warType: 'ATTRITION',
            attackerPolicy: 'ATTRITION',
            defenderPolicy: 'TURTLE',
            airSuperiorityOwner: 'attacker',
            groundControlOwner: 'none',
            blockadeOwner: 'defender',
            blitzActiveAttacker: true,
            blitzActiveDefender: false,
        );
        $action = new WarSimActionData(
            type: 'naval',
            attackingSoldiers: 0,
            attackingTanks: 0,
            armSoldiersWithMunitions: false,
            attackingAircraft: 0,
            target: 'infra',
            attackingShips: 15,
        );

        $this->assertSame([
            'nation_id' => 44,
            'soldiers' => 1000,
            'tanks' => 50,
            'aircraft' => 25,
            'ships' => 5,
            'war_policy' => 'TACTICIAN',
            'is_fortified' => true,
            'money' => 100000.5,
            'cities' => 12,
            'highest_city_infra' => 1999.9,
            'highest_city_population' => 125000,
            'avg_infra' => 1500.25,
        ], $nation->toArray());
        $this->assertSame([
            'war_type' => 'ATTRITION',
            'attacker_policy' => 'ATTRITION',
            'defender_policy' => 'TURTLE',
            'air_superiority_owner' => 'attacker',
            'ground_control_owner' => 'none',
            'blockade_owner' => 'defender',
            'blitz_active_attacker' => true,
            'blitz_active_defender' => false,
        ], $context->toArray());
        $this->assertSame([
            'type' => 'naval',
            'attacking_soldiers' => 0,
            'attacking_tanks' => 0,
            'arm_soldiers_with_munitions' => false,
            'attacking_aircraft' => 0,
            'target' => 'infra',
            'attacking_ships' => 15,
        ], $action->toArray());
    }
}
