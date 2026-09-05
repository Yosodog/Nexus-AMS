<?php

namespace App\Services;

use App\GraphQL\Models\Nation;
use App\Services\Settings\DataSyncSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class RaidTargetActivityFilter
{
    private const HOURS_PER_TURN = 2;

    public function __construct(private readonly DataSyncSettings $settings) {}

    /**
     * @param  Collection<int, Nation>  $nations
     * @return Collection<int, Nation>
     */
    public function filter(Collection $nations): Collection
    {
        $minimumInactiveTurns = $this->settings->getRaidMinimumInactiveTurns();

        if ($minimumInactiveTurns === 0) {
            return $nations->values();
        }

        $cityThreshold = $this->settings->getRaidActivityCityThreshold();
        $inactiveCutoff = CarbonImmutable::now('UTC')
            ->subHours($minimumInactiveTurns * self::HOURS_PER_TURN);

        return $nations
            ->filter(fn (Nation $nation): bool => $this->isEligible($nation, $cityThreshold, $inactiveCutoff))
            ->values();
    }

    private function isEligible(Nation $nation, int $cityThreshold, CarbonImmutable $inactiveCutoff): bool
    {
        if ($nation->num_cities === null) {
            return false;
        }

        if ($nation->num_cities <= $cityThreshold) {
            return true;
        }

        if ($nation->last_active === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($nation->last_active, 'UTC')->lessThanOrEqualTo($inactiveCutoff);
        } catch (Throwable) {
            return false;
        }
    }
}
