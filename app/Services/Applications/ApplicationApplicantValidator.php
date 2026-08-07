<?php

namespace App\Services\Applications;

use App\Enums\AlliancePositionEnum;
use App\Exceptions\ApplicationException;
use App\GraphQL\Models\Nation;
use App\Services\AllianceMembershipService;

class ApplicationApplicantValidator
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
    ) {}

    /**
     * @throws ApplicationException
     */
    public function assertApplicantEligible(Nation $nation, string $joinUrl): void
    {
        if (! $this->isNationInAlliance($nation)) {
            throw new ApplicationException(
                'nation_not_in_our_alliance',
                'The nation must join our alliance before applying.',
                422,
                ['join_url' => $joinUrl]
            );
        }

        if ($nation->alliance_position !== AlliancePositionEnum::APPLICANT->value) {
            throw new ApplicationException(
                'nation_not_applicant',
                'The nation must be marked as an applicant in the alliance.',
                422,
                ['join_url' => $joinUrl]
            );
        }
    }

    /**
     * @throws ApplicationException
     */
    public function assertNationInAlliance(Nation $nation, string $joinUrl): void
    {
        if (! $this->isNationInAlliance($nation)) {
            throw new ApplicationException(
                'nation_not_in_our_alliance',
                'The nation is no longer in our alliance.',
                422,
                ['join_url' => $joinUrl]
            );
        }
    }

    public function isNationInAlliance(Nation $nation): bool
    {
        return (int) ($nation->alliance_id ?? 0) === $this->membershipService->getPrimaryAllianceId();
    }
}
