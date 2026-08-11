<?php

namespace App\Services;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityContextUnavailable;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Models\MMRTier;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\RadiationSnapshot;
use App\Services\Economy\BuildOptimizer;
use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NationBuildRecommendationService
{
    private const CATEGORY_GROUPS = [
        'power' => ['coal_power', 'oil_power', 'wind_power', 'nuclear_power'],
        'raw_resource' => ['coal_mine', 'oil_well', 'uranium_mine', 'lead_mine', 'iron_mine', 'bauxite_mine', 'farm'],
        'manufacturing' => ['oil_refinery', 'aluminum_refinery', 'munitions_factory', 'steel_mill'],
        'commerce_support' => ['police_station', 'hospital', 'recycling_center', 'subway', 'supermarket', 'bank', 'shopping_mall', 'stadium'],
        'military' => ['barracks', 'factory', 'hangar', 'drydock'],
    ];

    private const FIELD_LABELS = [
        'coal_power' => 'Coal Power Plant',
        'oil_power' => 'Oil Power Plant',
        'wind_power' => 'Wind Power Plant',
        'nuclear_power' => 'Nuclear Power Plant',
        'coal_mine' => 'Coal Mine',
        'oil_well' => 'Oil Well',
        'uranium_mine' => 'Uranium Mine',
        'lead_mine' => 'Lead Mine',
        'iron_mine' => 'Iron Mine',
        'bauxite_mine' => 'Bauxite Mine',
        'farm' => 'Farm',
        'oil_refinery' => 'Gas Refinery',
        'aluminum_refinery' => 'Aluminum Refinery',
        'munitions_factory' => 'Munitions Factory',
        'steel_mill' => 'Steel Mill',
        'police_station' => 'Police Station',
        'hospital' => 'Hospital',
        'recycling_center' => 'Recycling Center',
        'subway' => 'Subway',
        'supermarket' => 'Supermarket',
        'bank' => 'Bank',
        'shopping_mall' => 'Mall',
        'stadium' => 'Stadium',
        'barracks' => 'Barracks',
        'factory' => 'Factory',
        'hangar' => 'Hangar',
        'drydock' => 'Drydock',
    ];

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly MMRService $mmrService,
        private readonly NationProfitabilityService $profitabilityService,
        private readonly BuildOptimizer $optimizer,
    ) {}

    public function refreshAllianceRecommendations(
        ?int $marketPriceSnapshotId = null,
        ?int $radiationSnapshotId = null
    ): int {
        $prices = $this->profitabilityService->getMarketPriceSet($marketPriceSnapshotId);
        $radiationSnapshot = $radiationSnapshotId !== null
            ? RadiationSnapshot::query()->find($radiationSnapshotId)
            : $this->profitabilityService->getCurrentRadiationSnapshot();

        if ($radiationSnapshotId !== null && $radiationSnapshot === null) {
            throw new ProfitabilityContextUnavailable("World snapshot {$radiationSnapshotId} is unavailable.");
        }
        $eligibleNations = $this->eligibleNationQuery()->get();

        if ($eligibleNations->isEmpty()) {
            throw new RuntimeException('No eligible nations were returned for the recommendation refresh.');
        }

        $eligibleNations->each(
            fn (Nation $nation) => $this->profitabilityService->assertCalculationContextAvailable(
                $nation,
                $radiationSnapshot
            )
        );

        foreach ($eligibleNations as $nation) {
            $this->storeRecommendationForNation($nation, $radiationSnapshot, $prices);
        }

        $eligibleIds = $eligibleNations->pluck('id')->all();
        NationBuildRecommendation::query()
            ->where('model_version', EconomyRules::MODEL_VERSION)
            ->when(
                $eligibleIds === [],
                fn ($query) => $query,
                fn ($query) => $query->whereNotIn('nation_id', $eligibleIds)
            )
            ->delete();

        return count($eligibleIds);
    }

    /**
     * @return list<int>
     */
    public function eligibleNationIds(): array
    {
        $nationIds = $this->eligibleNationQuery()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($nationIds === []) {
            throw new RuntimeException('No eligible nations were returned for the recommendation refresh.');
        }

        return $nationIds;
    }

    /**
     * @param  list<int>  $eligibleNationIds
     */
    public function pruneIneligibleRecommendations(array $eligibleNationIds): void
    {
        if ($eligibleNationIds === []) {
            throw new RuntimeException('Refusing to prune recommendations without a validated eligibility set.');
        }

        NationBuildRecommendation::query()
            ->where('model_version', EconomyRules::MODEL_VERSION)
            ->whereNotIn('nation_id', $eligibleNationIds)
            ->delete();
    }

    public function getPriceSetForBatch(): MarketPriceSet
    {
        return $this->profitabilityService->getMarketPriceSet();
    }

    public function getRadiationSnapshotForBatch(): RadiationSnapshot
    {
        return $this->profitabilityService->getCurrentRadiationSnapshot();
    }

    public function assertCalculationContextAvailable(
        Nation $nation,
        RadiationSnapshot $radiationSnapshot
    ): void {
        $this->profitabilityService->assertCalculationContextAvailable($nation, $radiationSnapshot);
    }

    /**
     * @param  list<int>  $nationIds
     */
    public function assertBatchCalculationContextAvailable(
        array $nationIds,
        RadiationSnapshot $radiationSnapshot
    ): void {
        if ($nationIds === []) {
            throw new RuntimeException('No eligible nations were available for context validation.');
        }

        $nations = Nation::query()
            ->select([
                'id',
                'treasure_income_modifier',
                'color_turn_bonus',
                'economy_context_synced_at',
            ])
            ->whereIn('id', $nationIds)
            ->get();

        if ($nations->count() !== count($nationIds)) {
            throw new ProfitabilityContextUnavailable(
                'One or more eligible nations are missing economy context.'
            );
        }

        $nations->each(
            fn (Nation $nation) => $this->assertCalculationContextAvailable($nation, $radiationSnapshot)
        );
    }

    public function refreshStoredRecommendationForNationId(
        int $nationId,
        ?int $marketPriceSnapshotId = null,
        ?int $radiationSnapshotId = null
    ): ?NationBuildRecommendation {
        $nation = $this->eligibleNationQuery()->find($nationId);

        if ($nation === null || ! $this->isEligibleNation($nation)) {
            $this->deleteStoredRecommendationForNationId($nationId);

            return null;
        }

        $prices = $this->profitabilityService->getMarketPriceSet($marketPriceSnapshotId);
        $radiationSnapshot = $radiationSnapshotId !== null
            ? RadiationSnapshot::query()->find($radiationSnapshotId)
            : $this->profitabilityService->getCurrentRadiationSnapshot();

        if ($radiationSnapshotId !== null && $radiationSnapshot === null) {
            throw new ProfitabilityContextUnavailable("World snapshot {$radiationSnapshotId} is unavailable.");
        }

        return $this->storeRecommendationForNation($nation, $radiationSnapshot, $prices);
    }

    public function deleteStoredRecommendationForNationId(int $nationId): void
    {
        NationBuildRecommendation::query()->where('nation_id', $nationId)->delete();
    }

    /**
     * @param  array<string, int>  $build
     * @return array<string, array<int, array<string, int|string>>>
     */
    public function buildDisplayGroups(array $build): array
    {
        $groups = [];

        foreach (self::CATEGORY_GROUPS as $group => $fields) {
            $groups[$group] = collect($fields)
                ->map(function (string $field) use ($build): ?array {
                    $count = (int) ($build[$this->jsonKeyForField($field)] ?? 0);

                    if ($count <= 0) {
                        return null;
                    }

                    return [
                        'field' => $field,
                        'label' => self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field),
                        'count' => $count,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        return $groups;
    }

    private function storeRecommendationForNation(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices
    ): ?NationBuildRecommendation {
        if ($prices->snapshotId === null) {
            throw new ProfitabilityPricingUnavailable('A market price snapshot is required to store a recommendation.');
        }

        $this->profitabilityService->assertCalculationContextAvailable($nation, $radiationSnapshot);

        $tier = $this->mmrService->getTierForNation($nation);
        $result = $this->optimizer->optimize(
            $nation,
            $nation->cities,
            $this->minimumBuild($tier, $nation),
            $radiationSnapshot,
            $prices
        );

        if ($result === null) {
            $this->deleteStoredRecommendationForNationId((int) $nation->id);

            return null;
        }

        $metrics = $result['metrics'];
        $normalizedBuild = $this->normalizeBuildJson($result['build'], $result['target_infrastructure']);
        $context = [
            'model_version' => EconomyRules::MODEL_VERSION,
            'target_strategy' => 'highest_recovered_city',
            'city_count' => $nation->cities->count(),
            'target_infrastructure' => $result['target_infrastructure'],
            'available_slots' => $result['available_slots'],
            'used_slots' => $result['used_slots'],
            'cities_below_target' => $result['cities_below_target'],
            'infrastructure_shortfall' => $result['infrastructure_shortfall'],
            'market' => [
                'snapshot_id' => $prices->snapshotId,
                'calculated_at' => $prices->calculatedAt?->toIso8601String(),
                'stale' => $prices->stale,
                'fallback_resources' => $prices->fallbackResources,
            ],
            'radiation' => [
                'snapshot_id' => $radiationSnapshot?->id,
                'snapshot_at' => $radiationSnapshot?->snapshot_at?->toIso8601String(),
                'game_date' => $radiationSnapshot?->game_date?->toDateString(),
            ],
            'world_snapshot_id' => $radiationSnapshot?->id,
            'game_date' => $radiationSnapshot?->game_date?->toDateString(),
            'season_month' => $radiationSnapshot?->game_date?->month,
            'economy_context_synced_at' => $nation->economy_context_synced_at?->toIso8601String(),
            'economy_context_stale' => false,
        ];
        $attributes = [
            'alliance_id' => $nation->alliance_id,
            'radiation_snapshot_id' => $radiationSnapshot?->id,
            'market_price_snapshot_id' => $prices->snapshotId,
            'model_version' => EconomyRules::MODEL_VERSION,
            'recommended_build_json' => $normalizedBuild,
            'infra_needed' => $result['target_infrastructure'],
            'land_used' => $result['land_used'],
            'imp_total' => $result['used_slots'],
            'available_slots' => $result['available_slots'],
            'cities_below_target' => $result['cities_below_target'],
            'infrastructure_shortfall' => $result['infrastructure_shortfall'],
            'converted_profit_per_day' => $metrics['converted_profit_per_day'],
            'money_profit_per_day' => $metrics['money_profit_per_day'],
            'resource_profit_per_day' => $metrics['resource_profit_per_day'],
            'disease' => $metrics['disease'],
            'pollution' => $metrics['pollution'],
            'crime' => $metrics['crime'],
            'commerce' => $metrics['commerce'],
            'population' => $metrics['population'],
            'price_basis' => $prices->basis,
            'calculation_context' => $context,
            'calculated_at' => now(),
        ];

        return DB::transaction(function () use ($nation, $prices, $radiationSnapshot, $attributes): NationBuildRecommendation {
            $this->profitabilityService->assertPersistedCalculationContextIsCurrent($nation);
            $existing = NationBuildRecommendation::query()
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();

            if ($this->hasNewerInputs($existing, $prices->snapshotId, $radiationSnapshot?->id)) {
                return $existing;
            }

            return NationBuildRecommendation::query()->updateOrCreate(
                ['nation_id' => $nation->id],
                $attributes
            );
        }, 3);
    }

    private function hasNewerInputs(
        ?NationBuildRecommendation $existing,
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

    /**
     * @return array<string, int>
     */
    private function minimumBuild(?MMRTier $tier, Nation $nation): array
    {
        $build = array_fill_keys(EconomyRules::BUILD_FIELDS, 0);

        if ($tier === null) {
            return $build;
        }

        $hasProject = fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
        $build['barracks'] = min((int) $tier->barracks, EconomyRules::improvementCap('barracks', $hasProject));
        $build['factory'] = min((int) $tier->factories, EconomyRules::improvementCap('factory', $hasProject));
        $build['hangar'] = min((int) $tier->hangars, EconomyRules::improvementCap('hangar', $hasProject));
        $build['drydock'] = min((int) $tier->drydocks, EconomyRules::improvementCap('drydock', $hasProject));

        return $build;
    }

    /**
     * @param  array<string, int>  $build
     * @return array<string, int>
     */
    private function normalizeBuildJson(array $build, int $infraNeeded): array
    {
        return [
            'infra_needed' => $infraNeeded,
            'imp_total' => collect(EconomyRules::BUILD_FIELDS)->sum(
                fn (string $field): int => (int) ($build[$field] ?? 0)
            ),
            'imp_coalpower' => (int) ($build['coal_power'] ?? 0),
            'imp_oilpower' => (int) ($build['oil_power'] ?? 0),
            'imp_windpower' => (int) ($build['wind_power'] ?? 0),
            'imp_nuclearpower' => (int) ($build['nuclear_power'] ?? 0),
            'imp_coalmine' => (int) ($build['coal_mine'] ?? 0),
            'imp_oilwell' => (int) ($build['oil_well'] ?? 0),
            'imp_uramine' => (int) ($build['uranium_mine'] ?? 0),
            'imp_leadmine' => (int) ($build['lead_mine'] ?? 0),
            'imp_ironmine' => (int) ($build['iron_mine'] ?? 0),
            'imp_bauxitemine' => (int) ($build['bauxite_mine'] ?? 0),
            'imp_farm' => (int) ($build['farm'] ?? 0),
            'imp_gasrefinery' => (int) ($build['oil_refinery'] ?? 0),
            'imp_aluminumrefinery' => (int) ($build['aluminum_refinery'] ?? 0),
            'imp_munitionsfactory' => (int) ($build['munitions_factory'] ?? 0),
            'imp_steelmill' => (int) ($build['steel_mill'] ?? 0),
            'imp_policestation' => (int) ($build['police_station'] ?? 0),
            'imp_hospital' => (int) ($build['hospital'] ?? 0),
            'imp_recyclingcenter' => (int) ($build['recycling_center'] ?? 0),
            'imp_subway' => (int) ($build['subway'] ?? 0),
            'imp_supermarket' => (int) ($build['supermarket'] ?? 0),
            'imp_bank' => (int) ($build['bank'] ?? 0),
            'imp_mall' => (int) ($build['shopping_mall'] ?? 0),
            'imp_stadium' => (int) ($build['stadium'] ?? 0),
            'imp_barracks' => (int) ($build['barracks'] ?? 0),
            'imp_factory' => (int) ($build['factory'] ?? 0),
            'imp_hangars' => (int) ($build['hangar'] ?? 0),
            'imp_drydock' => (int) ($build['drydock'] ?? 0),
        ];
    }

    /**
     * @return Builder<Nation>
     */
    private function eligibleNationQuery(): Builder
    {
        $allianceIds = $this->membershipService->getAllianceIds()->values()->all();

        return Nation::query()
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
                'treasure_income_modifier',
                'color_turn_bonus',
                'economy_context_synced_at',
            ])
            ->with(['cities'])
            ->whereIn('alliance_id', $allianceIds)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', 0);
    }

    public function isEligibleNation(Nation $nation): bool
    {
        return $this->membershipService->contains($nation->alliance_id)
            && $nation->alliance_position !== 'APPLICANT'
            && (int) ($nation->vacation_mode_turns ?? 0) === 0;
    }

    private function jsonKeyForField(string $field): string
    {
        return match ($field) {
            'coal_power' => 'imp_coalpower',
            'oil_power' => 'imp_oilpower',
            'wind_power' => 'imp_windpower',
            'nuclear_power' => 'imp_nuclearpower',
            'coal_mine' => 'imp_coalmine',
            'oil_well' => 'imp_oilwell',
            'uranium_mine' => 'imp_uramine',
            'lead_mine' => 'imp_leadmine',
            'iron_mine' => 'imp_ironmine',
            'bauxite_mine' => 'imp_bauxitemine',
            'farm' => 'imp_farm',
            'oil_refinery' => 'imp_gasrefinery',
            'aluminum_refinery' => 'imp_aluminumrefinery',
            'munitions_factory' => 'imp_munitionsfactory',
            'steel_mill' => 'imp_steelmill',
            'police_station' => 'imp_policestation',
            'hospital' => 'imp_hospital',
            'recycling_center' => 'imp_recyclingcenter',
            'subway' => 'imp_subway',
            'supermarket' => 'imp_supermarket',
            'bank' => 'imp_bank',
            'shopping_mall' => 'imp_mall',
            'stadium' => 'imp_stadium',
            'barracks' => 'imp_barracks',
            'factory' => 'imp_factory',
            'hangar' => 'imp_hangars',
            'drydock' => 'imp_drydock',
            default => $field,
        };
    }
}
