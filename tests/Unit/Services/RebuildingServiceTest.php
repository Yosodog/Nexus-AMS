<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Nation;
use App\Models\RebuildingIneligibility;
use App\Models\RebuildingRequest;
use App\Models\RebuildingTier;
use App\Services\AllianceMembershipService;
use App\Services\PWHealthService;
use App\Services\QueryService;
use App\Services\RebuildingService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

class RebuildingServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::setRebuildingCycleId(7);
        cache()->forever('alliances:membership:ids', [777]);
    }

    public function test_evaluate_eligibility_blocks_applicants(): void
    {
        $this->createTier();
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'APPLICANT',
            'num_cities' => 5,
        ]);

        $eligibility = $this->makeService()->evaluateEligibility($nation);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('Applicants are not eligible for rebuilding.', $eligibility['reason']);
    }

    public function test_evaluate_eligibility_blocks_ineligible_flagged_nations(): void
    {
        $this->createTier();
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 5,
        ]);

        RebuildingIneligibility::query()->create([
            'cycle_id' => 7,
            'nation_id' => $nation->id,
            'reason' => 'Flagged',
        ]);

        $eligibility = $this->makeService()->evaluateEligibility($nation);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('Nation is marked ineligible for this rebuilding cycle.', $eligibility['reason']);
    }

    public function test_evaluate_eligibility_blocks_nations_already_approved_this_cycle(): void
    {
        $tier = $this->createTier();
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 5,
        ]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        RebuildingRequest::query()->create([
            'cycle_id' => 7,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $tier->id,
            'city_count_snapshot' => 5,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 1000,
            'status' => 'approved',
        ]);

        $eligibility = $this->makeService()->evaluateEligibility($nation);

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('Nation has already received rebuilding this cycle.', $eligibility['reason']);
    }

    public function test_calculate_nation_rebuild_amount_sums_city_infrastructure_costs(): void
    {
        $tier = RebuildingTier::query()->create([
            'name' => 'Infra Tier',
            'min_city_count' => 1,
            'max_city_count' => 10,
            'target_infrastructure' => 1700,
            'is_active' => true,
            'requirements' => ['urban_planning', 'center_for_civil_engineering'],
        ]);

        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 2,
        ]);

        $nation->cities()->createMany([
            $this->cityPayload(1001, 1000),
            $this->cityPayload(1002, 1500),
        ]);

        $health = new PWHealthService($this->createMock(QueryService::class));
        $service = $this->makeService($health);

        $expected = round(
            $health->calcInfra(1000, 1700, true, true, false, false)
            + $health->calcInfra(1500, 1700, true, true, false, false),
            2
        );

        $this->assertSame($expected, $service->calculateNationRebuildAmount($nation->fresh('cities'), $tier));
    }

    /**
     * @return array<string, mixed>
     */
    private function cityPayload(int $id, float $infrastructure): array
    {
        return [
            'id' => $id,
            'name' => 'City '.$id,
            'date' => now()->toDateString(),
            'infrastructure' => $infrastructure,
            'land' => 250,
            'powered' => true,
            'oil_power' => 0,
            'wind_power' => 0,
            'coal_power' => 0,
            'nuclear_power' => 0,
            'coal_mine' => 0,
            'oil_well' => 0,
            'uranium_mine' => 0,
            'barracks' => 0,
            'farm' => 0,
            'police_station' => 0,
            'hospital' => 0,
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
        ];
    }

    private function createTier(): RebuildingTier
    {
        return RebuildingTier::query()->create([
            'name' => 'Standard',
            'min_city_count' => 1,
            'max_city_count' => 10,
            'target_infrastructure' => 1700,
            'is_active' => true,
            'requirements' => [],
        ]);
    }

    private function makeService(?PWHealthService $healthService = null): RebuildingService
    {
        return new RebuildingService(
            app(AllianceMembershipService::class),
            $healthService ?? new PWHealthService($this->createMock(QueryService::class))
        );
    }
}
