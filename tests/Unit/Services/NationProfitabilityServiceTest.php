<?php

namespace Tests\Unit\Services;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Services\AllianceMembershipService;
use App\Services\NationProfitabilityService;
use App\Services\PWHelperService;
use App\Services\RadiationService;
use App\Services\TradePriceService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class NationProfitabilityServiceTest extends TestCase
{
    public function test_project_bits_are_read_as_a_numeric_bitmask(): void
    {
        $nation = new Nation([
            'project_bits' => (string) (
                PWHelperService::PROJECTS['Bauxiteworks']
                | PWHelperService::PROJECTS['Arms Stockpile']
                | PWHelperService::PROJECTS['Emergency Gasoline Reserve']
            ),
        ]);

        $this->assertTrue($nation->projects['bauxite_works']);
        $this->assertTrue($nation->projects['arms_stockpile']);
        $this->assertTrue($nation->projects['emergency_gasoline_reserve']);
        $this->assertFalse($nation->projects['uranium_enrichment_program']);
    }

    public function test_profitability_resource_inputs_match_growth_circle_shortfalls(): void
    {
        $nation = new Nation([
            'id' => 526341,
            'leader_name' => 'Leader',
            'nation_name' => 'Nation',
            'continent' => 'NA',
            'domestic_policy' => 'MANIFEST_DESTINY',
            'num_cities' => 1,
            'project_bits' => (string) (
                PWHelperService::PROJECTS['Bauxiteworks']
                | PWHelperService::PROJECTS['Arms Stockpile']
                | PWHelperService::PROJECTS['Emergency Gasoline Reserve']
            ),
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
        ]);

        $city = new City([
            'date' => now()->subYear()->toDateString(),
            'infrastructure' => 2000,
            'land' => 1000,
            'powered' => true,
            'nuclear_power' => 1,
            'oil_refinery' => 5,
            'munitions_factory' => 5,
            'aluminum_refinery' => 5,
        ]);

        $nation->setRelation('cities', new Collection([$city]));
        $nation->setRelation('military', new NationMilitary);

        $result = $this->service()->calculateNationProfitability($nation, null, $this->resourcePrices());
        $resources = $result['resource_profit_per_day'];

        $this->assertSame(-6.0, $resources['uranium']);
        $this->assertSame(-45.0, $resources['oil']);
        $this->assertSame(-45.0, $resources['lead']);
        $this->assertSame(-30.6, $resources['bauxite']);
    }

    private function service(): NationProfitabilityService
    {
        return new NationProfitabilityService(
            $this->createMock(AllianceMembershipService::class),
            $this->createMock(TradePriceService::class),
            $this->createMock(RadiationService::class),
        );
    }

    /**
     * @return array<string, float>
     */
    private function resourcePrices(): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => 1.0])
            ->all();
    }
}
