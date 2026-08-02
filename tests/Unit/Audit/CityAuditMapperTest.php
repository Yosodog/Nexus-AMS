<?php

namespace Tests\Unit\Audit;

use App\Models\City;
use App\Models\Nation;
use App\Services\Audit\CityAuditMapper;
use App\Services\PWHelperService;
use Carbon\Carbon;
use Tests\TestCase;

class CityAuditMapperTest extends TestCase
{
    public function test_builds_flat_city_context_with_capacity_alignment_and_projects(): void
    {
        $nation = new Nation([
            'id' => 1,
            'nation_name' => 'Test Nation',
            'leader_name' => 'Tester',
            'score' => 500,
            'num_cities' => 3,
            'color' => 'blue',
            'project_bits' => (string) PWHelperService::PROJECTS['Urban Planning'],
        ]);
        $nation->syncOriginal();

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
        $city->syncOriginal();

        $city->setRelation('nation', $nation);

        $mapper = new CityAuditMapper;
        $context = $mapper->buildContext($city);

        $this->assertArrayNotHasKey('city', $context);
        $this->assertSame(10, $context['city.id']);
        $this->assertSame(500.0, $context['city.infrastructure']);
        $this->assertSame(750.0, $context['city.land']);
        $this->assertTrue($context['city.powered']);
        $this->assertSame(14, $context['city.improvement_count']);
        $this->assertSame(10, $context['city.improvement_capacity']);
        $this->assertTrue($context['city.improvement_capacity_exceeded']);
        $this->assertTrue($context['city.infrastructure_aligned']);
        $this->assertTrue($context['city.land_aligned']);
        $this->assertTrue($context['city.infrastructure_and_land_aligned']);
        $this->assertTrue($context['city.land_at_least_infrastructure']);
        $this->assertSame(3, $context['city.barracks']);
        $this->assertSame('Tester', $context['nation.leader_name']);
        $this->assertSame(3, $context['nation.num_cities']);
        $this->assertContains('Urban Planning', $context['nation.projects']);
    }

    public function test_incomplete_improvement_data_stays_missing_and_alignment_is_typed(): void
    {
        $nation = new Nation(['id' => 1, 'project_bits' => null]);
        $nation->syncOriginal();
        $city = new City([
            'id' => 11,
            'nation_id' => 1,
            'infrastructure' => 510,
            'land' => 760,
            'powered' => false,
        ]);
        $city->syncOriginal();
        $city->setRelation('nation', $nation);

        $context = (new CityAuditMapper)->buildContext($city);

        $this->assertNull($context['city.improvement_count']);
        $this->assertSame(10, $context['city.improvement_capacity']);
        $this->assertNull($context['city.improvement_capacity_exceeded']);
        $this->assertFalse($context['city.infrastructure_aligned']);
        $this->assertFalse($context['city.land_aligned']);
        $this->assertFalse($context['city.infrastructure_and_land_aligned']);
        $this->assertTrue($context['city.land_at_least_infrastructure']);
        $this->assertFalse($context['city.powered']);
        $this->assertNull($context['nation.projects']);
    }
}
