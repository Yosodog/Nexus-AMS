<?php

namespace App\Services\BlockadeRelief;

use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Validation\ValidationException;

class BlockadeReliefEligibilityService
{
    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    public function isEligibleUser(?User $user): bool
    {
        if (! $user || $user->disabled || ! $user->isVerified() || ! $user->activeDiscordAccount()) {
            return false;
        }

        return $this->isAllianceNation($user->nation);
    }

    public function isAllianceNation(?Nation $nation): bool
    {
        return $nation !== null
            && $this->membershipService->contains($nation->alliance_id)
            && strtoupper((string) $nation->alliance_position) !== 'APPLICANT';
    }

    public function assertEligibleUser(User $user): Nation
    {
        if ($user->disabled || ! $user->isVerified()) {
            throw ValidationException::withMessages([
                'membership' => 'An active, verified Nexus account is required for blockade relief.',
            ]);
        }

        if (! $user->activeDiscordAccount()) {
            throw ValidationException::withMessages([
                'membership' => 'Link and verify an active Discord account before using blockade relief.',
            ]);
        }

        if (! $this->isAllianceNation($user->nation)) {
            throw ValidationException::withMessages([
                'membership' => 'Blockade relief is available only to alliance and enabled offshore members. Applicants are not eligible.',
            ]);
        }

        return $user->nation;
    }
}
