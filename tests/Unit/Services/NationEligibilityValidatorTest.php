<?php

namespace Tests\Unit\Services;

use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\NationEligibilityValidator;
use App\Services\PWHelperService;
use Illuminate\Validation\ValidationException;
use Tests\FeatureTestCase;

class NationEligibilityValidatorTest extends FeatureTestCase
{
    public function test_validate_government_type_rejects_mismatches(): void
    {
        $validator = new NationEligibilityValidator(new Nation(['domestic_policy' => 'OPEN_MARKETS']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You must have the MANIFEST_DESTINY government type.');

        $validator->validateGovernmentType('MANIFEST_DESTINY');
    }

    public function test_validate_color_rejects_disallowed_colors(): void
    {
        $validator = new NationEligibilityValidator(new Nation(['color' => 'BLUE']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Your nation must be one of the following colors: RED, GREEN');

        $validator->validateColor(['RED', 'GREEN']);
    }

    public function test_validate_required_projects_rejects_missing_projects(): void
    {
        $nation = new Nation(['project_bits' => (string) PWHelperService::PROJECTS['Urban Planning']]);
        $validator = new NationEligibilityValidator($nation);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You must own the Center for Civil Engineering project to be eligible.');

        $validator->validateRequiredProjects(['Center for Civil Engineering']);
    }

    public function test_validate_alliance_membership_rejects_applicants(): void
    {
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->method('contains')->willReturn(true);

        $validator = new NationEligibilityValidator(
            new Nation(['alliance_id' => 777, 'alliance_position' => 'APPLICANT']),
            $membership
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Applicants are not eligible for financial aid.');

        $validator->validateAllianceMembership();
    }
}
