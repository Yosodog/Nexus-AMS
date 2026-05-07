<?php

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Models\Nation;

class GrowthCircleService
{
    public function __construct(
        protected AllianceMembershipService $membershipService,
    ) {}

    /**
     * Evaluate the five eligibility gates for Growth Circles.
     *
     * Both enrollment and per-cycle distribution use this method, so
     * a member who later loses eligibility is paused (not auto-disenrolled).
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public function evaluateEligibility(Nation $nation): array
    {
        if (! $this->membershipService->contains((int) $nation->alliance_id)) {
            return ['eligible' => false, 'reason' => 'Nation is not in the alliance group.'];
        }

        if (($nation->alliance_position ?? null) === AlliancePositionEnum::APPLICANT->value) {
            return ['eligible' => false, 'reason' => 'Applicants are not eligible for Growth Circles.'];
        }

        if ((int) ($nation->vacation_mode_turns ?? 0) > 0) {
            return ['eligible' => false, 'reason' => 'Not available while in vacation mode.'];
        }

        if (strtolower((string) ($nation->color ?? '')) === 'beige') {
            return ['eligible' => false, 'reason' => 'Not available while in beige.'];
        }

        if ((int) ($nation->num_cities ?? 0) <= 0) {
            return ['eligible' => false, 'reason' => 'Nation has no cities.'];
        }

        return ['eligible' => true, 'reason' => null];
    }
}
