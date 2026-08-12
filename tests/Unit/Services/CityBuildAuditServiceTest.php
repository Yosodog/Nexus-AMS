<?php

namespace Tests\Unit\Services;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Services\CityBuildAuditService;
use App\Services\Economy\EconomyRules;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CityBuildAuditServiceTest extends TestCase
{
    public function test_it_compares_each_city_with_the_current_recommended_build(): void
    {
        $recommendation = new NationBuildRecommendation([
            'model_version' => EconomyRules::MODEL_VERSION,
            'recommended_build_json' => [
                'infra_needed' => 2000,
                'imp_nuclearpower' => 1,
                'imp_barracks' => 5,
            ],
            'infra_needed' => 2000,
            'land_used' => 1500,
        ]);
        $matchingCity = $this->city([
            'id' => 10,
            'nuclear_power' => 1,
            'barracks' => 5,
        ]);
        $mismatchedCity = $this->city([
            'id' => 11,
            'infrastructure' => 1900,
            'land' => 1400,
            'powered' => false,
            'nuclear_power' => 0,
            'barracks' => 4,
            'farm' => 1,
        ]);
        $nation = new Nation(['id' => 253987]);
        $nation->setRelation('buildRecommendation', $recommendation);
        $nation->setRelation('cities', new Collection([$matchingCity, $mismatchedCity]));

        $audit = (new CityBuildAuditService)->auditNation($nation);

        $this->assertSame('needs_changes', $audit['status']);
        $this->assertSame(2, $audit['city_count']);
        $this->assertSame(1, $audit['matching_city_count']);
        $this->assertSame(1, $audit['cities_needing_changes']);
        $this->assertSame(6, $audit['total_changes']);
        $this->assertTrue($audit['has_different_city_builds']);
        $this->assertSame(1, $audit['different_city_build_count']);
        $this->assertTrue($audit['first_city']['matches']);
        $this->assertSame(10, $audit['first_city']['id']);
        $this->assertArrayNotHasKey('cities', $audit);
    }

    public function test_it_marks_a_nation_without_a_recommendation_as_missing(): void
    {
        $nation = new Nation(['id' => 253987]);
        $nation->setRelation('buildRecommendation', null);
        $nation->setRelation('cities', new Collection([$this->city()]));

        $audit = (new CityBuildAuditService)->auditNation($nation);

        $this->assertSame('missing', $audit['status']);
        $this->assertSame(1, $audit['city_count']);
        $this->assertNull($audit['recommendation_json']);
        $this->assertFalse($audit['has_different_city_builds']);
        $this->assertNull($audit['first_city']);
    }

    public function test_it_does_not_mark_identical_improvement_builds_as_different(): void
    {
        $recommendation = new NationBuildRecommendation([
            'model_version' => EconomyRules::MODEL_VERSION,
            'recommended_build_json' => ['imp_nuclearpower' => 1],
            'infra_needed' => 2000,
            'land_used' => 1500,
        ]);
        $nation = new Nation(['id' => 253987]);
        $nation->setRelation('buildRecommendation', $recommendation);
        $nation->setRelation('cities', new Collection([
            $this->city(['id' => 10, 'nuclear_power' => 1]),
            $this->city(['id' => 11, 'nuclear_power' => 1, 'infrastructure' => 1900]),
        ]));

        $audit = (new CityBuildAuditService)->auditNation($nation);

        $this->assertFalse($audit['has_different_city_builds']);
        $this->assertSame(0, $audit['different_city_build_count']);
    }

    /** @param array<string, mixed> $overrides */
    private function city(array $overrides = []): City
    {
        return new City([
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            'id' => 10,
            'name' => 'Test City',
            'infrastructure' => 2000,
            'land' => 1500,
            'powered' => true,
            ...$overrides,
        ]);
    }
}
