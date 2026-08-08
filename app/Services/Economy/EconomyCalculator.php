<?php

namespace App\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityContextUnavailable;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Services\Calculators\MilitaryCostCalculator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class EconomyCalculator
{
    public function __construct(private readonly MilitaryCostCalculator $militaryCostCalculator) {}

    /**
     * @return array<string, mixed>
     */
    public function calculateNation(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices
    ): array {
        $profit = EconomyRules::emptyResourceBuffer();
        $components = [
            'city_income_per_day' => 0.0,
            'color_bonus_per_day' => 0.0,
            'power_cost_per_day' => 0.0,
            'food_cost_per_day' => 0.0,
            'military_upkeep_per_day' => 0.0,
        ];

        foreach ($nation->cities as $city) {
            $cityResult = $this->calculateCity($nation, $city, $radiationSnapshot, $prices);
            $profit = $this->sumResourceBuffers($profit, $cityResult['resource_profit_per_day']);
            $components['city_income_per_day'] += $cityResult['city_income_per_day'];
            $components['power_cost_per_day'] += $cityResult['power_cost_per_day'];
            $components['food_cost_per_day'] += $cityResult['food_cost_per_day'];
        }

        $colorBonus = max(0, (int) ($nation->color_turn_bonus ?? 0)) * EconomyRules::TURNS_PER_DAY;
        $profit['money'] += $colorBonus;
        $components['color_bonus_per_day'] = $colorBonus;

        $military = $this->calculateMilitaryUpkeep($nation, $prices);
        $profit = $this->sumResourceBuffers($profit, $military['resource_profit_per_day']);
        $components['military_upkeep_per_day'] = $military['military_upkeep_per_day'];

        return [
            'nation_id' => $nation->id,
            'nation_url' => sprintf('https://politicsandwar.com/nation/id=%d', $nation->id),
            'leader_name' => (string) $nation->leader_name,
            'nation_name' => (string) $nation->nation_name,
            'cities' => (int) $nation->num_cities,
            'converted_profit_per_day' => round($prices->convert($profit), 2),
            'money_profit_per_day' => round($profit['money'], 2),
            'resource_profit_per_day' => collect($profit)
                ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => round($amount, 2)])
                ->all(),
            'city_income_per_day' => round($components['city_income_per_day'], 2),
            'color_bonus_per_day' => round($components['color_bonus_per_day'], 2),
            'power_cost_per_day' => round($components['power_cost_per_day'], 2),
            'food_cost_per_day' => round($components['food_cost_per_day'], 2),
            'military_upkeep_per_day' => round($components['military_upkeep_per_day'], 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateCityMetrics(
        Nation $nation,
        City $city,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices,
        ?CarbonInterface $asOf = null,
    ): array {
        $result = $this->calculateCity($nation, $city, $radiationSnapshot, $prices, $asOf);
        $operatingCity = $result['operating_city'];
        $hasProject = $this->projectChecker($nation);
        $gameDate = $radiationSnapshot?->game_date;

        $resourceOutput = collect($result['resource_output_per_day'])
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => round($amount, 2)])
            ->all();
        $resourceExpenses = collect($result['resource_expense_per_day'])
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => round($amount, 2)])
            ->all();
        $negativeExpenses = collect($result['resource_expense_per_day'])
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => -$amount])
            ->all();
        $farmCount = max(0, (int) ($operatingCity->farm ?? 0));

        return [
            'converted_profit_per_day' => round($prices->convert($result['resource_profit_per_day']), 2),
            'money_profit_per_day' => round($result['resource_profit_per_day']['money'], 2),
            'resource_profit_per_day' => collect($result['resource_profit_per_day'])
                ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => round($amount, 2)])
                ->all(),
            'unrounded_resource_profit_per_day' => $result['resource_profit_per_day'],
            'city_income_per_day' => round($result['city_income_per_day'], 2),
            'power_cost_per_day' => round($result['power_cost_per_day'], 2),
            'food_cost_per_day' => round($result['food_cost_per_day'], 2),
            'resource_output_per_day' => $resourceOutput,
            'resource_expense_per_day' => $resourceExpenses,
            'unrounded_resource_output_per_day' => $result['resource_output_per_day'],
            'unrounded_resource_expense_per_day' => $result['resource_expense_per_day'],
            'gross_income_market_value_per_day' => round($prices->convert($result['resource_output_per_day']), 2),
            'gross_expense_market_value_per_day' => round(-$prices->convert($negativeExpenses), 2),
            'disease' => round($this->disease($operatingCity, $hasProject, $gameDate), 2),
            'pollution' => $this->pollution($operatingCity, $hasProject, $gameDate),
            'crime' => round($this->crime($operatingCity, $hasProject), 2),
            'commerce' => $this->commerce($operatingCity, $hasProject),
            'population' => $this->population($operatingCity, $hasProject, $gameDate, $asOf),
            'powered' => $result['powered'],
            'calculation_modifiers' => [
                'new_player_income_factor' => $this->newPlayerBonus((int) $nation->num_cities),
                'city_age_population_factor' => $this->cityAgeBonus($operatingCity, $asOf),
                'farm_radiation_factor' => $farmCount > 0
                    ? $this->farmRadiationModifier((string) $nation->continent, $radiationSnapshot, $hasProject)
                    : 1.0,
                'farm_season_factor' => $farmCount > 0
                    ? $this->seasonModifier((string) $nation->continent, $radiationSnapshot)
                    : 1.0,
                'farm_continent_factor' => $farmCount > 0
                    ? $this->continentProductionModifier((string) $nation->continent)
                    : 1.0,
            ],
        ];
    }

    /**
     * @return array{
     *     resource_profit_per_day: array<string, float>,
     *     resource_output_per_day: array<string, float>,
     *     resource_expense_per_day: array<string, float>,
     *     city_income_per_day: float,
     *     power_cost_per_day: float,
     *     food_cost_per_day: float,
     *     powered: bool,
     *     operating_city: City
     * }
     */
    public function calculateCity(
        Nation $nation,
        City $city,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices,
        ?CarbonInterface $asOf = null,
    ): array {
        $profit = EconomyRules::emptyResourceBuffer();
        $output = EconomyRules::emptyResourceBuffer();
        $expenses = EconomyRules::emptyResourceBuffer();
        $hasProject = $this->projectChecker($nation);
        $powered = (bool) $city->powered && $this->poweredInfrastructure($city) >= (float) $city->infrastructure;

        foreach (EconomyRules::RAW_BUILDINGS as $building => $resource) {
            $count = max(0, (int) ($city->{$building} ?? 0));

            if ($count <= 0) {
                continue;
            }

            $upkeep = EconomyRules::buildingMoneyUpkeep($building, $hasProject) * $count;
            $production = $this->resourceProduction(
                $resource,
                (float) $city->land,
                $count,
                (string) $nation->continent,
                $hasProject,
                $radiationSnapshot
            );
            $profit['money'] -= $upkeep;
            $profit[$resource] += $production;
            $expenses['money'] += $upkeep;
            $output[$resource] += $production;
        }

        $powerVector = $this->powerOperatingVector($nation, $city, (int) ceil((float) $city->infrastructure));
        $profit = $this->sumResourceBuffers($profit, $powerVector);
        foreach ($powerVector as $resource => $amount) {
            if ($amount < 0) {
                $expenses[$resource] += -$amount;
            }
        }
        $powerCost = $prices->convert($powerVector);

        if ($powered) {
            foreach (EconomyRules::MANUFACTURING_BUILDINGS as $building => $resource) {
                $count = max(0, (int) ($city->{$building} ?? 0));

                if ($count <= 0) {
                    continue;
                }

                $upkeep = EconomyRules::buildingMoneyUpkeep($building, $hasProject) * $count;
                $production = $this->resourceProduction(
                    $resource,
                    (float) $city->land,
                    $count,
                    (string) $nation->continent,
                    $hasProject,
                    $radiationSnapshot
                );
                $profit['money'] -= $upkeep;
                $profit[$resource] += $production;
                $expenses['money'] += $upkeep;
                $output[$resource] += $production;

                foreach ($this->manufacturedInputs($resource, $count, $hasProject) as $input => $amount) {
                    $profit[$input] -= $amount;
                    $expenses[$input] += $amount;
                }
            }

            foreach (EconomyRules::SUPPORT_FIELDS as $building) {
                $upkeep = EconomyRules::buildingMoneyUpkeep($building, $hasProject)
                    * max(0, (int) ($city->{$building} ?? 0));
                $profit['money'] -= $upkeep;
                $expenses['money'] += $upkeep;
            }
        }

        $operatingCity = $powered ? $city : $this->withoutPoweredImprovements($city);
        $operatingHasProject = $this->projectChecker($nation);
        $gameDate = $radiationSnapshot?->game_date;
        $income = max(
            0.0,
            ((((($this->commerce($operatingCity, $operatingHasProject) * 0.02) * 0.725) + 0.725)
                * $this->population($operatingCity, $operatingHasProject, $gameDate, $asOf))
                * $this->newPlayerBonus((int) $nation->num_cities))
                * ($this->grossModifier($nation, false) + max(0.0, (float) ($nation->treasure_income_modifier ?? 0.0)))
        );
        $profit['money'] += $income;
        $output['money'] += $income;

        $foodConsumption = $this->foodConsumption($operatingCity, $asOf);
        $profit['food'] -= $foodConsumption;
        $expenses['food'] += $foodConsumption;

        return [
            'resource_profit_per_day' => $profit,
            'resource_output_per_day' => $output,
            'resource_expense_per_day' => $expenses,
            'city_income_per_day' => $income,
            'power_cost_per_day' => $powerCost,
            'food_cost_per_day' => -($foodConsumption * $prices->priceFor('food', -$foodConsumption)),
            'powered' => $powered,
            'operating_city' => $operatingCity,
        ];
    }

    /**
     * Operating contribution before population-derived income.
     *
     * @return array<string, float>
     */
    public function improvementOperatingVector(
        Nation $nation,
        City $city,
        string $field,
        int $count,
        ?RadiationSnapshot $radiationSnapshot
    ): array {
        $profit = EconomyRules::emptyResourceBuffer();

        if ($count <= 0) {
            return $profit;
        }

        $hasProject = $this->projectChecker($nation);
        $profit['money'] -= EconomyRules::buildingMoneyUpkeep($field, $hasProject) * $count;

        if (isset(EconomyRules::RAW_BUILDINGS[$field])) {
            $resource = EconomyRules::RAW_BUILDINGS[$field];
            $profit[$resource] += $this->resourceProduction(
                $resource,
                (float) $city->land,
                $count,
                (string) $nation->continent,
                $hasProject,
                $radiationSnapshot
            );
        }

        if (isset(EconomyRules::MANUFACTURING_BUILDINGS[$field])) {
            $resource = EconomyRules::MANUFACTURING_BUILDINGS[$field];
            $profit[$resource] += $this->resourceProduction(
                $resource,
                (float) $city->land,
                $count,
                (string) $nation->continent,
                $hasProject,
                $radiationSnapshot
            );

            foreach ($this->manufacturedInputs($resource, $count, $hasProject) as $input => $amount) {
                $profit[$input] -= $amount;
            }
        }

        return $profit;
    }

    /**
     * @return array<string, float>
     */
    public function powerOperatingVector(Nation $nation, City $city, int $targetInfrastructure): array
    {
        $profit = EconomyRules::emptyResourceBuffer();
        $remainingInfrastructure = $targetInfrastructure;
        $hasProject = $this->projectChecker($nation);

        foreach (EconomyRules::POWER_FIELDS as $powerPlant) {
            $count = max(0, (int) ($city->{$powerPlant} ?? 0));

            for ($index = 0; $index < $count; $index++) {
                $profit['money'] -= EconomyRules::buildingMoneyUpkeep($powerPlant, $hasProject);
                $this->applyPowerResourceUsage($profit, $powerPlant, $remainingInfrastructure);
                $remainingInfrastructure -= EconomyRules::powerCapacity($powerPlant);
            }
        }

        return $profit;
    }

    public function pollution(City $city, callable $hasProject, ?CarbonImmutable $gameDate = null): int
    {
        $pollution = 0;

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $pollution += EconomyRules::pollutionContribution(
                $field,
                max(0, (int) ($city->{$field} ?? 0)),
                $hasProject
            );
        }

        return max(0, $pollution + $this->nukePollution($city, $gameDate));
    }

    public function commerce(City $city, callable $hasProject): int
    {
        if (! (bool) $city->powered) {
            return 0;
        }

        $commerce = collect(EconomyRules::SUPPORT_FIELDS)->sum(
            fn (string $field): int => EconomyRules::commerceContribution($field, max(0, (int) ($city->{$field} ?? 0)))
        );

        if ($hasProject('specialized_police_training_program')) {
            $commerce += 4;
        }

        $maxCommerce = 100;

        if ($hasProject('international_trade_center')) {
            $commerce += 1;
            $maxCommerce = 115;

            if ($hasProject('telecommunications_satellite')) {
                $commerce += 2;
                $maxCommerce = 125;
            }
        }

        return min($commerce, $maxCommerce);
    }

    public function crime(City $city, callable $hasProject): float
    {
        $infraCents = (float) $city->infrastructure * 100;
        $policeModifier = max(0, (int) $city->police_station)
            * ($hasProject('specialized_police_training_program') ? 3.5 : 2.5);

        return max(
            0.0,
            ((((103 - $this->commerce($city, $hasProject)) ** 2) + $infraCents) * 0.000009) - $policeModifier
        );
    }

    public function disease(City $city, callable $hasProject, ?CarbonImmutable $gameDate = null): float
    {
        $infraCents = (float) $city->infrastructure * 100;
        $landCents = (float) $city->land * 100;
        $hospitalModifier = max(0, (int) $city->hospital)
            * ($hasProject('clinical_research_center') ? 3.5 : 2.5);

        return max(
            0.0,
            ((0.01 * (($infraCents / (($landCents * 0.01) + 0.001)) ** 2) - 25) * 0.01)
                + ($infraCents * 0.01 * 0.001)
                - $hospitalModifier
                + ($this->pollution($city, $hasProject, $gameDate) * 0.05)
        );
    }

    public function population(
        City $city,
        callable $hasProject,
        ?CarbonImmutable $gameDate = null,
        ?CarbonInterface $asOf = null,
    ): int {
        $infraCents = (float) $city->infrastructure * 100;
        $ageBonus = $this->cityAgeBonus($city, $asOf);
        $diseaseDeaths = ($this->disease($city, $hasProject, $gameDate) * 0.01) * $infraCents;
        $crimeDeaths = max(($this->crime($city, $hasProject) * 0.1) * $infraCents - 25, 0);

        return (int) round(max(10, ($infraCents - $diseaseDeaths - $crimeDeaths) * $ageBonus));
    }

    /**
     * Population-derived metrics for a durable recommendation state.
     *
     * @return array{income: float, disease: float, crime: float, commerce: int, population: int, pollution: int}
     */
    public function populationMetricsForState(
        Nation $nation,
        City $city,
        int $basePollution,
        int $baseCommerce,
        int $hospitals,
        int $policeStations
    ): array {
        $hasProject = $this->projectChecker($nation);
        $commerce = $baseCommerce;

        if ($hasProject('specialized_police_training_program')) {
            $commerce += 4;
        }

        $maximumCommerce = 100;

        if ($hasProject('international_trade_center')) {
            $commerce += 1;
            $maximumCommerce = 115;

            if ($hasProject('telecommunications_satellite')) {
                $commerce += 2;
                $maximumCommerce = 125;
            }
        }

        $commerce = min($commerce, $maximumCommerce);
        $pollution = max(0, $basePollution + ($hospitals * 4) + $policeStations);
        $infraCents = (float) $city->infrastructure * 100;
        $landCents = (float) $city->land * 100;
        $hospitalModifier = $hospitals * ($hasProject('clinical_research_center') ? 3.5 : 2.5);
        $disease = max(
            0.0,
            ((0.01 * (($infraCents / (($landCents * 0.01) + 0.001)) ** 2) - 25) * 0.01)
                + ($infraCents * 0.01 * 0.001)
                - $hospitalModifier
                + ($pollution * 0.05)
        );
        $policeModifier = $policeStations * ($hasProject('specialized_police_training_program') ? 3.5 : 2.5);
        $crime = max(
            0.0,
            ((((103 - $commerce) ** 2) + $infraCents) * 0.000009) - $policeModifier
        );
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays(now()));
        $ageBonus = 1 + log($ageDays) * 0.06666666666666667;
        $diseaseDeaths = ($disease * 0.01) * $infraCents;
        $crimeDeaths = max(($crime * 0.1) * $infraCents - 25, 0);
        $population = (int) round(max(10, ($infraCents - $diseaseDeaths - $crimeDeaths) * $ageBonus));
        $income = max(
            0.0,
            ((((($commerce * 0.02) * 0.725) + 0.725) * $population)
                * $this->newPlayerBonus((int) $nation->num_cities))
                * ($this->grossModifier($nation, false) + max(0.0, (float) ($nation->treasure_income_modifier ?? 0.0)))
        );

        return [
            'income' => $income,
            'disease' => $disease,
            'crime' => $crime,
            'commerce' => $commerce,
            'population' => $population,
            'pollution' => $pollution,
        ];
    }

    /**
     * Precomputed constants for repeated recommendation population scoring.
     *
     * @return array<string, mixed>
     */
    public function populationScoringContext(Nation $nation, iterable $cities): array
    {
        $hasProject = $this->projectChecker($nation);
        $commerceBonus = $hasProject('specialized_police_training_program') ? 4 : 0;
        $maximumCommerce = 100;

        if ($hasProject('international_trade_center')) {
            $commerceBonus += 1;
            $maximumCommerce = 115;

            if ($hasProject('telecommunications_satellite')) {
                $commerceBonus += 2;
                $maximumCommerce = 125;
            }
        }

        $profiles = [];

        foreach ($cities as $city) {
            $infraCents = (float) $city->infrastructure * 100;
            $landCents = (float) $city->land * 100;
            $profiles[] = [
                'infra_cents' => $infraCents,
                'disease_base' => ((0.01 * (($infraCents / (($landCents * 0.01) + 0.001)) ** 2) - 25) * 0.01)
                    + ($infraCents * 0.01 * 0.001),
                'age_bonus' => 1 + log(max(1, Carbon::parse($city->date)->diffInDays(now()))) * 0.06666666666666667,
            ];
        }

        return [
            'commerce_bonus' => $commerceBonus,
            'maximum_commerce' => $maximumCommerce,
            'hospital_modifier' => $hasProject('clinical_research_center') ? 3.5 : 2.5,
            'police_modifier' => $hasProject('specialized_police_training_program') ? 3.5 : 2.5,
            'income_multiplier' => $this->newPlayerBonus((int) $nation->num_cities)
                * ($this->grossModifier($nation, false) + max(0.0, (float) ($nation->treasure_income_modifier ?? 0.0))),
            'profiles' => $profiles,
        ];
    }

    public function dailyFoodConsumption(City $city): float
    {
        return $this->foodConsumption($city);
    }

    public function resourceProduction(
        string $resource,
        float $land,
        int $count,
        ?string $continent,
        callable $hasProject,
        ?RadiationSnapshot $radiationSnapshot
    ): float {
        if ($count <= 0) {
            return 0.0;
        }

        if ($resource === 'food') {
            $radiation = $this->farmRadiationModifier($continent, $radiationSnapshot, $hasProject);

            $base = max(
                0.0,
                ($land / ($hasProject('mass_irrigation') ? 400 : 500))
                    * 12
                    * $this->seasonModifier($continent, $radiationSnapshot)
                    * $this->continentProductionModifier($continent)
                    * $radiation
            );

            return $this->scaledResourceProduction($base, $count, 20);
        }

        [$base, $cap, $project, $boost] = match ($resource) {
            'coal', 'oil', 'lead', 'iron', 'bauxite' => [3.0, 10, null, 1.0],
            'uranium' => [3.0, 5, 'uranium_enrichment_program', 2.0],
            'gasoline' => [6.0, 5, 'emergency_gasoline_reserve', 2.0],
            'munitions' => [18.0, 5, 'arms_stockpile', 1.2],
            'steel' => [9.0, 5, 'iron_works', 1.36],
            'aluminum' => [9.0, 5, 'bauxite_works', 1.36],
            default => [0.0, 1, null, 1.0],
        };

        if ($project !== null && $hasProject($project)) {
            $base *= $boost;
        }

        return $this->scaledResourceProduction($base, $count, $cap);
    }

    /**
     * @return array<string, float>
     */
    private function manufacturedInputs(string $resource, int $count, callable $hasProject): array
    {
        [$cap, $baseInput, $project, $boost, $inputs] = match ($resource) {
            'gasoline' => [5, 3.0, 'emergency_gasoline_reserve', 2.0, ['oil']],
            'munitions' => [5, 6.0, null, 1.0, ['lead']],
            'steel' => [5, 3.0, 'iron_works', 1.36, ['iron', 'coal']],
            'aluminum' => [5, 3.0, 'bauxite_works', 1.36, ['bauxite']],
            default => [1, 0.0, null, 1.0, []],
        };

        if ($count <= 0 || $inputs === []) {
            return [];
        }

        if ($project !== null && $hasProject($project)) {
            $baseInput *= $boost;
        }

        $inputAmount = $this->scaledResourceProduction($baseInput, $count, $cap);

        return collect($inputs)->mapWithKeys(fn (string $input): array => [$input => $inputAmount])->all();
    }

    /**
     * @return array{resource_profit_per_day: array<string, float>, military_upkeep_per_day: float}
     */
    private function calculateMilitaryUpkeep(Nation $nation, MarketPriceSet $prices): array
    {
        $profit = EconomyRules::emptyResourceBuffer();
        $atWar = ((int) ($nation->offensive_wars_count ?? 0) + (int) ($nation->defensive_wars_count ?? 0)) > 0;
        $military = $nation->military;
        $hasProject = $this->projectChecker($nation);
        $result = $this->militaryCostCalculator->calculate(
            quantities: collect(MilitaryCostCalculator::UNITS)
                ->mapWithKeys(fn (string $unit): array => [$unit => max(0, (int) ($military?->{$unit} ?? 0))])
                ->all(),
            researchLevels: [
                'ground_cost' => max(0, min(20, (int) ($nation->ground_cost_research ?? 0))),
                'ground_capacity' => max(0, min(20, (int) ($nation->ground_capacity_research ?? 0))),
                'air_cost' => max(0, min(20, (int) ($nation->air_cost_research ?? 0))),
                'air_capacity' => max(0, min(20, (int) ($nation->air_capacity_research ?? 0))),
                'naval_cost' => max(0, min(20, (int) ($nation->naval_cost_research ?? 0))),
                'naval_capacity' => max(0, min(20, (int) ($nation->naval_capacity_research ?? 0))),
            ],
            wartime: $atWar,
            imperialism: $nation->domestic_policy === 'IMPERIALISM',
            governmentSupportAgency: $hasProject('government_support_agency'),
            bureauOfDomesticAffairs: $hasProject('bureau_of_domestic_affairs'),
            prices: $prices,
        );
        $upkeep = $result->breakdowns['daily_upkeep'];
        $profit['money'] -= $upkeep->money;

        foreach ($upkeep->resources as $resource => $amount) {
            $profit[$resource] -= $amount;
        }

        return [
            'resource_profit_per_day' => $profit,
            'military_upkeep_per_day' => -($upkeep->marketValue ?? 0.0),
        ];
    }

    private function applyPowerResourceUsage(array &$profit, string $powerPlant, int $remainingInfrastructure): void
    {
        [$resource, $baseInfrastructure, $maxInfrastructure, $amountPerLevel] = match ($powerPlant) {
            'coal_power' => ['coal', 100, 500, 1.2],
            'oil_power' => ['oil', 100, 500, 1.2],
            'nuclear_power' => [
                'uranium',
                1000,
                2000,
                EconomyRules::NUCLEAR_FUEL_PER_THOUSAND_INFRASTRUCTURE,
            ],
            default => [null, 0, 0, 0.0],
        };

        if ($resource === null || $remainingInfrastructure <= 0) {
            return;
        }

        $levels = $remainingInfrastructure < $baseInfrastructure
            ? 1
            : (int) ceil(min($remainingInfrastructure, $maxInfrastructure) / $baseInfrastructure);
        $profit[$resource] -= $levels * $amountPerLevel;
    }

    private function poweredInfrastructure(City $city): int
    {
        return collect(EconomyRules::POWER_FIELDS)->sum(
            fn (string $field): int => max(0, (int) ($city->{$field} ?? 0)) * EconomyRules::powerCapacity($field)
        );
    }

    private function withoutPoweredImprovements(City $city): City
    {
        $attributes = $city->getAttributes();
        $attributes['powered'] = false;

        foreach ([
            ...array_keys(EconomyRules::MANUFACTURING_BUILDINGS),
            ...EconomyRules::SUPPORT_FIELDS,
            ...EconomyRules::MILITARY_BUILDING_FIELDS,
        ] as $field) {
            $attributes[$field] = 0;
        }

        $unpoweredCity = new City;
        $unpoweredCity->setRawAttributes($attributes);

        return $unpoweredCity;
    }

    private function grossModifier(Nation $nation, bool $noFood): float
    {
        $hasProject = $this->projectChecker($nation);
        $modifier = 1.0;

        if ($nation->domestic_policy === 'OPEN_MARKETS') {
            $modifier += 0.01;

            if ($hasProject('government_support_agency')) {
                $modifier += 0.005;
            }

            if ($hasProject('bureau_of_domestic_affairs')) {
                $modifier += 0.0025;
            }
        }

        if ($noFood) {
            $modifier -= 0.33;
        }

        return $modifier;
    }

    private function newPlayerBonus(int $cityCount): float
    {
        return 1 + max(1 - (($cityCount - 1) * 0.05), 0);
    }

    private function cityAgeBonus(City $city, ?CarbonInterface $asOf = null): float
    {
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays($asOf ?? now()));

        return 1 + log($ageDays) * 0.06666666666666667;
    }

    private function foodConsumption(City $city, ?CarbonInterface $asOf = null): float
    {
        $basePopulation = (float) $city->infrastructure * 100;
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays($asOf ?? now()));

        return (($basePopulation ** 2) / 125_000_000)
            + (($basePopulation * (1 + (log($ageDays) / 15))) - $basePopulation) / 850;
    }

    private function nukePollution(City $city, ?CarbonImmutable $gameDate): int
    {
        if (! $city->nuke_date) {
            return 0;
        }

        if ($gameDate === null) {
            throw new ProfitabilityContextUnavailable('A game date is required to calculate nuclear pollution.');
        }

        $nukeDate = CarbonImmutable::parse($city->nuke_date, 'UTC')->startOfDay();
        $gameDate = $gameDate->startOfDay();

        if ($nukeDate->isAfter($gameDate)) {
            throw new ProfitabilityContextUnavailable(
                "City {$city->id} has a nuclear date later than the pinned game date."
            );
        }

        $turnsSinceNuke = (int) $nukeDate->diffInDays($gameDate);

        if ($turnsSinceNuke >= EconomyRules::NUKE_POLLUTION_TURNS) {
            return 0;
        }

        return (int) max(
            0,
            ((EconomyRules::NUKE_POLLUTION_TURNS - $turnsSinceNuke) * EconomyRules::NUKE_POLLUTION_MAX)
                / EconomyRules::NUKE_POLLUTION_TURNS
        );
    }

    private function radiationModifier(?string $continent, ?RadiationSnapshot $snapshot): float
    {
        if ($continent === null || $snapshot === null) {
            return 1.0;
        }

        $local = match (strtoupper($continent)) {
            'NA' => (float) $snapshot->north_america,
            'SA' => (float) $snapshot->south_america,
            'EU' => (float) $snapshot->europe,
            'AF' => (float) $snapshot->africa,
            'AS' => (float) $snapshot->asia,
            'AU' => (float) $snapshot->australia,
            'AN' => (float) $snapshot->antarctica,
            default => 0.0,
        };
        $globalAverage = (
            (float) $snapshot->north_america
            + (float) $snapshot->south_america
            + (float) $snapshot->europe
            + (float) $snapshot->africa
            + (float) $snapshot->asia
            + (float) $snapshot->australia
            + (float) $snapshot->antarctica
        ) / 5;
        $radiationIndex = min(1000.0, max(0.0, $local + $globalAverage));

        return 1 - ($radiationIndex / 1000);
    }

    private function farmRadiationModifier(
        ?string $continent,
        ?RadiationSnapshot $snapshot,
        callable $hasProject,
    ): float {
        $radiation = $this->radiationModifier($continent, $snapshot);

        return $hasProject('fallout_shelter')
            ? max(0.0, min(1.0, 0.15 + (0.85 * $radiation)))
            : $radiation;
    }

    private function seasonModifier(?string $continent, ?RadiationSnapshot $snapshot): float
    {
        if ($snapshot?->game_date === null) {
            throw new ProfitabilityContextUnavailable('A game date is required to calculate seasonal production.');
        }

        $month = $snapshot->game_date->month;
        $continent = strtoupper((string) $continent);

        if (in_array($month, [12, 1, 2], true)) {
            return match ($continent) {
                'NA', 'EU', 'AS' => 0.8,
                'AN' => 0.5,
                default => 1.2,
            };
        }

        if (in_array($month, [6, 7, 8], true)) {
            return match ($continent) {
                'NA', 'EU', 'AS' => 1.2,
                'AN' => 0.5,
                default => 0.8,
            };
        }

        return 1.0;
    }

    private function continentProductionModifier(?string $continent): float
    {
        return strtoupper((string) $continent) === 'AN' ? 0.5 : 1.0;
    }

    private function scaledResourceProduction(float $base, int $count, int $cap): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        return $base * (1 + (0.5 * (($count - 1) / ($cap - 1)))) * $count;
    }

    private function projectChecker(Nation $nation): callable
    {
        return fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
    }

    /**
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
     * @return array<string, float>
     */
    private function sumResourceBuffers(array $left, array $right): array
    {
        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $left[$resource] = (float) ($left[$resource] ?? 0.0) + (float) ($right[$resource] ?? 0.0);
        }

        return $left;
    }
}
