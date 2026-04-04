<?php

namespace App\Services;

use App\Models\City;
use App\Models\MMRTier;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\RadiationSnapshot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class NationBuildRecommendationService
{
    private const POWER_FIELDS = [
        'coal_power',
        'oil_power',
        'wind_power',
        'nuclear_power',
    ];

    private const NON_POWER_FIELDS = [
        'coal_mine',
        'oil_well',
        'uranium_mine',
        'lead_mine',
        'iron_mine',
        'bauxite_mine',
        'farm',
        'oil_refinery',
        'aluminum_refinery',
        'munitions_factory',
        'steel_mill',
        'police_station',
        'hospital',
        'recycling_center',
        'subway',
        'supermarket',
        'bank',
        'shopping_mall',
        'stadium',
        'barracks',
        'factory',
        'hangar',
        'drydock',
    ];

    private const CANDIDATE_FIELDS = [
        'coal_mine',
        'oil_well',
        'uranium_mine',
        'lead_mine',
        'iron_mine',
        'bauxite_mine',
        'farm',
        'oil_refinery',
        'aluminum_refinery',
        'munitions_factory',
        'steel_mill',
        'police_station',
        'hospital',
        'recycling_center',
        'subway',
        'supermarket',
        'bank',
        'shopping_mall',
        'stadium',
    ];

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
        private readonly NationProfitabilityService $profitabilityService
    ) {}

    public function refreshAllianceRecommendations(): int
    {
        $radiationSnapshot = $this->profitabilityService->getCurrentRadiationSnapshot();
        $resourcePrices = $this->profitabilityService->getResourcePrices();
        $eligibleNations = $this->eligibleNationQuery()->get();

        foreach ($eligibleNations as $nation) {
            $this->storeRecommendationForNation($nation, $radiationSnapshot, $resourcePrices);
        }

        $eligibleIds = $eligibleNations->pluck('id')->all();

        NationBuildRecommendation::query()
            ->when(
                empty($eligibleIds),
                fn ($query) => $query,
                fn ($query) => $query->whereNotIn('nation_id', $eligibleIds)
            )
            ->delete();

        return count($eligibleIds);
    }

    public function refreshStoredRecommendationForNationId(int $nationId): ?NationBuildRecommendation
    {
        $nation = $this->eligibleNationQuery()->find($nationId);

        if (! $nation || ! $this->isEligibleNation($nation)) {
            $this->deleteStoredRecommendationForNationId($nationId);

            return null;
        }

        return $this->storeRecommendationForNation(
            $nation,
            $this->profitabilityService->getCurrentRadiationSnapshot(),
            $this->profitabilityService->getResourcePrices()
        );
    }

    public function deleteStoredRecommendationForNationId(int $nationId): void
    {
        NationBuildRecommendation::query()
            ->where('nation_id', $nationId)
            ->delete();
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

    /**
     * @param  array<string, float>  $resourcePrices
     */
    private function storeRecommendationForNation(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): ?NationBuildRecommendation {
        $result = $this->calculateRecommendationForNation($nation, $radiationSnapshot, $resourcePrices);

        if ($result === null) {
            $this->deleteStoredRecommendationForNationId((int) $nation->id);

            return null;
        }

        return NationBuildRecommendation::query()->updateOrCreate(
            ['nation_id' => $nation->id],
            [
                'alliance_id' => $nation->alliance_id,
                'radiation_snapshot_id' => $radiationSnapshot?->id,
                'recommended_build_json' => $result['recommended_build_json'],
                'infra_needed' => $result['infra_needed'],
                'land_used' => $result['land_used'],
                'imp_total' => $result['imp_total'],
                'converted_profit_per_day' => $result['converted_profit_per_day'],
                'money_profit_per_day' => $result['money_profit_per_day'],
                'resource_profit_per_day' => $result['resource_profit_per_day'],
                'disease' => $result['disease'],
                'pollution' => $result['pollution'],
                'crime' => $result['crime'],
                'commerce' => $result['commerce'],
                'population' => $result['population'],
                'price_basis' => '24h average trade prices',
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>|null
     */
    private function calculateRecommendationForNation(
        Nation $nation,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): ?array {
        if ($nation->cities->isEmpty()) {
            return null;
        }

        $tier = $this->mmrService->getTierForNation($nation);
        $profile = $this->buildRepresentativeProfile($nation->cities);
        $seedBuild = $this->seedBuildFromTier($tier, $nation);
        $maxSlots = $this->resolveSlotBudget($seedBuild, $nation, $profile, $radiationSnapshot, $resourcePrices);

        if ($maxSlots === null) {
            return null;
        }

        $best = $this->evaluateBuild($seedBuild, $nation, $profile, $maxSlots, $radiationSnapshot, $resourcePrices);

        if ($best === null) {
            return null;
        }

        while ($best['imp_total'] < $maxSlots) {
            $candidate = $this->findBestAddition($best['base_build'], $best, $nation, $profile, $maxSlots, $radiationSnapshot, $resourcePrices);

            if ($candidate === null) {
                break;
            }

            $best = $candidate;
        }

        while (true) {
            $candidate = $this->findBestSwap($best['base_build'], $best, $nation, $profile, $maxSlots, $radiationSnapshot, $resourcePrices);

            if ($candidate === null) {
                break;
            }

            $best = $candidate;
        }

        if ($best['imp_total'] !== $maxSlots) {
            return null;
        }

        $normalizedBuild = $this->normalizeBuildJson($best['full_build'], $profile['target_infra']);

        return [
            'recommended_build_json' => $normalizedBuild,
            'infra_needed' => $profile['target_infra'],
            'land_used' => round($profile['land_used'], 2),
            'imp_total' => $best['imp_total'],
            'converted_profit_per_day' => $best['metrics']['converted_profit_per_day'],
            'money_profit_per_day' => $best['metrics']['money_profit_per_day'],
            'resource_profit_per_day' => $best['metrics']['resource_profit_per_day'],
            'disease' => $best['metrics']['disease'],
            'pollution' => $best['metrics']['pollution'],
            'crime' => $best['metrics']['crime'],
            'commerce' => $best['metrics']['commerce'],
            'population' => $best['metrics']['population'],
        ];
    }

    /**
     * @param  array<string, int>  $seedBuild
     * @param  array<string, mixed>  $profile
     * @param  array<string, float>  $resourcePrices
     */
    private function resolveSlotBudget(
        array $seedBuild,
        Nation $nation,
        array $profile,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): ?int {
        $maxSlots = $profile['slots_budget'];

        return $this->evaluateBuild($seedBuild, $nation, $profile, $maxSlots, $radiationSnapshot, $resourcePrices) !== null
            ? $maxSlots
            : null;
    }

    /**
     * @param  array<string, int>  $baseBuild
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $profile
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>|null
     */
    private function findBestAddition(
        array $baseBuild,
        array $current,
        Nation $nation,
        array $profile,
        int $maxSlots,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): ?array {
        $best = null;
        $currentProfit = (float) $current['metrics']['converted_profit_per_day'];
        $mustFillSlots = (int) $current['imp_total'] < $maxSlots;

        foreach (self::CANDIDATE_FIELDS as $field) {
            if (($baseBuild[$field] ?? 0) >= $this->capFor($field, $nation)) {
                continue;
            }

            if (! $this->isFieldAllowed($field, $nation)) {
                continue;
            }

            $candidateBuild = $baseBuild;
            $candidateBuild[$field]++;

            $candidate = $this->evaluateBuild($candidateBuild, $nation, $profile, $maxSlots, $radiationSnapshot, $resourcePrices);

            if ($candidate === null) {
                continue;
            }

            $delta = (float) $candidate['metrics']['converted_profit_per_day'] - $currentProfit;

            if (! $mustFillSlots && $delta <= 0.01) {
                continue;
            }

            if (
                $best === null
                || ($mustFillSlots && (
                    (float) $candidate['metrics']['converted_profit_per_day'] > (float) $best['metrics']['converted_profit_per_day']
                    || (
                        (float) $candidate['metrics']['converted_profit_per_day'] === (float) $best['metrics']['converted_profit_per_day']
                        && (float) $candidate['metrics']['money_profit_per_day'] > (float) $best['metrics']['money_profit_per_day']
                    )
                ))
                || (! $mustFillSlots && $delta > $best['delta'])
            ) {
                $best = $candidate + ['delta' => $delta];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, int>  $baseBuild
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $profile
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>|null
     */
    private function findBestSwap(
        array $baseBuild,
        array $current,
        Nation $nation,
        array $profile,
        int $maxSlots,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): ?array {
        $best = null;
        $currentProfit = (float) $current['metrics']['converted_profit_per_day'];

        foreach (self::CANDIDATE_FIELDS as $removeField) {
            if (($baseBuild[$removeField] ?? 0) <= 0) {
                continue;
            }

            foreach (self::CANDIDATE_FIELDS as $addField) {
                if ($addField === $removeField) {
                    continue;
                }

                if (! $this->isFieldAllowed($addField, $nation)) {
                    continue;
                }

                if (($baseBuild[$addField] ?? 0) >= $this->capFor($addField, $nation)) {
                    continue;
                }

                $candidateBuild = $baseBuild;
                $candidateBuild[$removeField]--;
                $candidateBuild[$addField]++;

                $candidate = $this->evaluateBuild($candidateBuild, $nation, $profile, $maxSlots, $radiationSnapshot, $resourcePrices);

                if ($candidate === null) {
                    continue;
                }

                $delta = (float) $candidate['metrics']['converted_profit_per_day'] - $currentProfit;

                if ($delta <= 0.01) {
                    continue;
                }

                if ($best === null || $delta > $best['delta']) {
                    $best = $candidate + ['delta' => $delta];
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, int>  $baseBuild
     * @param  array<string, mixed>  $profile
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>|null
     */
    private function evaluateBuild(
        array $baseBuild,
        Nation $nation,
        array $profile,
        int $maxSlots,
        ?RadiationSnapshot $radiationSnapshot,
        array $resourcePrices
    ): ?array {
        $fullBuild = array_merge($baseBuild, $this->optimalPowerBuild($profile['target_infra'], $nation));
        $impTotal = $this->buildingCount($fullBuild, array_merge(self::NON_POWER_FIELDS, self::POWER_FIELDS));

        if ($impTotal > $maxSlots) {
            return null;
        }

        $city = $this->makeCityFromBuild($fullBuild, $profile, $profile['target_infra']);
        $metrics = $this->profitabilityService->calculateCityRecommendationMetrics(
            $nation,
            $city,
            $radiationSnapshot,
            $resourcePrices
        );

        return [
            'base_build' => $baseBuild,
            'full_build' => $fullBuild,
            'imp_total' => $impTotal,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  EloquentCollection<int, City>  $cities
     * @return array<string, mixed>
     */
    private function buildRepresentativeProfile(EloquentCollection $cities): array
    {
        $medianLand = (float) $this->medianValue($cities->pluck('land')->map(fn ($value) => (float) $value)->all());
        $medianInfra = (float) $this->medianValue($cities->pluck('infrastructure')->map(fn ($value) => (float) $value)->all());
        $sortedDates = $cities->pluck('date')
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->values();
        $medianDate = $sortedDates->get((int) floor(($sortedDates->count() - 1) / 2)) ?? now();

        return [
            'land_used' => max(0.0, $medianLand),
            'target_infra' => max(50, (int) floor($medianInfra / 50) * 50),
            'slots_budget' => max(1, (int) floor($medianInfra / 50)),
            'city_date' => $medianDate,
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function medianValue(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = (int) floor(($count - 1) / 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle] + (float) $values[$middle + 1]) / 2;
    }

    /**
     * @param  array<string, int>  $build
     */
    private function makeCityFromBuild(array $build, array $profile, int $infraNeeded): City
    {
        return new City([
            'name' => 'Recommended Build',
            'date' => $profile['city_date'],
            'nuke_date' => null,
            'infrastructure' => $infraNeeded,
            'land' => $profile['land_used'],
            'powered' => true,
            'oil_power' => $build['oil_power'],
            'wind_power' => $build['wind_power'],
            'coal_power' => $build['coal_power'],
            'nuclear_power' => $build['nuclear_power'],
            'coal_mine' => $build['coal_mine'],
            'oil_well' => $build['oil_well'],
            'uranium_mine' => $build['uranium_mine'],
            'barracks' => $build['barracks'],
            'farm' => $build['farm'],
            'police_station' => $build['police_station'],
            'hospital' => $build['hospital'],
            'recycling_center' => $build['recycling_center'],
            'subway' => $build['subway'],
            'supermarket' => $build['supermarket'],
            'bank' => $build['bank'],
            'shopping_mall' => $build['shopping_mall'],
            'stadium' => $build['stadium'],
            'lead_mine' => $build['lead_mine'],
            'iron_mine' => $build['iron_mine'],
            'bauxite_mine' => $build['bauxite_mine'],
            'oil_refinery' => $build['oil_refinery'],
            'aluminum_refinery' => $build['aluminum_refinery'],
            'steel_mill' => $build['steel_mill'],
            'munitions_factory' => $build['munitions_factory'],
            'factory' => $build['factory'],
            'hangar' => $build['hangar'],
            'drydock' => $build['drydock'],
        ]);
    }

    /**
     * Mirrors Locutus' `setOptimalPower` baseline.
     *
     * @return array<string, int>
     */
    private function optimalPowerBuild(int $targetInfra, Nation $nation): array
    {
        $nuclear = 0;
        $coal = 0;
        $oil = 0;
        $wind = 0;
        $remainingInfra = $targetInfra;

        while ($remainingInfra > 500 || ($targetInfra > 2000 && $remainingInfra > 250)) {
            $nuclear++;
            $remainingInfra -= 2000;
        }

        while ($remainingInfra > 250) {
            if ($this->canBuildCoalPower($nation)) {
                $coal++;
                $remainingInfra -= 500;
            } elseif ($this->canBuildOilPower($nation)) {
                $oil++;
                $remainingInfra -= 500;
            } else {
                break;
            }
        }

        while ($remainingInfra > 0) {
            $wind++;
            $remainingInfra -= 250;
        }

        return [
            'coal_power' => $coal,
            'oil_power' => $oil,
            'wind_power' => $wind,
            'nuclear_power' => $nuclear,
        ];
    }

    /**
     * @return Builder<Nation>
     */
    private function eligibleNationQuery()
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
                'domestic_policy',
                'num_cities',
                'project_bits',
            ])
            ->with([
                'cities:id,nation_id,date,infrastructure,land',
            ])
            ->whereIn('alliance_id', $allianceIds)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', '=', 0);
    }

    private function isEligibleNation(Nation $nation): bool
    {
        return $this->membershipService->contains($nation->alliance_id)
            && $nation->alliance_position !== 'APPLICANT'
            && (int) ($nation->vacation_mode_turns ?? 0) === 0;
    }

    /**
     * @param  array<string, int>  $build
     * @param  array<int, string>  $fields
     */
    private function buildingCount(array $build, array $fields): int
    {
        return collect($fields)->sum(fn (string $field): int => (int) ($build[$field] ?? 0));
    }

    /**
     * @param  array<string, int>  $build
     * @return array<string, int>
     */
    private function normalizeBuildJson(array $build, int $infraNeeded): array
    {
        return [
            'infra_needed' => $infraNeeded,
            'imp_total' => $this->buildingCount($build, array_merge(self::NON_POWER_FIELDS, self::POWER_FIELDS)),
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
     * @return array<string, int>
     */
    private function seedBuildFromTier(?MMRTier $tier, Nation $nation): array
    {
        $build = array_fill_keys(array_merge(self::NON_POWER_FIELDS, self::POWER_FIELDS), 0);

        if ($tier === null) {
            return $build;
        }

        $build['barracks'] = min((int) $tier->barracks, $this->capFor('barracks', $nation));
        $build['factory'] = min((int) $tier->factories, $this->capFor('factory', $nation));
        $build['hangar'] = min((int) $tier->hangars, $this->capFor('hangar', $nation));
        $build['drydock'] = min((int) $tier->drydocks, $this->capFor('drydock', $nation));

        return $build;
    }

    private function capFor(string $field, Nation $nation): int
    {
        $hasProject = fn (string $project): bool => (bool) data_get($nation->projects, $project, false);

        return match ($field) {
            'coal_mine', 'oil_well', 'lead_mine', 'iron_mine', 'bauxite_mine' => 10,
            'uranium_mine' => 5,
            'farm' => 20,
            'oil_refinery', 'aluminum_refinery', 'munitions_factory', 'steel_mill' => 5,
            'subway' => 1,
            'supermarket' => 4,
            'bank' => $hasProject('international_trade_center') ? 6 : 5,
            'shopping_mall' => $hasProject('telecommunications_satellite') ? 5 : 4,
            'stadium' => 3,
            'police_station' => 5,
            'hospital' => $hasProject('clinical_research_center') ? 6 : 5,
            'recycling_center' => $hasProject('recycling_initiative') ? 4 : 3,
            'barracks', 'factory', 'hangar' => 5,
            'drydock' => 3,
            default => 0,
        };
    }

    private function isFieldAllowed(string $field, Nation $nation): bool
    {
        $continent = strtoupper((string) $nation->continent);

        return match ($field) {
            'coal_mine' => in_array($continent, ['NA', 'EU', 'AU', 'AN'], true),
            'oil_well' => in_array($continent, ['SA', 'AF', 'AS', 'AN'], true),
            'uranium_mine' => in_array($continent, ['NA', 'AF', 'AS', 'AN'], true),
            'lead_mine' => in_array($continent, ['SA', 'EU', 'AU'], true),
            'iron_mine' => in_array($continent, ['NA', 'EU', 'AS'], true),
            'bauxite_mine' => in_array($continent, ['SA', 'AF', 'AU'], true),
            default => true,
        };
    }

    private function canBuildCoalPower(Nation $nation): bool
    {
        return true;
    }

    private function canBuildOilPower(Nation $nation): bool
    {
        return true;
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
