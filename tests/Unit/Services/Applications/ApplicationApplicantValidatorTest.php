<?php

namespace Tests\Unit\Services\Applications;

use App\Enums\AlliancePositionEnum;
use App\Exceptions\ApplicationException;
use App\GraphQL\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\Applications\ApplicationApplicantValidator;
use PHPUnit\Framework\TestCase;

class ApplicationApplicantValidatorTest extends TestCase
{
    public function test_applicant_in_the_primary_alliance_is_eligible(): void
    {
        $validator = $this->validator(777);
        $nation = $this->nation(777, AlliancePositionEnum::APPLICANT->value);

        $validator->assertApplicantEligible($nation, 'https://example.test/join');

        $this->assertTrue($validator->isNationInAlliance($nation));
    }

    public function test_nation_outside_the_primary_alliance_gets_join_guidance(): void
    {
        $validator = $this->validator(777);

        try {
            $validator->assertApplicantEligible(
                $this->nation(888, AlliancePositionEnum::APPLICANT->value),
                'https://example.test/join',
            );
            $this->fail('Expected applicant eligibility to fail.');
        } catch (ApplicationException $exception) {
            $this->assertSame('nation_not_in_our_alliance', $exception->error);
            $this->assertSame(['join_url' => 'https://example.test/join'], $exception->context);
        }
    }

    public function test_non_applicant_position_is_rejected_after_alliance_membership_passes(): void
    {
        $validator = $this->validator(777);

        try {
            $validator->assertApplicantEligible(
                $this->nation(777, AlliancePositionEnum::MEMBER->value),
                'https://example.test/join',
            );
            $this->fail('Expected applicant eligibility to fail.');
        } catch (ApplicationException $exception) {
            $this->assertSame('nation_not_applicant', $exception->error);
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_decision_validation_accepts_any_position_inside_the_primary_alliance(): void
    {
        $validator = $this->validator(777);

        $validator->assertNationInAlliance(
            $this->nation(777, AlliancePositionEnum::MEMBER->value),
            'https://example.test/join',
        );

        $this->addToAssertionCount(1);
    }

    private function validator(int $primaryAllianceId): ApplicationApplicantValidator
    {
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->method('getPrimaryAllianceId')->willReturn($primaryAllianceId);

        return new ApplicationApplicantValidator($membership);
    }

    private function nation(int $allianceId, string $position): Nation
    {
        $nation = new Nation;
        $nation->alliance_id = $allianceId;
        $nation->alliance_position = $position;

        return $nation;
    }
}
