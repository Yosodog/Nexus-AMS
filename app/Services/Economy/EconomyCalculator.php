<?php

namespace App\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityContextUnavailable;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class EconomyCalculator
{
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

        $colorBonus = max(0, (int) ($nation->color_turn_bonus ?? 0)) * 12;
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
        MarketPriceSet $prices
    ): array {
        $result = $this->calculateCity($nation, $city, $radiationSnapshot, $prices);
        $operatingCity = $result['operating_city'];
        $hasProject = $this->projectChecker($nation);
        $gameDate = $radiationSnapshot?->game_date;

        return [
            'converted_profit_per_day' => round($prices->convert($result['resource_profit_per_day']), 2),
            'money_profit_per_day' => round($result['resource_profit_per_day']['money'], 2),
            'resource_profit_per_day' => collect($result['resource_profit_per_day'])
                ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => round($amount, 2)])
                ->all(),
            'city_income_per_day' => round($result['city_income_per_day'], 2),
            'power_cost_per_day' => round($result['power_cost_per_day'], 2),
            'food_cost_per_day' => round($result['food_cost_per_day'], 2),
            'disease' => round($this->disease($operatingCity, $hasProject, $gameDate), 2),
            'pollution' => $this->pollution($operatingCity, $hasProject, $gameDate),
            'crime' => round($this->crime($operatingCity, $hasProject), 2),
            'commerce' => $this->commerce($operatingCity, $hasProject),
            'population' => $this->population($operatingCity, $hasProject, $gameDate),
            'powered' => $result['powered'],
        ];
    }

    /**
     * @return array{resource_profit_per_day: array<string, float>, city_income_per_day: float, power_cost_per_day: float, food_cost_per_day: float, powered: bool, operating_city: City}
     */
    public function calculateCity(
        Nation $nation,
        City $city,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices
    ): array {
        $profit = EconomyRules::emptyResourceBuffer();
        $hasProject = $this->projectChecker($nation);
        $powered = (bool) $city->powered && $this->poweredInfrastructure($city) >= (float) $city->infrastructure;

        foreach (EconomyRules::RAW_BUILDINGS as $building => $resource) {
            $count = max(0, (int) ($city->{$building} ?? 0));

            if ($count <= 0) {
                continue;
            }

            $profit['money'] -= EconomyRules::buildingMoneyUpkeep($building, $hasProject) * $count;
            $profit[$resource] += $this->resourceProduction(
                $resource,
                (float) $city->land,
                $count,
                (string) $nation->continent,
                $hasProject,
                $radiationSnapshot
            );
        }

        $powerVector = $this->powerOperatingVector($nation, $city, (int) ceil((float) $city->infrastructure));
        $profit = $this->sumResourceBuffers($profit, $powerVector);
        $powerCost = $prices->convert($powerVector);

        if ($powered) {
            foreach (EconomyRules::MANUFACTURING_BUILDINGS as $building => $resource) {
                $count = max(0, (int) ($city->{$building} ?? 0));

                if ($count <= 0) {
                    continue;
                }

                $profit['money'] -= EconomyRules::buildingMoneyUpkeep($building, $hasProject) * $count;
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

            foreach (EconomyRules::SUPPORT_FIELDS as $building) {
                $profit['money'] -= EconomyRules::buildingMoneyUpkeep($building, $hasProject)
                    * max(0, (int) ($city->{$building} ?? 0));
            }
        }

        $operatingCity = $powered ? $city : $this->withoutPoweredImprovements($city);
        $operatingHasProject = $this->projectChecker($nation);
        $gameDate = $radiationSnapshot?->game_date;
        $income = max(
            0.0,
            ((((($this->commerce($operatingCity, $operatingHasProject) * 0.02) * 0.725) + 0.725)
                * $this->population($operatingCity, $operatingHasProject, $gameDate))
                * $this->newPlayerBonus((int) $nation->num_cities))
                * ($this->grossModifier($nation, false) + max(0.0, (float) ($nation->treasure_income_modifier ?? 0.0)))
        );
        $profit['money'] += $income;

        $foodConsumption = $this->foodConsumption($operatingCity);
        $profit['food'] -= $foodConsumption;

        return [
            'resource_profit_per_day' => $profit,
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

    public function population(City $city, callable $hasProject, ?CarbonImmutable $gameDate = null): int
    {
        $infraCents = (float) $city->infrastructure * 100;
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays(now()));
        $ageBonus = 1 + log($ageDays) * 0.06666666666666667;
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
            $radiation = $this->radiationModifier($continent, $radiationSnapshot);

            if ($hasProject('fallout_shelter')) {
                $radiation = max(0.0, min(1.0, 0.15 + (0.85 * $radiation)));
            }

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
        $factor = $this->militaryUpkeepFactor($nation);
        $military = $nation->military;
        $research = fn (string $field): int => max(0, min(20, (int) ($nation->{$field} ?? 0)));

        $soldiers = max(0, (int) ($military?->soldiers ?? 0));
        $soldierMoney = ($atWar ? 1.875 : 1.25)
            - ($research('ground_cost_research') * ($atWar ? 0.03 : 0.02))
            - ($research('ground_capacity_research') * ($atWar ? 0.06 : 0.04));
        $soldierFoodDenominator = ($atWar ? 500 : 750)
            + ($research('ground_cost_research') * ($atWar ? 30 : 20));
        $profit['money'] -= $soldiers * max(0.0, $soldierMoney) * $factor;
        $profit['food'] -= $soldiers * (1 / max(1, $soldierFoodDenominator)) * $factor;

        $tanks = max(0, (int) ($military?->tanks ?? 0));
        $tankMoney = ($atWar ? 75.0 : 50.0)
            - ($research('ground_cost_research') * ($atWar ? 1.5 : 1.0))
            - ($research('ground_capacity_research') * ($atWar ? 3.0 : 2.0));
        $profit['money'] -= $tanks * max(0.0, $tankMoney) * $factor;

        $aircraft = max(0, (int) ($military?->aircraft ?? 0));
        $aircraftMoney = ($atWar ? 1000.0 : 750.0)
            - ($research('air_cost_research') * ($atWar ? 10.0 : 15.0))
            - ($research('air_capacity_research') * ($atWar ? 20.0 : 30.0));
        $profit['money'] -= $aircraft * max(0.0, $aircraftMoney) * $factor;

        $ships = max(0, (int) ($military?->ships ?? 0));
        $shipMoney = ($atWar ? 5000.0 : 3300.0)
            - ($research('naval_cost_research') * ($atWar ? 50.0 : 30.0))
            - ($research('naval_capacity_research') * ($atWar ? 100.0 : 60.0));
        $profit['money'] -= $ships * max(0.0, $shipMoney) * $factor;

        foreach ([
            'missiles' => [$atWar ? 31500.0 : 21000.0, 1.0],
            'nukes' => [$atWar ? 52500.0 : 35000.0, 1.0],
            'spies' => [2400.0, 1.0],
        ] as $unit => [$money, $multiplier]) {
            $profit['money'] -= max(0, (int) ($military?->{$unit} ?? 0)) * $money * $multiplier * $factor;
        }

        return [
            'resource_profit_per_day' => $profit,
            'military_upkeep_per_day' => $prices->convert($profit),
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

        return new City($attributes);
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

    private function militaryUpkeepFactor(Nation $nation): float
    {
        $hasProject = $this->projectChecker($nation);
        $factor = 1.0;

        if ($nation->domestic_policy === 'IMPERIALISM') {
            $factor -= 0.05;

            if ($hasProject('government_support_agency')) {
                $factor -= 0.025;
            }

            if ($hasProject('bureau_of_domestic_affairs')) {
                $factor -= 0.0125;
            }
        }

        return max(0.0, $factor);
    }

    private function newPlayerBonus(int $cityCount): float
    {
        return 1 + max(1 - (($cityCount - 1) * 0.05), 0);
    }

    private function foodConsumption(City $city): float
    {
        $basePopulation = (float) $city->infrastructure * 100;
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays(now()));

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
