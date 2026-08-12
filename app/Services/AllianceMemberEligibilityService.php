<?php

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class AllianceMemberEligibilityService
{
    private const ELIGIBLE_POSITIONS = [
        AlliancePositionEnum::MEMBER->value,
        AlliancePositionEnum::OFFICER->value,
        AlliancePositionEnum::HEIR->value,
        AlliancePositionEnum::LEADER->value,
    ];

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
            && in_array($alliancePosition, self::ELIGIBLE_POSITIONS, true);
    }

    /**
     * @param  Builder<Nation>  $query
     * @return Builder<Nation>
     */
    public function applyEligibilityToQuery(Builder $query): Builder
    {
        return $query
            ->whereIn('alliance_id', $this->membershipService->getAllianceIds())
            ->whereIn('alliance_position', self::ELIGIBLE_POSITIONS);
    }
}
