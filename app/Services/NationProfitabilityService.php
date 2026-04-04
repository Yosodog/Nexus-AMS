<?php

namespace App\Services;

use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationProfitabilitySnapshot;
use App\Models\RadiationSnapshot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class NationProfitabilityService
{
    private const RESOURCE_KEYS = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly TradePriceService $tradePriceService,
        private readonly RadiationService $radiationService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLeaderboard(): array
    {
        $allianceIds = $this->membershipService->getAllianceIds()->values()->all();
        $cacheKey = sprintf(
            'nation_profitability_snapshots:%s:%s:%s',
            md5(json_encode($allianceIds)),
            (string) NationProfitabilitySnapshot::query()->max('updated_at'),
            (string) NationProfitabilitySnapshot::query()->count()
        );

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($allianceIds) {
            $rows = NationProfitabilitySnapshot::query()
                ->whereIn('alliance_id', $allianceIds)
                ->orderByDesc('converted_profit_per_day')
                ->orderBy('nation_id')
                ->get()
                ->map(function (NationProfitabilitySnapshot $snapshot): array {
                    return [
                        'nation_id' => $snapshot->nation_id,
                        'nation_url' => sprintf('https://politicsandwar.com/nation/id=%d', $snapshot->nation_id),
                        'leader_name' => $snapshot->leader_name,
                        'nation_name' => $snapshot->nation_name,
                        'cities' => $snapshot->cities,
                        'converted_profit_per_day' => $snapshot->converted_profit_per_day,
                        'money_profit_per_day' => $snapshot->money_profit_per_day,
                        'resource_profit_per_day' => $snapshot->resource_profit_per_day ?? [],
                        'city_income_per_day' => $snapshot->city_income_per_day,
                        'power_cost_per_day' => $snapshot->power_cost_per_day,
                        'food_cost_per_day' => $snapshot->food_cost_per_day,
                        'military_upkeep_per_day' => $snapshot->military_upkeep_per_day,
                    ];
                })
                ->values()
                ->all();

            foreach ($rows as $index => &$row) {
                $row['rank'] = $index + 1;
            }
            unset($row);

            $latestSnapshot = NationProfitabilitySnapshot::query()
                ->with('radiationSnapshot:id,snapshot_at')
                ->latest('calculated_at')
                ->latest('id')
                ->first();

            return [
                'generated_at' => $this->serializeTimestamp($latestSnapshot?->calculated_at),
                'price_basis' => $latestSnapshot?->price_basis ?? '24h average trade prices',
                'radiation_snapshot_id' => $latestSnapshot?->radiation_snapshot_id,
                'radiation_snapshot_at' => $this->serializeTimestamp($latestSnapshot?->radiationSnapshot?->snapshot_at),
                'rows' => $rows,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateLiveNationProfitabilityById(int $nationId): array
    {
        $nationFromApi = NationQueryService::getNationAndCitiesById($nationId);
        $resourcePrices = $this->resourcePrices();
        $radiationSnapshot = $this->currentRadiationSnapshot();
        $calculatorNation = $this->makeCalculatorNationFromGraphQL($nationFromApi);
        $result = $this->calculateNationProfitability($calculatorNation, $radiationSnapshot, $resourcePrices);

        if ($this->isEligibleGraphQLNation($nationFromApi)) {
            $storedNation = Nation::updateFromAPI($nationFromApi)->load([
                'cities',
                'military',
            ]);

            $snapshot = $this->storeSnapshotForNation(
                $storedNation,
                $result,
                $radiationSnapshot
            );

            $result['stored_snapshot_updated'] = $snapshot !== null;
        } else {
            $result['stored_snapshot_updated'] = false;
        }

        $result['source'] = 'live';
        $result['price_basis'] = '24h average trade prices';
        $result['radiation_snapshot_id'] = $radiationSnapshot?->id;
        $result['radiation_snapshot_at'] = $this->serializeTimestamp($radiationSnapshot?->snapshot_at);

        return $result;
    }

    /**
     * @return array<string, float>
     */
    public function getResourcePrices(): array
    {
        return $this->resourcePrices();
    }

    public function getCurrentRadiationSnapshot(): ?RadiationSnapshot
    {
        return $this->currentRadiationSnapshot();
    }

    /**
     * @param  array<string, float>|null  $resourcePrices
     * @return array<string, mixed>
     */
    public function calculateCityRecommendationMetrics(
        Nation $nation,
        City $city,
        ?RadiationSnapshot $radiationSnapshot = null,
        ?array $resourcePrices = null
    ): array {
        $resourcePrices ??= $this->resourcePrices();
        $cityResult = $this->calculateCityProfitability($nation, $city, $radiationSnapshot, $resourcePrices);
        $hasProject = fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
        $resourceProfitPerDay = collect($cityResult['resource_profit_per_turn'])->mapWithKeys(
            fn (float $value, string $resource): array => [$resource => round($value, 2)]
        )->all();

        return [
            'converted_profit_per_day' => round(
                $this->convertResourcesToMoney($cityResult['resource_profit_per_turn'], $resourcePrices),
                2
            ),
            'money_profit_per_day' => round((float) ($cityResult['resource_profit_per_turn']['money'] ?? 0.0), 2),
            'resource_profit_per_day' => $resourceProfitPerDay,
            'city_income_per_day' => round($cityResult['city_income_per_turn'], 2),
            'power_cost_per_day' => round($cityResult['power_cost_per_turn'], 2),
            'food_cost_per_day' => round($cityResult['food_cost_per_turn'], 2),
            'disease' => round($this->disease($city, $hasProject), 2),
            'pollution' => $this->pollution($city, $hasProject),
            'crime' => round($this->crime($city, $hasProject), 2),
            'commerce' => $this->commerce($city, $hasProject),
            'population' => $this->population($city, $hasProject),
            'powered' => (bool) $city->powered && $this->poweredInfra($city) >= (float) $city->infrastructure,
        ];
    }

    private function serializeTimestamp(?Carbon $timestamp): ?string
    {
        return $timestamp?->toIso8601String();
    }

    public function refreshAllianceSnapshots(): int
    {
        $resourcePrices = $this->resourcePrices();
        $radiationSnapshot = $this->currentRadiationSnapshot();
        $allianceIds = $this->membershipService->getAllianceIds()->values()->all();

        $eligibleNations = Nation::query()
            ->select([
                'id',
                'alliance_id',
                'alliance_position',
                'vacation_mode_turns',
                'leader_name',
                'nation_name',
                'continent',
                'domestic_policy',
                'num_cities',
                'project_bits',
                'offensive_wars_count',
                'defensive_wars_count',
            ])
            ->with([
                'cities:id,nation_id,name,date,nuke_date,infrastructure,land,powered,oil_power,wind_power,coal_power,nuclear_power,coal_mine,oil_well,uranium_mine,barracks,farm,police_station,hospital,recycling_center,subway,supermarket,bank,shopping_mall,stadium,lead_mine,iron_mine,bauxite_mine,oil_refinery,aluminum_refinery,steel_mill,munitions_factory,factory,hangar,drydock',
                'military:nation_id,soldiers,tanks,aircraft,ships,missiles,nukes,spies',
            ])
            ->whereIn('alliance_id', $allianceIds)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', '=', 0)
            ->get();

        foreach ($eligibleNations as $nation) {
            $result = $this->calculateNationProfitability($nation, $radiationSnapshot, $resourcePrices);
            $this->storeSnapshotForNation($nation, $result, $radiationSnapshot);
        }

        $eligibleIds = $eligibleNations->pluck('id')->all();

        NationProfitabilitySnapshot::query()
            ->when(
                empty($eligibleIds),
                fn ($query) => $query,
                fn ($query) => $query->whereNotIn('nation_id', $eligibleIds)
            )
            ->delete();

        return count($eligibleIds);
    }

    public function refreshStoredSnapshotForNationId(int $nationId): ?NationProfitabilitySnapshot
    {
        $nation = Nation::query()
            ->with([
                'cities',
                'military',
            ])
            ->find($nationId);

        if (! $nation || ! $this->isEligibleNation($nation)) {
            NationProfitabilitySnapshot::query()
                ->where('nation_id', $nationId)
                ->delete();

            return null;
        }

        $radiationSnapshot = $this->currentRadiationSnapshot();
        $result = $this->calculateNationProfitability(
            $nation,
            $radiationSnapshot,
            $this->resourcePrices()
        );

        return $this->storeSnapshotForNation($nation, $result, $radiationSnapshot);
    }

    public function deleteStoredSnapshotForNationId(int $nationId): void
    {
        NationProfitabilitySnapshot::query()
            ->where('nation_id', $nationId)
            ->delete();
    }

    public function shouldStoreSnapshotForNation(Nation $nation): bool
    {
        return $this->isEligibleNation($nation);
    }

    /**
     * @param  array<string, float>|null  $resourcePrices
     * @return array<string, mixed>
     */
    public function calculateNationProfitability(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot = null,
        ?array $resourcePrices = null
    ): array {
        $resourcePrices ??= $this->resourcePrices();
        $perTurn = $this->emptyResourceBuffer();
        $components = [
            'city_income_per_turn' => 0.0,
            'power_cost_per_turn' => 0.0,
            'food_cost_per_turn' => 0.0,
            'military_upkeep_per_turn' => 0.0,
        ];

        foreach ($nation->cities as $city) {
            $cityProfitability = $this->calculateCityProfitability($nation, $city, $radiationSnapshot, $resourcePrices);
            $perTurn = $this->sumResourceBuffers($perTurn, $cityProfitability['resource_profit_per_turn']);
            $components['city_income_per_turn'] += $cityProfitability['city_income_per_turn'];
            $components['power_cost_per_turn'] += $cityProfitability['power_cost_per_turn'];
            $components['food_cost_per_turn'] += $cityProfitability['food_cost_per_turn'];
        }

        $militaryUpkeep = $this->calculateMilitaryUpkeepPerTurn($nation, $resourcePrices);
        $perTurn = $this->sumResourceBuffers($perTurn, $militaryUpkeep['resource_profit_per_turn']);
        $components['military_upkeep_per_turn'] += $militaryUpkeep['military_upkeep_per_turn'];

        $perDay = collect($perTurn)->mapWithKeys(
            fn (float $value, string $resource): array => [$resource => round($value, 2)]
        )->all();

        return [
            'nation_id' => $nation->id,
            'nation_url' => sprintf('https://politicsandwar.com/nation/id=%d', $nation->id),
            'leader_name' => (string) $nation->leader_name,
            'nation_name' => (string) $nation->nation_name,
            'cities' => (int) $nation->num_cities,
            'converted_profit_per_day' => round($this->convertResourcesToMoney($perTurn, $resourcePrices), 2),
            'money_profit_per_day' => round((float) ($perTurn['money'] ?? 0.0), 2),
            'resource_profit_per_day' => $perDay,
            'city_income_per_day' => round($components['city_income_per_turn'], 2),
            'power_cost_per_day' => round($components['power_cost_per_turn'], 2),
            'food_cost_per_day' => round($components['food_cost_per_turn'], 2),
            'military_upkeep_per_day' => round($components['military_upkeep_per_turn'], 2),
        ];
    }

    private function storeSnapshotForNation(
        Nation $nation,
        array $result,
        ?RadiationSnapshot $radiationSnapshot
    ): ?NationProfitabilitySnapshot {
        if (! $this->isEligibleNation($nation)) {
            $this->deleteStoredSnapshotForNationId((int) $nation->id);

            return null;
        }

        return NationProfitabilitySnapshot::query()->updateOrCreate(
            ['nation_id' => $nation->id],
            [
                'alliance_id' => $nation->alliance_id,
                'radiation_snapshot_id' => $radiationSnapshot?->id,
                'leader_name' => $result['leader_name'],
                'nation_name' => $result['nation_name'],
                'cities' => $result['cities'],
                'converted_profit_per_day' => $result['converted_profit_per_day'],
                'money_profit_per_day' => $result['money_profit_per_day'],
                'city_income_per_day' => $result['city_income_per_day'],
                'power_cost_per_day' => $result['power_cost_per_day'],
                'food_cost_per_day' => $result['food_cost_per_day'],
                'military_upkeep_per_day' => $result['military_upkeep_per_day'],
                'resource_profit_per_day' => $result['resource_profit_per_day'],
                'price_basis' => '24h average trade prices',
                'calculated_at' => now(),
            ]
        );
    }

    private function isEligibleNation(Nation $nation): bool
    {
        return $this->membershipService->contains($nation->alliance_id)
            && $nation->alliance_position !== 'APPLICANT'
            && (int) ($nation->vacation_mode_turns ?? 0) === 0;
    }

    private function isEligibleGraphQLNation(GraphQLNation $nation): bool
    {
        return $this->membershipService->contains($nation->alliance_id)
            && $nation->alliance_position !== 'APPLICANT'
            && (int) ($nation->vacation_mode_turns ?? 0) === 0;
    }

    private function makeCalculatorNationFromGraphQL(GraphQLNation $nation): Nation
    {
        $calculatorNation = new Nation([
            'id' => $nation->id,
            'alliance_id' => $nation->alliance_id,
            'alliance_position' => $nation->alliance_position,
            'vacation_mode_turns' => $nation->vacation_mode_turns ?? 0,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'continent' => $nation->continent,
            'domestic_policy' => $nation->domestic_policy,
            'num_cities' => $nation->num_cities ?? 0,
            'project_bits' => $nation->project_bits ?? '0',
            'offensive_wars_count' => $nation->offensive_wars_count ?? 0,
            'defensive_wars_count' => $nation->defensive_wars_count ?? 0,
        ]);

        $cities = new EloquentCollection;

        foreach ($nation->cities ?? [] as $city) {
            $cities->push(new City([
                'id' => $city->id,
                'nation_id' => $city->nation_id,
                'name' => $city->name,
                'date' => $city->date,
                'nuke_date' => $city->nuke_date,
                'infrastructure' => $city->infrastructure,
                'land' => $city->land,
                'powered' => $city->powered,
                'oil_power' => $city->oil_power,
                'wind_power' => $city->wind_power,
                'coal_power' => $city->coal_power,
                'nuclear_power' => $city->nuclear_power,
                'coal_mine' => $city->coal_mine,
                'oil_well' => $city->oil_well,
                'uranium_mine' => $city->uranium_mine,
                'barracks' => $city->barracks,
                'farm' => $city->farm,
                'police_station' => $city->police_station,
                'hospital' => $city->hospital,
                'recycling_center' => $city->recycling_center,
                'subway' => $city->subway,
                'supermarket' => $city->supermarket,
                'bank' => $city->bank,
                'shopping_mall' => $city->shopping_mall,
                'stadium' => $city->stadium,
                'lead_mine' => $city->lead_mine,
                'iron_mine' => $city->iron_mine,
                'bauxite_mine' => $city->bauxite_mine,
                'oil_refinery' => $city->oil_refinery,
                'aluminum_refinery' => $city->aluminum_refinery,
                'steel_mill' => $city->steel_mill,
                'munitions_factory' => $city->munitions_factory,
                'factory' => $city->factory,
                'hangar' => $city->hangar,
                'drydock' => $city->drydock,
            ]));
        }

        $calculatorNation->setRelation('cities', $cities);
        $calculatorNation->setRelation('military', new NationMilitary([
            'nation_id' => $nation->id,
            'soldiers' => $nation->soldiers ?? 0,
            'tanks' => $nation->tanks ?? 0,
            'aircraft' => $nation->aircraft ?? 0,
            'ships' => $nation->ships ?? 0,
            'missiles' => $nation->missiles ?? 0,
            'nukes' => $nation->nukes ?? 0,
            'spies' => $nation->spies ?? 0,
        ]));

        return $calculatorNation;
    }

    private function currentRadiationSnapshot(): ?RadiationSnapshot
    {
        return $this->radiationService->latest() ?? $this->radiationService->refresh();
    }

    /**
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>
     */
    private function calculateCityProfitability(
        Nation $nation,
        City $city,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): array {
        $profit = $this->emptyResourceBuffer();
        $hasProject = fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
        $powered = (bool) $city->powered && $this->poweredInfra($city) >= (float) $city->infrastructure;
        $remainingPoweredInfra = (int) ceil((float) $city->infrastructure);
        $powerCostPerTurn = 0.0;

        foreach ($this->rawBuildingMap() as $building => $resource) {
            $count = (int) ($city->{$building} ?? 0);
            if ($count <= 0) {
                continue;
            }

            $profit['money'] -= $this->buildingMoneyUpkeep($building, $hasProject) * $count;
            $profit[$resource] += $this->resourceProduction(
                $resource,
                (float) $city->land,
                $count,
                (string) $nation->continent,
                $hasProject,
                $radiationSnapshot
            );
        }

        if ($powered) {
            foreach (['coal_power', 'oil_power', 'nuclear_power', 'wind_power'] as $powerPlant) {
                $count = (int) ($city->{$powerPlant} ?? 0);
                for ($index = 0; $index < $count; $index++) {
                    $moneyUpkeep = $this->buildingMoneyUpkeep($powerPlant, $hasProject);
                    $profit['money'] -= $moneyUpkeep;
                    $powerCostPerTurn -= $moneyUpkeep;
                    $powerCostPerTurn += $this->applyPowerResourceUsage($profit, $powerPlant, $remainingPoweredInfra, $resourcePrices);
                    $remainingPoweredInfra -= $this->powerInfraMax($powerPlant);
                }
            }

            foreach ($this->poweredBuildingMap() as $building => $resource) {
                $count = (int) ($city->{$building} ?? 0);
                if ($count <= 0) {
                    continue;
                }

                $profit['money'] -= $this->buildingMoneyUpkeep($building, $hasProject) * $count;

                if ($resource === null) {
                    continue;
                }

                $production = $this->resourceProduction(
                    $resource,
                    (float) $city->land,
                    $count,
                    (string) $nation->continent,
                    $hasProject,
                    $radiationSnapshot
                );
                $profit[$resource] += $production;

                foreach ($this->manufacturedInputs($resource, $count, $hasProject) as $inputResource => $amount) {
                    $profit[$inputResource] -= $amount;
                }
            }

            $incomePerTurn = max(
                0.0,
                ((((($this->commerce($city, $hasProject) * 0.02) * 0.725) + 0.725)
                    * $this->population($city, $hasProject))
                    * $this->newPlayerBonus((int) $nation->num_cities))
                    * $this->grossModifier($nation, false)
            );
            $profit['money'] += $incomePerTurn;
        } else {
            $incomePerTurn = 0.0;
        }

        $foodConsumption = $this->foodConsumption($city);
        $profit['food'] -= $foodConsumption;

        return [
            'resource_profit_per_turn' => $profit,
            'city_income_per_turn' => $incomePerTurn,
            'power_cost_per_turn' => $powerCostPerTurn,
            'food_cost_per_turn' => -($foodConsumption * ($resourcePrices['food'] ?? 0.0)),
        ];
    }

    /**
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>
     */
    private function calculateMilitaryUpkeepPerTurn(Nation $nation, array $resourcePrices): array
    {
        $profit = $this->emptyResourceBuffer();
        $atWar = ((int) ($nation->offensive_wars_count ?? 0) + (int) ($nation->defensive_wars_count ?? 0)) > 0;
        $factor = $this->militaryUpkeepFactor($nation);
        $military = $nation->military;

        foreach ($this->militaryDefinitions() as $unit => $definition) {
            $count = (int) ($military?->{$unit} ?? 0);
            if ($count <= 0) {
                continue;
            }

            $warMultiplier = $atWar ? $definition['war_multiplier'] : 1.0;
            foreach ($definition['peace'] as $resource => $value) {
                $profit[$resource] -= $count * $value * $warMultiplier * $factor;
            }
        }

        return [
            'resource_profit_per_turn' => $profit,
            'military_upkeep_per_turn' => $this->convertResourcesToMoney($profit, $resourcePrices),
        ];
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function militaryDefinitions(): array
    {
        return [
            'soldiers' => [
                'peace' => ['money' => 1.25, 'food' => 1 / 750],
                'war_multiplier' => 1.5,
            ],
            'tanks' => [
                'peace' => ['money' => 50.0],
                'war_multiplier' => 1.5,
            ],
            'aircraft' => [
                'peace' => ['money' => 750.0],
                'war_multiplier' => 4 / 3,
            ],
            'ships' => [
                'peace' => ['money' => 3300.0],
                'war_multiplier' => 50 / 33,
            ],
            'missiles' => [
                'peace' => ['money' => 21000.0],
                'war_multiplier' => 1.5,
            ],
            'nukes' => [
                'peace' => ['money' => 35000.0],
                'war_multiplier' => 1.5,
            ],
            'spies' => [
                'peace' => ['money' => 2400.0],
                'war_multiplier' => 1.0,
            ],
        ];
    }

    private function grossModifier(Nation $nation, bool $noFood): float
    {
        $hasProject = fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
        $grossModifier = 1.0;

        if ($nation->domestic_policy === 'OPEN_MARKETS') {
            $grossModifier += 0.01;

            if ($hasProject('government_support_agency')) {
                $grossModifier += 0.005;
            }

            if ($hasProject('bureau_of_domestic_affairs')) {
                $grossModifier += 0.0025;
            }
        }

        if ($noFood) {
            $grossModifier -= 0.33;
        }

        return $grossModifier;
    }

    private function militaryUpkeepFactor(Nation $nation): float
    {
        $hasProject = fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
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

        return $factor;
    }

    private function newPlayerBonus(int $cityCount): float
    {
        return 1 + max(1 - (($cityCount - 1) * 0.05), 0);
    }

    private function commerce(City $city, callable $hasProject): int
    {
        $commerce = ((int) $city->subway * 8)
            + ((int) $city->shopping_mall * 8)
            + ((int) $city->stadium * 10)
            + ((int) $city->bank * 6)
            + ((int) $city->supermarket * 4);

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

    private function pollution(City $city, callable $hasProject): int
    {
        $pollution = 0;
        $pollution += (int) $city->coal_power * 8;
        $pollution += (int) $city->oil_power * 6;
        $pollution += (int) $city->nuclear_power * 20;
        $pollution += (int) $city->coal_mine * 12;
        $pollution += (int) $city->oil_well * 12;
        $pollution += (int) $city->uranium_mine * 20;
        $pollution += (int) $city->lead_mine * 12;
        $pollution += (int) $city->iron_mine * 12;
        $pollution += (int) $city->bauxite_mine * 12;
        $pollution += (int) $city->farm * ($hasProject('green_technologies') ? 1 : 2);
        $pollution += (int) $city->oil_refinery * ($hasProject('green_technologies') ? 24 : 32);
        $pollution += (int) $city->steel_mill * ($hasProject('green_technologies') ? 30 : 40);
        $pollution += (int) $city->aluminum_refinery * ($hasProject('green_technologies') ? 30 : 40);
        $pollution += (int) $city->munitions_factory * ($hasProject('green_technologies') ? 24 : 32);
        $pollution += (int) $city->subway * ($hasProject('green_technologies') ? -70 : -45);
        $pollution += (int) $city->shopping_mall * 2;
        $pollution += (int) $city->stadium * 5;
        $pollution += (int) $city->police_station;
        $pollution += (int) $city->hospital * 4;
        $pollution += (int) $city->recycling_center * ($hasProject('recycling_initiative') ? -75 : -70);
        $pollution += $this->nukePollution($city);

        return max(0, $pollution);
    }

    private function crime(City $city, callable $hasProject): float
    {
        $infraCents = (float) $city->infrastructure * 100;
        $policeModifier = ((int) $city->police_station) * ($hasProject('specialized_police_training_program') ? 3.5 : 2.5);

        return max(0.0, ((((103 - $this->commerce($city, $hasProject)) ** 2) + $infraCents) * 0.000009) - $policeModifier);
    }

    private function disease(City $city, callable $hasProject): float
    {
        $infraCents = (float) $city->infrastructure * 100;
        $landCents = (float) $city->land * 100;
        $hospitalModifier = ((int) $city->hospital) * ($hasProject('clinical_research_center') ? 3.5 : 2.5);

        return max(
            0.0,
            ((0.01 * (($infraCents / (($landCents * 0.01) + 0.001)) ** 2) - 25) * 0.01)
                + ($infraCents * 0.01 * 0.001)
                - $hospitalModifier
                + ($this->pollution($city, $hasProject) * 0.05)
        );
    }

    private function population(City $city, callable $hasProject): int
    {
        $infraCents = (float) $city->infrastructure * 100;
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays(now()));
        $ageBonus = 1 + log($ageDays) * 0.06666666666666667;
        $diseaseDeaths = ($this->disease($city, $hasProject) * 0.01) * $infraCents;
        $crimeDeaths = max(($this->crime($city, $hasProject) * 0.1) * $infraCents - 25, 0);

        return (int) round(max(10, ($infraCents - $diseaseDeaths - $crimeDeaths) * $ageBonus));
    }

    private function foodConsumption(City $city): float
    {
        $basePopulation = (float) $city->infrastructure * 100;
        $ageDays = max(1, Carbon::parse($city->date)->diffInDays(now()));

        return (($basePopulation ** 2) / 125_000_000)
            + (($basePopulation * (1 + (log($ageDays) / 15))) - $basePopulation) / 850;
    }

    private function nukePollution(City $city): int
    {
        if (! $city->nuke_date) {
            return 0;
        }

        $turnsSinceNuke = Carbon::parse($city->nuke_date)->diffInHours(now()) / 2;
        $maxTurns = 11 * 12;
        if ($turnsSinceNuke >= $maxTurns) {
            return 0;
        }

        return (int) max(0, (($maxTurns - $turnsSinceNuke) * 400) / $maxTurns);
    }

    private function poweredInfra(City $city): int
    {
        return ((int) $city->oil_power * 500)
            + ((int) $city->wind_power * 250)
            + ((int) $city->coal_power * 500)
            + ((int) $city->nuclear_power * 2000);
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
        ) / 7;

        return max(0.0, 1 - (($local + $globalAverage) / 1000));
    }

    private function seasonModifier(?string $continent): float
    {
        $month = now()->month;
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

    private function resourceProduction(
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
                    * $this->seasonModifier($continent)
                    * $radiation
            );

            return $this->scaledResourceProduction($base, $count, 20);
        }

        [$base, $cap, $project, $boost] = match ($resource) {
            'coal' => [3.0, 10, null, 1.0],
            'oil' => [3.0, 10, null, 1.0],
            'uranium' => [2.0, 5, 'uranium_enrichment_program', 2.0],
            'lead' => [3.0, 10, null, 1.0],
            'iron' => [3.0, 10, null, 1.0],
            'bauxite' => [3.0, 10, null, 1.0],
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
            'munitions' => [5, 6.0, 'arms_stockpile', 1.2, ['lead']],
            'steel' => [5, 3.0, 'iron_works', 1.36, ['iron', 'coal']],
            'aluminum' => [5, 3.0, 'bauxite_works', 1.36, ['bauxite']],
            default => [1, 0.0, null, 1.0, []],
        };

        if ($count <= 0 || empty($inputs)) {
            return [];
        }

        if ($project !== null && $hasProject($project)) {
            $baseInput *= $boost;
        }

        $inputAmount = $this->scaledResourceProduction($baseInput, $count, $cap);

        return collect($inputs)->mapWithKeys(fn (string $input): array => [$input => $inputAmount])->all();
    }

    private function scaledResourceProduction(float $base, int $count, int $cap): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        return $base * (1 + (0.5 * (($count - 1) / ($cap - 1)))) * $count;
    }

    /**
     * @return array<string, string>
     */
    private function rawBuildingMap(): array
    {
        return [
            'coal_mine' => 'coal',
            'oil_well' => 'oil',
            'uranium_mine' => 'uranium',
            'lead_mine' => 'lead',
            'iron_mine' => 'iron',
            'bauxite_mine' => 'bauxite',
            'farm' => 'food',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function poweredBuildingMap(): array
    {
        return [
            'oil_refinery' => 'gasoline',
            'steel_mill' => 'steel',
            'aluminum_refinery' => 'aluminum',
            'munitions_factory' => 'munitions',
            'subway' => null,
            'shopping_mall' => null,
            'stadium' => null,
            'bank' => null,
            'supermarket' => null,
            'police_station' => null,
            'hospital' => null,
            'recycling_center' => null,
            'barracks' => null,
            'factory' => null,
            'hangar' => null,
            'drydock' => null,
        ];
    }

    private function buildingMoneyUpkeep(string $building, callable $hasProject): float
    {
        return match ($building) {
            'coal_power' => 1200.0,
            'oil_power' => 1800.0,
            'nuclear_power' => 10500.0,
            'wind_power' => 500.0,
            'coal_mine' => 400.0,
            'oil_well' => 600.0,
            'uranium_mine' => 5000.0,
            'lead_mine' => 1500.0,
            'iron_mine' => 1600.0,
            'bauxite_mine' => 1600.0,
            'farm' => 300.0,
            'oil_refinery' => $hasProject('green_technologies') ? 3600.0 : 4000.0,
            'steel_mill' => $hasProject('green_technologies') ? 3600.0 : 4000.0,
            'aluminum_refinery' => $hasProject('green_technologies') ? 2250.0 : 2500.0,
            'munitions_factory' => $hasProject('green_technologies') ? 3150.0 : 3500.0,
            'subway' => 3250.0,
            'shopping_mall' => 5400.0,
            'stadium' => 12150.0,
            'bank' => 1800.0,
            'supermarket' => 600.0,
            'police_station' => 750.0,
            'hospital' => 1000.0,
            'recycling_center' => 2500.0,
            default => 0.0,
        };
    }

    /**
     * @param  array<string, float>  $profit
     * @param  array<string, float>  $resourcePrices
     */
    private function applyPowerResourceUsage(
        array &$profit,
        string $powerPlant,
        int $remainingPoweredInfra,
        array $resourcePrices
    ): float {
        [$resource, $baseInfra, $maxInfra, $amountPerLevel] = match ($powerPlant) {
            'coal_power' => ['coal', 100, 500, 1.2],
            'oil_power' => ['oil', 100, 500, 1.2],
            'nuclear_power' => ['uranium', 1000, 2000, 3.125],
            default => [null, 0, 0, 0.0],
        };

        if ($resource === null || $remainingPoweredInfra <= 0) {
            return 0.0;
        }

        $levels = $remainingPoweredInfra < $baseInfra
            ? 1
            : (int) ceil(min($remainingPoweredInfra, $maxInfra) / $baseInfra);
        $consumed = $levels * $amountPerLevel;
        $profit[$resource] -= $consumed;

        return -($consumed * ($resourcePrices[$resource] ?? 0.0));
    }

    private function powerInfraMax(string $powerPlant): int
    {
        return match ($powerPlant) {
            'coal_power', 'oil_power' => 500,
            'nuclear_power' => 2000,
            'wind_power' => 250,
            default => 0,
        };
    }

    /**
     * @return array<string, float>
     */
    private function resourcePrices(): array
    {
        $average = $this->tradePriceService->get24hAverage();

        return collect(self::RESOURCE_KEYS)->mapWithKeys(function (string $resource) use ($average): array {
            return [$resource => $resource === 'money' ? 1.0 : (float) ($average->{$resource} ?? 0.0)];
        })->all();
    }

    /**
     * @param  array<string, float>  $resources
     * @param  array<string, float>  $resourcePrices
     */
    private function convertResourcesToMoney(array $resources, array $resourcePrices): float
    {
        $converted = 0.0;

        foreach ($resources as $resource => $amount) {
            $converted += $amount * ($resourcePrices[$resource] ?? 0.0);
        }

        return $converted;
    }

    /**
     * @return array<string, float>
     */
    private function emptyResourceBuffer(): array
    {
        return array_fill_keys(self::RESOURCE_KEYS, 0.0);
    }

    /**
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
     * @return array<string, float>
     */
    private function sumResourceBuffers(array $left, array $right): array
    {
        foreach (self::RESOURCE_KEYS as $resource) {
            $left[$resource] = (float) ($left[$resource] ?? 0.0) + (float) ($right[$resource] ?? 0.0);
        }

        return $left;
    }
}
