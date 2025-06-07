<?php

namespace App\Services;

use App\Models\Account;
use App\Models\MMRTier;
use App\Models\Nation;
use App\Models\NationSignIn;

class MMRService
{
    private const RESOURCES = ['money', 'steel', 'aluminum', 'munitions', 'gasoline', 'uranium', 'food'];

    private const UNITS = [
        'soldiers' => ['multiplier' => 3000, 'field' => 'barracks'],
        'tanks' => ['multiplier' => 250, 'field' => 'factories'],
        'aircraft' => ['multiplier' => 15, 'field' => 'hangars'],
        'ships' => ['multiplier' => 5, 'field' => 'drydocks'],
        'missiles' => ['multiplier' => 1, 'field' => 'missiles'],
        // nukes spies handled separately
    ];

    /**
     * @param Nation $nation
     * @return MMRTier|null
     */
    public function getTierForNation(Nation $nation): ?MMRTier
    {
        return MMRTier::whereIn('city_count', [$nation->num_cities, 0])
            ->orderByDesc('city_count') // Prefer exact match, fallback to 0
            ->first();
    }

    /**
     * @param Nation $nation
     * @param NationSignIn $signIn
     * @return array
     */
    public function evaluate(Nation $nation, NationSignIn $signIn): array
    {
        $tier = $this->getTierForNation($nation);

        if (!$tier) {
            return [
                'mmr_score' => 0,
                'meets_unit_requirements' => false,
            ];
        }

        return [
            'mmr_score' => $this->calculateResourceScore($signIn, $tier),
            'meets_unit_requirements' => $this->meetsUnitRequirements($signIn, $tier, $nation->num_cities),
        ];
    }

    /**
     * Calculates the MMR resource score (0-100) based on sign-in and banked amounts.
     * @param NationSignIn $signIn
     * @param MMRTier $tier
     * @return int
     */
    protected function calculateResourceScore(NationSignIn $signIn, MMRTier $tier): int
    {
        $total = 0;

        foreach (self::RESOURCES as $resource) {
            $have = $signIn->$resource; // Already includes nation + banked
            $required = max(1, $tier->$resource);
            $total += min(1, $have / $required);
        }

        return (int) round(($total / count(self::RESOURCES)) * 100);
    }

    /**
     * Determines if the nation meets the unit-based MMR thresholds.
     * @param NationSignIn $signIn
     * @param MMRTier $tier
     * @param int $cityCount
     * @return bool
     */
    protected function meetsUnitRequirements(NationSignIn $signIn, MMRTier $tier, int $cityCount): bool
    {
        foreach (self::UNITS as $unit => $info) {
            $required = $tier->{$info['field']} * $info['multiplier'] * $cityCount;
            if ($signIn->$unit < $required) {
                return false;
            }
        }

        if ($signIn->nukes < $tier->nukes || $signIn->spies < $tier->spies) {
            return false;
        }

        return true;
    }
}