<?php

namespace App\Services;

use App\Models\CityGrant;
use App\Services\Calculators\GamePurchaseCostCalculator;
use Illuminate\Support\Facades\Log;
use Throwable;

class CityCostService
{
    public function __construct(
        private readonly QueryService $query,
        private readonly GrantRequirementService $grantRequirementService,
        private readonly GamePurchaseCostCalculator $purchaseCostCalculator,
    ) {}

    public function getTop20Average(bool $refreshIfStale = true): ?float
    {
        $average = SettingService::getCityAverage();
        $updatedAt = SettingService::getCityAverageUpdatedAt();

        if (! $refreshIfStale) {
            return $average;
        }

        if ($average === null || $updatedAt === null || $updatedAt->lt(now()->subHours(30))) {
            return $this->refreshTop20Average() ?? $average;
        }

        return $average;
    }

    public function refreshTop20Average(): ?float
    {
        try {
            $builder = (new GraphQLQueryBuilder)
                ->setRootField('game_info')
                ->addFields(['city_average']);

            $data = $this->query->sendQuery($builder, headers: false, handlePagination: false);

            if (! isset($data->city_average)) {
                Log::warning('PW API game_info response missing city_average.');

                return null;
            }

            $average = (float) $data->city_average;

            SettingService::setCityAverage($average);
            SettingService::setCityAverageUpdatedAt(now());

            return $average;
        } catch (Throwable $e) {
            Log::warning('Failed to refresh top-20 city average: '.$e->getMessage());

            return null;
        }
    }

    public function calculateCityCost(
        int $cityNumber,
        bool $bureauOfDomesticAffairsRequired = false,
        bool $governmentSupportAgencyRequired = false
    ): ?float {
        if ($cityNumber < 1) {
            return null;
        }

        $top20Average = $this->getTop20Average();

        if ($top20Average === null) {
            return null;
        }

        return $this->purchaseCostCalculator->city(
            cityNumber: $cityNumber,
            topTwentyAverage: $top20Average,
            manifestDestiny: true,
            governmentSupportAgency: $governmentSupportAgencyRequired,
            bureauOfDomesticAffairs: $bureauOfDomesticAffairsRequired,
        )->breakdowns['purchase']->money;
    }

    public function calculateGrantAmount(CityGrant $grant): ?float
    {
        if (! $grant->city_number) {
            return null;
        }

        $requiresBda = $this->grantRequiresBureauOfDomesticAffairs($grant);
        $requiresGsa = $this->grantRequiresGovernmentSupportAgency($grant);
        $cityCost = $this->calculateCityCost($grant->city_number, $requiresBda, $requiresGsa);

        if ($cityCost === null) {
            return null;
        }

        return $cityCost * ($grant->grant_amount / 100.0);
    }

    public function calculateGrantAmountForCity(
        int $cityNumber,
        float $grantPercentage,
        bool $bureauOfDomesticAffairsRequired = false,
        bool $governmentSupportAgencyRequired = false
    ): ?float {
        $cityCost = $this->calculateCityCost(
            $cityNumber,
            $bureauOfDomesticAffairsRequired,
            $governmentSupportAgencyRequired
        );

        if ($cityCost === null) {
            return null;
        }

        return $cityCost * ($grantPercentage / 100.0);
    }

    public function grantRequiresBureauOfDomesticAffairs(CityGrant $grant): bool
    {
        $projects = $this->grantRequirementService->guaranteedProjects($grant->requirements);

        return in_array('Bureau of Domestic Affairs', $projects, true);
    }

    public function grantRequiresGovernmentSupportAgency(CityGrant $grant): bool
    {
        $projects = $this->grantRequirementService->guaranteedProjects($grant->requirements);

        return in_array('Government Support Agency', $projects, true);
    }
}
