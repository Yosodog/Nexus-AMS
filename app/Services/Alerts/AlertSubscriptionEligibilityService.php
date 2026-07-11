<?php

namespace App\Services\Alerts;

use App\Enums\AlliancePositionEnum;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;

class AlertSubscriptionEligibilityService
{
    public function __construct(private readonly AllianceMembershipService $allianceMembership) {}

    public function eligibleNation(User $user): ?Nation
    {
        $discordAccount = $user->relationLoaded('discordAccounts')
            ? $user->discordAccounts
                ->whereNull('unlinked_at')
                ->sortByDesc('linked_at')
                ->first()
            : $user->activeDiscordAccount();

        if ($user->disabled || ! $user->isVerified() || ! $discordAccount) {
            return null;
        }

        $nation = $user->relationLoaded('nation')
            ? $user->nation
            : $user->nation()->first();

        if (! $nation || ! $this->allianceMembership->contains($nation->alliance_id)) {
            return null;
        }

        if ($nation->alliance_position === AlliancePositionEnum::APPLICANT->value) {
            return null;
        }

        return $nation;
    }

    public function isEligible(User $user): bool
    {
        return $this->eligibleNation($user) !== null;
    }
}
