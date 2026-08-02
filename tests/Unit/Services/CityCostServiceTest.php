<?php

namespace Tests\Unit\Services;

use App\Models\CityGrant;
use App\Services\CityCostService;
use Tests\TestCase;

class CityCostServiceTest extends TestCase
{
    public function test_legacy_required_projects_preserve_city_cost_discounts(): void
    {
        $grant = new CityGrant([
            'requirements' => [
                'required_projects' => [
                    'Bureau of Domestic Affairs',
                    'Government Support Agency',
                ],
            ],
        ]);
        $service = app(CityCostService::class);

        $this->assertTrue($service->grantRequiresBureauOfDomesticAffairs($grant));
        $this->assertTrue($service->grantRequiresGovernmentSupportAgency($grant));
    }

    public function test_optional_project_paths_do_not_apply_city_cost_discounts(): void
    {
        $grant = new CityGrant([
            'requirements' => [
                'group' => 'any',
                'rules' => [
                    [
                        'field' => 'projects',
                        'operator' => 'contains_all',
                        'value' => ['Bureau of Domestic Affairs', 'Government Support Agency'],
                        'message' => '',
                    ],
                    [
                        'field' => 'num_cities',
                        'operator' => 'gte',
                        'value' => 10,
                        'message' => '',
                    ],
                ],
            ],
        ]);
        $service = app(CityCostService::class);

        $this->assertFalse($service->grantRequiresBureauOfDomesticAffairs($grant));
        $this->assertFalse($service->grantRequiresGovernmentSupportAgency($grant));
    }
}
