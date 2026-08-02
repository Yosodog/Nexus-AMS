<?php

namespace App\Services;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityContextUnavailable;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\City;
use App\Models\GrowthCircleDistribution;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationProfitabilitySnapshot;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketValuationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NationProfitabilityService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly MarketValuationService $marketValuationService,
        private readonly RadiationService $radiationService,
        private readonly EconomyCalculator $calculator,
    ) {}

    /**
     * @return array{food: float, uranium: float}|null
     */
    public function getDailyResourceShortfall(Nation $nation): ?array
    {
        $snapshot = $this->currentSnapshotQuery($nation)->first();

        if ($snapshot === null) {
            return null;
        }

        $perDay = $snapshot->resource_profit_per_day ?? [];

        return [
            'food' => max(0.0, -(float) ($perDay['food'] ?? 0.0)),
            'uranium' => max(0.0, -(float) ($perDay['uranium'] ?? 0.0)),
        ];
    }

    /**
     * @return array{coal: float, oil: float, uranium: float, iron: float, bauxite: float, lead: float, food: float}|null
     */
    public function getDailyGrowthCircleShortfalls(Nation $nation): ?array
    {
        $snapshot = $this->currentSnapshotQuery($nation)->first();

        if ($snapshot === null) {
            return null;
        }

        $perDay = $snapshot->resource_profit_per_day ?? [];

        return collect(GrowthCircleDistribution::distributionResourceKeys())
            ->mapWithKeys(fn (string $resource): array => [
                $resource => max(0.0, -(float) ($perDay[$resource] ?? 0.0)),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLeaderboard(): array
    {
        $allianceIds = $this->membershipService->getAllianceIds()->values()->all();
        $eligibleNationCount = Nation::query()
            ->whereIn('alliance_id', $allianceIds)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', 0)
            ->count();
        $snapshotQuery = NationProfitabilitySnapshot::query()
            ->where('model_version', EconomyRules::MODEL_VERSION);
        $cacheKey = sprintf(
            'nation_profitability_snapshots:v%d:%s:%s:%s:%d',
            EconomyRules::MODEL_VERSION,
            md5(json_encode($allianceIds)),
            (string) (clone $snapshotQuery)->max('updated_at'),
            (string) (clone $snapshotQuery)->count(),
            $eligibleNationCount
        );

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($allianceIds, $eligibleNationCount): array {
            $snapshots = NationProfitabilitySnapshot::query()
                ->where('model_version', EconomyRules::MODEL_VERSION)
                ->whereIn('alliance_id', $allianceIds)
                ->orderByDesc('converted_profit_per_day')
                ->orderBy('nation_id')
                ->get();
            $rows = $snapshots->map(function (NationProfitabilitySnapshot $snapshot): array {
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
            })->values()->all();

            foreach ($rows as $index => &$row) {
                $row['rank'] = $index + 1;
            }
            unset($row);

            $latestSnapshot = $snapshots->sortByDesc('calculated_at')->first();

            return [
                'generated_at' => $this->serializeTimestamp($latestSnapshot?->calculated_at),
                'price_basis' => $latestSnapshot?->price_basis ?? MarketPriceSet::BASIS,
                'market_prices_calculated_at' => data_get($latestSnapshot?->calculation_context, 'market.calculated_at'),
                'market_prices_stale' => (bool) data_get($latestSnapshot?->calculation_context, 'market.stale', false),
                'model_version' => EconomyRules::MODEL_VERSION,
                'missing_current_version_count' => max(0, $eligibleNationCount - count($rows)),
                'radiation_snapshot_id' => $latestSnapshot?->radiation_snapshot_id,
                'radiation_snapshot_at' => data_get($latestSnapshot?->calculation_context, 'radiation.snapshot_at'),
                'game_date' => data_get($latestSnapshot?->calculation_context, 'game_date'),
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
        $prices = $this->marketValuationService->current();
        $radiationSnapshot = $this->currentRadiationSnapshot();
        $calculatorNation = $this->makeCalculatorNationFromGraphQL($nationFromApi);
        $this->assertFreshEconomyContext($calculatorNation);
        $result = $this->calculator->calculateNation($calculatorNation, $radiationSnapshot, $prices);

        if ($this->isEligibleGraphQLNation($nationFromApi) && $prices->snapshotId !== null) {
            $storedNation = Nation::updateFromAPI($nationFromApi)->load(['cities', 'military']);
            $this->assertFreshEconomyContext($storedNation);
            $storedResult = $this->calculator->calculateNation($storedNation, $radiationSnapshot, $prices);
            $snapshot = $this->storeSnapshotForNation($storedNation, $storedResult, $radiationSnapshot, $prices);
            $result['stored_snapshot_updated'] = $snapshot !== null;
        } else {
            $result['stored_snapshot_updated'] = false;
        }

        $result = $this->withCalculationMetadata($result, $prices, $radiationSnapshot);
        $result['calculation_context'] = $this->calculationContext(
            $calculatorNation,
            $prices,
            $radiationSnapshot
        );
        $result['source'] = 'live';

        return $result;
    }

    /**
     * Compatibility projection for callers that still accept one price per resource.
     *
     * @return array<string, float>
     */
    public function getResourcePrices(): array
    {
        return $this->marketValuationService->current()->liquidationPricesWithMoney();
    }

    public function getMarketPriceSet(?int $snapshotId = null): MarketPriceSet
    {
        return $this->marketValuationService->current($snapshotId);
    }

    public function getCurrentRadiationSnapshot(): RadiationSnapshot
    {
        return $this->currentRadiationSnapshot();
    }

    public function assertCalculationContextAvailable(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot,
        bool $requireFreshWorldSnapshot = false
    ): void {
        $this->assertUsableWorldSnapshot($radiationSnapshot, $requireFreshWorldSnapshot);
        $this->assertFreshEconomyContext($nation);
    }

    public function assertPersistedCalculationContextIsCurrent(Nation $nation): void
    {
        $current = Nation::query()
            ->select([
                'id',
                'alliance_id',
                'alliance_position',
                'color',
                'treasure_income_modifier',
                'color_turn_bonus',
                'economy_context_synced_at',
            ])
            ->lockForUpdate()
            ->find($nation->id);

        if ($current === null) {
            throw new ProfitabilityContextUnavailable(
                "Economy context is unavailable for nation {$nation->id}."
            );
        }

        $this->assertFreshEconomyContext($current);

        if (
            ! $this->economyContextSourcesMatch($nation, $current)
            || ! Carbon::parse($nation->economy_context_synced_at)
                ->equalTo(Carbon::parse($current->economy_context_synced_at))
            || abs(
                (float) $nation->treasure_income_modifier
                - (float) $current->treasure_income_modifier
            ) > 0.000001
            || (int) $nation->color_turn_bonus !== (int) $current->color_turn_bonus
        ) {
            throw new ProfitabilityContextUnavailable(
                "Economy context changed while calculating nation {$nation->id}."
            );
        }
    }

    /**
     * @param  array<string, float>|MarketPriceSet|null  $resourcePrices
     * @return array<string, mixed>
     */
    public function calculateCityRecommendationMetrics(
        Nation $nation,
        City $city,
        ?RadiationSnapshot $radiationSnapshot = null,
        array|MarketPriceSet|null $resourcePrices = null
    ): array {
        return $this->calculator->calculateCityMetrics(
            $nation,
            $city,
            $radiationSnapshot,
            $this->normalizePrices($resourcePrices)
        );
    }

    public function refreshAllianceSnapshots(?int $marketPriceSnapshotId = null): int
    {
        $prices = $this->marketValuationService->current($marketPriceSnapshotId);
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
                'color',
                'domestic_policy',
                'num_cities',
                'project_bits',
                'offensive_wars_count',
                'defensive_wars_count',
                'ground_capacity_research',
                'ground_cost_research',
                'air_capacity_research',
                'air_cost_research',
                'naval_capacity_research',
                'naval_cost_research',
                'treasure_income_modifier',
                'color_turn_bonus',
                'economy_context_synced_at',
            ])
            ->with([
                'cities:id,nation_id,name,date,nuke_date,infrastructure,land,powered,oil_power,wind_power,coal_power,nuclear_power,coal_mine,oil_well,uranium_mine,barracks,farm,police_station,hospital,recycling_center,subway,supermarket,bank,shopping_mall,stadium,lead_mine,iron_mine,bauxite_mine,oil_refinery,aluminum_refinery,steel_mill,munitions_factory,factory,hangar,drydock',
                'military:nation_id,soldiers,tanks,aircraft,ships,missiles,nukes,spies',
            ])
            ->whereIn('alliance_id', $allianceIds)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', 0)
            ->get();

        if ($eligibleNations->isEmpty()) {
            throw new RuntimeException('No eligible nations were returned for the profitability refresh.');
        }

        $eligibleNations->each(fn (Nation $nation) => $this->assertFreshEconomyContext($nation));

        $calculations = [];

        foreach ($eligibleNations as $nation) {
            $calculations[(int) $nation->id] = $this->calculator->calculateNation(
                $nation,
                $radiationSnapshot,
                $prices
            );
        }

        $eligibleIds = $eligibleNations->pluck('id')->all();

        DB::transaction(function () use (
            $eligibleNations,
            $calculations,
            $radiationSnapshot,
            $prices,
            $eligibleIds
        ): void {
            foreach ($eligibleNations as $nation) {
                $this->storeSnapshotForNation(
                    $nation,
                    $calculations[(int) $nation->id],
                    $radiationSnapshot,
                    $prices
                );
            }

            NationProfitabilitySnapshot::query()
                ->where('model_version', EconomyRules::MODEL_VERSION)
                ->whereNotIn('nation_id', $eligibleIds)
                ->delete();
        }, 3);

        return count($eligibleIds);
    }

    public function refreshStoredSnapshotForNationId(
        int $nationId,
        ?int $marketPriceSnapshotId = null,
        ?int $radiationSnapshotId = null
    ): ?NationProfitabilitySnapshot {
        $nation = Nation::query()->with(['cities', 'military'])->find($nationId);

        if ($nation === null || ! $this->isEligibleNation($nation)) {
            $this->deleteStoredSnapshotForNationId($nationId);

            return null;
        }

        $prices = $this->marketValuationService->current($marketPriceSnapshotId);
        $radiationSnapshot = $radiationSnapshotId !== null
            ? RadiationSnapshot::query()->find($radiationSnapshotId)
            : $this->currentRadiationSnapshot();

        if ($radiationSnapshotId !== null && $radiationSnapshot === null) {
            throw new ProfitabilityContextUnavailable("World snapshot {$radiationSnapshotId} is unavailable.");
        }

        $this->assertUsableWorldSnapshot($radiationSnapshot);
        $this->assertFreshEconomyContext($nation);
        $result = $this->calculator->calculateNation($nation, $radiationSnapshot, $prices);

        return $this->storeSnapshotForNation($nation, $result, $radiationSnapshot, $prices);
    }

    public function deleteStoredSnapshotForNationId(int $nationId): void
    {
        NationProfitabilitySnapshot::query()->where('nation_id', $nationId)->delete();
    }

    public function shouldStoreSnapshotForNation(Nation $nation): bool
    {
        return $this->isEligibleNation($nation);
    }

    /**
     * @param  array<string, float>|MarketPriceSet|null  $resourcePrices
     * @return array<string, mixed>
     */
    public function calculateNationProfitability(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot = null,
        array|MarketPriceSet|null $resourcePrices = null
    ): array {
        return $this->calculator->calculateNation(
            $nation,
            $radiationSnapshot,
            $this->normalizePrices($resourcePrices)
        );
    }

    private function storeSnapshotForNation(
        Nation $nation,
        array $result,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices
    ): ?NationProfitabilitySnapshot {
        if (! $this->isEligibleNation($nation)) {
            $this->deleteStoredSnapshotForNationId((int) $nation->id);

            return null;
        }

        if ($prices->snapshotId === null) {
            throw new ProfitabilityPricingUnavailable('A market price snapshot is required to store profitability.');
        }

        $this->assertUsableWorldSnapshot($radiationSnapshot);
        $this->assertFreshEconomyContext($nation);

        $attributes = [
            'alliance_id' => $nation->alliance_id,
            'radiation_snapshot_id' => $radiationSnapshot?->id,
            'market_price_snapshot_id' => $prices->snapshotId,
            'model_version' => EconomyRules::MODEL_VERSION,
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
            'price_basis' => $prices->basis,
            'calculation_context' => $this->calculationContext($nation, $prices, $radiationSnapshot),
            'calculated_at' => now(),
        ];

        return DB::transaction(function () use ($nation, $prices, $radiationSnapshot, $attributes): NationProfitabilitySnapshot {
            $this->assertPersistedCalculationContextIsCurrent($nation);
            $existing = NationProfitabilitySnapshot::query()
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();

            if ($this->hasNewerInputs($existing, $prices->snapshotId, $radiationSnapshot?->id)) {
                return $existing;
            }

            return NationProfitabilitySnapshot::query()->updateOrCreate(
                ['nation_id' => $nation->id],
                $attributes
            );
        }, 3);
    }

    private function hasNewerInputs(
        ?NationProfitabilitySnapshot $existing,
        ?int $marketPriceSnapshotId,
        ?int $radiationSnapshotId
    ): bool {
        if ($existing === null || $existing->model_version !== EconomyRules::MODEL_VERSION) {
            return false;
        }

        return ((int) $existing->market_price_snapshot_id > 0
                && ($marketPriceSnapshotId === null || $marketPriceSnapshotId < (int) $existing->market_price_snapshot_id))
            || ((int) $existing->radiation_snapshot_id > 0
                && ($radiationSnapshotId === null || $radiationSnapshotId < (int) $existing->radiation_snapshot_id));
    }

    private function currentSnapshotQuery(Nation $nation)
    {
        return NationProfitabilitySnapshot::query()
            ->where('nation_id', $nation->id)
            ->where('model_version', EconomyRules::MODEL_VERSION)
            ->where('calculated_at', '>=', now()->subHours(48))
            ->latest('calculated_at');
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
        $storedContext = Nation::query()->find($nation->id, [
            'id',
            'alliance_id',
            'alliance_position',
            'color',
            'treasure_income_modifier',
            'color_turn_bonus',
            'economy_context_synced_at',
        ]);

        if ($storedContext !== null && ! $this->graphQLNationMatchesEconomyContextSource($nation, $storedContext)) {
            throw new ProfitabilityContextUnavailable(
                "Economy context does not match the current source data for nation {$nation->id}."
            );
        }

        $calculatorNation = new Nation([
            'id' => $nation->id,
            'alliance_id' => $nation->alliance_id,
            'alliance_position' => $nation->alliance_position,
            'vacation_mode_turns' => $nation->vacation_mode_turns ?? 0,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'continent' => $nation->continent,
            'color' => $nation->color,
            'domestic_policy' => $nation->domestic_policy,
            'num_cities' => $nation->num_cities ?? 0,
            'project_bits' => $nation->project_bits ?? '0',
            'offensive_wars_count' => $nation->offensive_wars_count ?? 0,
            'defensive_wars_count' => $nation->defensive_wars_count ?? 0,
            'ground_capacity_research' => $nation->ground_capacity_research,
            'ground_cost_research' => $nation->ground_cost_research,
            'air_capacity_research' => $nation->air_capacity_research,
            'air_cost_research' => $nation->air_cost_research,
            'naval_capacity_research' => $nation->naval_capacity_research,
            'naval_cost_research' => $nation->naval_cost_research,
            'treasure_income_modifier' => $storedContext?->treasure_income_modifier,
            'color_turn_bonus' => $storedContext?->color_turn_bonus,
            'economy_context_synced_at' => $storedContext?->economy_context_synced_at,
        ]);
        $cities = new EloquentCollection;

        foreach ($nation->cities ?? [] as $city) {
            $cities->push(new City(get_object_vars($city)));
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

    private function currentRadiationSnapshot(): RadiationSnapshot
    {
        $snapshot = $this->radiationService->latestOrRefresh();
        $this->assertUsableWorldSnapshot($snapshot, requireFresh: true);

        return $snapshot;
    }

    private function normalizePrices(array|MarketPriceSet|null $prices): MarketPriceSet
    {
        if ($prices instanceof MarketPriceSet) {
            return $prices;
        }

        if (is_array($prices)) {
            return MarketPriceSet::symmetric($prices);
        }

        return $this->marketValuationService->current();
    }

    /**
     * @return array<string, mixed>
     */
    private function withCalculationMetadata(
        array $result,
        MarketPriceSet $prices,
        ?RadiationSnapshot $radiationSnapshot
    ): array {
        $result['model_version'] = EconomyRules::MODEL_VERSION;
        $result['price_basis'] = $prices->basis;
        $result['market_prices_calculated_at'] = $prices->calculatedAt?->toIso8601String();
        $result['market_prices_stale'] = $prices->stale;
        $result['market_price_fallback_resources'] = $prices->fallbackResources;
        $result['radiation_snapshot_id'] = $radiationSnapshot?->id;
        $result['radiation_snapshot_at'] = $this->serializeTimestamp($radiationSnapshot?->snapshot_at);
        $result['game_date'] = $radiationSnapshot?->game_date?->toDateString();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function calculationContext(
        Nation $nation,
        MarketPriceSet $prices,
        ?RadiationSnapshot $radiationSnapshot
    ): array {
        return [
            'model_version' => EconomyRules::MODEL_VERSION,
            'market' => [
                'snapshot_id' => $prices->snapshotId,
                'calculated_at' => $prices->calculatedAt?->toIso8601String(),
                'stale' => $prices->stale,
                'fallback_resources' => $prices->fallbackResources,
            ],
            'radiation' => [
                'snapshot_id' => $radiationSnapshot?->id,
                'snapshot_at' => $this->serializeTimestamp($radiationSnapshot?->snapshot_at),
                'game_date' => $radiationSnapshot?->game_date?->toDateString(),
            ],
            'world_snapshot_id' => $radiationSnapshot?->id,
            'game_date' => $radiationSnapshot?->game_date?->toDateString(),
            'season_month' => $radiationSnapshot?->game_date?->month,
            'treasure_income_modifier' => (float) ($nation->treasure_income_modifier ?? 0.0),
            'color_turn_bonus' => (int) ($nation->color_turn_bonus ?? 0),
            'economy_context_synced_at' => $this->serializeTimestamp($nation->economy_context_synced_at),
            'economy_context_stale' => false,
            'military_research' => [
                'ground_capacity' => $nation->ground_capacity_research,
                'ground_cost' => $nation->ground_cost_research,
                'air_capacity' => $nation->air_capacity_research,
                'air_cost' => $nation->air_cost_research,
                'naval_capacity' => $nation->naval_capacity_research,
                'naval_cost' => $nation->naval_cost_research,
            ],
        ];
    }

    private function assertUsableWorldSnapshot(
        ?RadiationSnapshot $snapshot,
        bool $requireFresh = false
    ): void {
        if ($snapshot === null || $snapshot->game_date === null) {
            throw new ProfitabilityContextUnavailable('A world snapshot with an authoritative game date is required.');
        }

        if (
            $requireFresh
            && ($snapshot->snapshot_at === null
                || $snapshot->snapshot_at->lt(now()->subHours(EconomyRules::WORLD_SNAPSHOT_MAX_AGE_HOURS)))
        ) {
            throw new ProfitabilityContextUnavailable('The latest world snapshot is too old to use.');
        }
    }

    private function assertFreshEconomyContext(Nation $nation): void
    {
        if (
            $nation->treasure_income_modifier === null
            || $nation->color_turn_bonus === null
            || $nation->economy_context_synced_at === null
        ) {
            throw new ProfitabilityContextUnavailable(
                "Economy context is unavailable for nation {$nation->id}."
            );
        }

        if (
            Carbon::parse($nation->economy_context_synced_at)
                ->lt(now()->subHours(EconomyRules::ECONOMY_CONTEXT_MAX_AGE_HOURS))
        ) {
            throw new ProfitabilityContextUnavailable(
                "Economy context is stale for nation {$nation->id}."
            );
        }
    }

    private function graphQLNationMatchesEconomyContextSource(
        GraphQLNation $nation,
        Nation $storedContext
    ): bool {
        return $this->nullableInteger($nation->alliance_id) === $this->nullableInteger($storedContext->alliance_id)
            && (string) $nation->alliance_position === (string) $storedContext->alliance_position
            && strtolower((string) $nation->color) === strtolower((string) $storedContext->color);
    }

    private function economyContextSourcesMatch(Nation $left, Nation $right): bool
    {
        return $this->nullableInteger($left->alliance_id) === $this->nullableInteger($right->alliance_id)
            && (string) $left->alliance_position === (string) $right->alliance_position
            && strtolower((string) $left->color) === strtolower((string) $right->color);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function serializeTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return Carbon::parse($timestamp)->toIso8601String();
    }
}
