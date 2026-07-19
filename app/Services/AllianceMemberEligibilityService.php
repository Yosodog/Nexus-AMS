<?php

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AllianceMemberEligibilityService
{
    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    /**
     * Resolve an eligible member nation from the configured alliance umbrella.
     *
     * Enabled offshores are intentionally included through AllianceMembershipService.
     * Applicants are not treated as members for operational features.
     *
     * @throws AuthorizationException
     */
    public function nationFor(User $user): Nation
    {
        $nation = $user->nation;

        if (! $nation || ! $this->isEligibleNation($nation)) {
            throw new AuthorizationException('This feature is available only to current alliance members.');
        }

        return $nation;
    }

    public function isEligibleNation(Nation $nation): bool
    {
        $alliancePosition = strtoupper(trim((string) $nation->alliance_position));

        return $this->membershipService->contains($nation->alliance_id)
            && in_array($alliancePosition, [
                AlliancePositionEnum::MEMBER->value,
                AlliancePositionEnum::OFFICER->value,
                AlliancePositionEnum::HEIR->value,
                AlliancePositionEnum::LEADER->value,
            ], true);
    }
}
