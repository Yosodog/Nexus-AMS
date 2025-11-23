<?php

namespace Tests\Unit\Audit;

use App\Models\City;
use App\Models\Nation;
use App\Services\Audit\CityAuditMapper;
use Carbon\Carbon;
use Tests\TestCase;

class CityAuditMapperTest extends TestCase
{
    public function test_builds_city_and_nation_variables(): void
    {
        $nation = new Nation([
            'id' => 1,
            'nation_name' => 'Test Nation',
            'leader_name' => 'Tester',
            'score' => 500,
            'num_cities' => 3,
            'color' => 'blue',
        ]);

        $city = new City([
            'id' => 10,
            'nation_id' => 1,
            'name' => 'Capital',
            'date' => Carbon::now(),
            'infrastructure' => 500,
            'land' => 750,
            'powered' => true,
            'oil_power' => 1,
            'wind_power' => 0,
            'coal_power' => 0,
            'nuclear_power' => 0,
            'coal_mine' => 2,
            'oil_well' => 1,
            'uranium_mine' => 0,
            'barracks' => 3,
            'farm' => 5,
            'police_station' => 1,
            'hospital' => 1,
            'recycling_center' => 0,
            'subway' => 0,
            'supermarket' => 0,
            'bank' => 0,
            'shopping_mall' => 0,
            'stadium' => 0,
            'lead_mine' => 0,
            'iron_mine' => 0,
            'bauxite_mine' => 0,
            'oil_refinery' => 0,
            'aluminum_refinery' => 0,
            'steel_mill' => 0,
            'munitions_factory' => 0,
            'factory' => 0,
            'hangar' => 0,
            'drydock' => 0,
        ]);

        $city->setRelation('nation', $nation);

        $mapper = new CityAuditMapper;
        $variables = $mapper->buildVariables($city);

        $this->assertArrayHasKey('city', $variables);
        $this->assertEquals(500, $variables['city']['infrastructure']);
        $this->assertTrue($variables['city']['powered']);
        $this->assertSame('Tester', $variables['nation']['leader_name']);
        $this->assertSame(3, $variables['nation']['num_cities']);
    }
}
