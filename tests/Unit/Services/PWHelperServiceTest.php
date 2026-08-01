<?php

namespace Tests\Unit\Services;

use App\Services\PWHelperService;
use Tests\UnitTestCase;

class PWHelperServiceTest extends UnitTestCase
{
    public function test_get_nation_projects_returns_projects_present_in_bitmask(): void
    {
        $bitmask = PWHelperService::PROJECTS['Urban Planning'] | PWHelperService::PROJECTS['Center for Civil Engineering'];

        $projects = PWHelperService::getNationProjects($bitmask);

        $this->assertContains('Urban Planning', $projects);
        $this->assertContains('Center for Civil Engineering', $projects);
    }

    public function test_get_nation_projects_accepts_binary_api_strings_without_integer_coercion(): void
    {
        $bitmask = PWHelperService::PROJECTS['Urban Planning']
            | PWHelperService::PROJECTS['Center for Civil Engineering'];
        $apiBits = str_pad(decbin($bitmask), count(PWHelperService::PROJECTS), '0', STR_PAD_LEFT);

        $projects = PWHelperService::getNationProjects($apiBits);

        $this->assertContains('Urban Planning', $projects);
        $this->assertContains('Center for Civil Engineering', $projects);
        $this->assertNotContains('Ironworks', $projects);
    }

    public function test_get_nation_projects_still_accepts_decimal_strings(): void
    {
        $projects = PWHelperService::getNationProjects('8192');

        $this->assertContains('Uranium Enrichment Program', $projects);
    }

    public function test_resources_returns_expected_variants(): void
    {
        $withMoney = PWHelperService::resources();
        $withoutMoney = PWHelperService::resources(false);
        $withCredits = PWHelperService::resources(true, true);

        $this->assertSame('money', $withMoney[0]);
        $this->assertNotContains('money', $withoutMoney);
        $this->assertContains('credits', $withCredits);
    }
}
