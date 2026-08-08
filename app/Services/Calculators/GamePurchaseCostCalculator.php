<?php

namespace App\Services\Calculators;

use App\DataTransferObjects\Calculators\CalculatorResult;
use App\DataTransferObjects\Calculators\CostBreakdown;
use App\DataTransferObjects\MarketPriceSet;
use InvalidArgumentException;

final class GamePurchaseCostCalculator
{
    public const MAX_CITY_NUMBER = 10_000;

    public const MAX_CITY_AVERAGE = 10_000.0;

    public const MAX_INFRASTRUCTURE = 20_000.0;

    public const MAX_LAND = 20_000.0;

    public const MAX_RESEARCH_LEVEL = 20;

    public const SOURCE_COMMIT = ProjectCostCatalog::SOURCE_COMMIT;

    public const CITY_SOURCE_URL = 'https://politicsandwar.com/changelog/?page=2';

    public const INFRA_LAND_SOURCE_URL = 'https://github.com/xdnw/locutus/blob/41c9fd07e38c4afd135ab9d84ba1ccaae1d2fbe8/src/main/java/link/locutus/discord/util/PW.java';

    public const RESEARCH_SOURCE_URL = 'https://github.com/xdnw/locutus/blob/41c9fd07e38c4afd135ab9d84ba1ccaae1d2fbe8/src/main/java/link/locutus/discord/apiv1/enums/Research.java';

    /** @var array<string, string> */
    public const RESEARCH_BRANCHES = [
        'ground_capacity' => 'ground',
        'ground_cost' => 'ground',
        'air_capacity' => 'air',
        'air_cost' => 'air',
        'naval_capacity' => 'naval',
        'naval_cost' => 'naval',
    ];

    public function __construct(private readonly ProjectCostCatalog $projects) {}

    public function city(
        int $cityNumber,
        float $topTwentyAverage,
        bool $manifestDestiny,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
        ?MarketPriceSet $prices = null,
    ): CalculatorResult {
        if ($cityNumber < 1 || $cityNumber > self::MAX_CITY_NUMBER) {
            throw new InvalidArgumentException('The city number must be between 1 and 10,000.');
        }

        if (! is_finite($topTwentyAverage) || $topTwentyAverage <= 0 || $topTwentyAverage > self::MAX_CITY_AVERAGE) {
            throw new InvalidArgumentException('The top-20% city average must be greater than zero and no more than 10,000.');
        }

        $adjustedCity = $cityNumber - ($topTwentyAverage / 4.0);
        $polynomial = (100_000.0 * ($adjustedCity ** 3)) + (150_000.0 * $adjustedCity) + 75_000.0;
        $quadraticFloor = ($cityNumber ** 2) * 100_000.0;
        $baseCost = max($polynomial, $quadraticFloor);
        [$factor, $modifiers] = $this->policyDiscounts(
            'manifest_destiny',
            'Manifest Destiny',
            $manifestDestiny,
            $governmentSupportAgency,
            $bureauOfDomesticAffairs,
        );
        $cost = max(1.0, $baseCost * $factor);

        return new CalculatorResult(
            calculator: 'city_purchase',
            breakdowns: ['purchase' => CostBreakdown::acquisition($cost, [], $prices)],
            modifiers: $modifiers,
            assumptions: [
                'The requested city number is the city being purchased, not the nation\'s current city count.',
                'Existing city-refund credits and event-specific discounts are excluded.',
                'The dynamic top-20% city average is supplied separately and is not embedded in the formula.',
            ],
            metrics: [
                'city_number' => $cityNumber,
                'top_twenty_average' => CostBreakdown::round($topTwentyAverage),
                'base_cost' => CostBreakdown::round($baseCost),
                'discount_percent' => CostBreakdown::round((1 - $factor) * 100),
            ],
        );
    }

    public function infrastructure(
        float $current,
        float $target,
        bool $urbanization,
        bool $centerForCivilEngineering,
        bool $advancedEngineeringCorps,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
        ?MarketPriceSet $prices = null,
    ): CalculatorResult {
        $this->assertPurchaseRange($current, $target, self::MAX_INFRASTRUCTURE, 'Infrastructure');
        $baseCost = $this->infrastructureBaseCost($current, $target);
        $factor = 1.0;
        $factor -= $advancedEngineeringCorps ? 0.05 : 0.0;
        $factor -= $centerForCivilEngineering ? 0.05 : 0.0;
        $factor -= $urbanization ? 0.05 : 0.0;
        $factor -= $urbanization && $governmentSupportAgency ? 0.025 : 0.0;
        $factor -= $urbanization && $bureauOfDomesticAffairs ? 0.0125 : 0.0;

        return new CalculatorResult(
            calculator: 'infrastructure_purchase',
            breakdowns: ['purchase' => CostBreakdown::acquisition($baseCost * $factor, [], $prices)],
            modifiers: [
                $this->modifier('advanced_engineering_corps', 'Advanced Engineering Corps', 0.05, $advancedEngineeringCorps),
                $this->modifier('center_for_civil_engineering', 'Center for Civil Engineering', 0.05, $centerForCivilEngineering),
                $this->modifier('urbanization', 'Urbanization', 0.05, $urbanization),
                $this->modifier('government_support_agency', 'Government Support Agency synergy', 0.025, $urbanization && $governmentSupportAgency, 'Requires Urbanization.'),
                $this->modifier('bureau_of_domestic_affairs', 'Bureau of Domestic Affairs synergy', 0.0125, $urbanization && $bureauOfDomesticAffairs, 'Requires Urbanization.'),
            ],
            assumptions: [
                'This is a purchase-only calculation; selling infrastructure is not included.',
                'P&W purchases the partial block first, then complete 100-infrastructure blocks.',
                'The unit price is rounded to cents for each purchase block before the total is rounded.',
            ],
            metrics: [
                'current' => CostBreakdown::round($current),
                'target' => CostBreakdown::round($target),
                'base_cost' => CostBreakdown::round($baseCost),
                'discount_percent' => CostBreakdown::round((1 - $factor) * 100),
            ],
        );
    }

    public function land(
        float $current,
        float $target,
        bool $rapidExpansion,
        bool $arableLandAgency,
        bool $advancedEngineeringCorps,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
        ?MarketPriceSet $prices = null,
    ): CalculatorResult {
        $this->assertPurchaseRange($current, $target, self::MAX_LAND, 'Land');
        $baseCost = $this->landBaseCost($current, $target);
        $factor = 1.0;
        $factor -= $advancedEngineeringCorps ? 0.05 : 0.0;
        $factor -= $arableLandAgency ? 0.05 : 0.0;
        $factor -= $rapidExpansion ? 0.05 : 0.0;
        $factor -= $rapidExpansion && $governmentSupportAgency ? 0.025 : 0.0;
        $factor -= $rapidExpansion && $bureauOfDomesticAffairs ? 0.0125 : 0.0;

        return new CalculatorResult(
            calculator: 'land_purchase',
            breakdowns: ['purchase' => CostBreakdown::acquisition($baseCost * $factor, [], $prices)],
            modifiers: [
                $this->modifier('advanced_engineering_corps', 'Advanced Engineering Corps', 0.05, $advancedEngineeringCorps),
                $this->modifier('arable_land_agency', 'Arable Land Agency', 0.05, $arableLandAgency),
                $this->modifier('rapid_expansion', 'Rapid Expansion', 0.05, $rapidExpansion),
                $this->modifier('government_support_agency', 'Government Support Agency synergy', 0.025, $rapidExpansion && $governmentSupportAgency, 'Requires Rapid Expansion.'),
                $this->modifier('bureau_of_domestic_affairs', 'Bureau of Domestic Affairs synergy', 0.0125, $rapidExpansion && $bureauOfDomesticAffairs, 'Requires Rapid Expansion.'),
            ],
            assumptions: [
                'This is a purchase-only calculation; selling land is not included.',
                'Land is priced in blocks of up to 500 from the current level toward the target.',
            ],
            metrics: [
                'current' => CostBreakdown::round($current),
                'target' => CostBreakdown::round($target),
                'base_cost' => CostBreakdown::round($baseCost),
                'discount_percent' => CostBreakdown::round((1 - $factor) * 100),
            ],
        );
    }

    public function project(
        string $project,
        bool $technologicalAdvancement,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
        ?MarketPriceSet $prices = null,
    ): CalculatorResult {
        $definition = $this->projects->get($project);
        [$factor, $modifiers] = $this->policyDiscounts(
            'technological_advancement',
            'Technological Advancement',
            $technologicalAdvancement,
            $governmentSupportAgency,
            $bureauOfDomesticAffairs,
        );
        $costs = collect($definition['costs'])
            ->mapWithKeys(fn (float|int $amount, string $resource): array => [$resource => (float) $amount * $factor])
            ->all();
        $money = (float) ($costs['money'] ?? 0.0);
        unset($costs['money']);

        return new CalculatorResult(
            calculator: 'project_purchase',
            breakdowns: ['purchase' => CostBreakdown::acquisition($money, $costs, $prices)],
            modifiers: $modifiers,
            assumptions: [
                'Prerequisite projects, city-count requirements, project slots, and purchase timers are not eligibility checks in this cost-only result.',
                'Removed planning projects are excluded from the catalog.',
            ],
            metrics: [
                'project' => $project,
                'project_label' => $definition['label'],
                'discount_percent' => CostBreakdown::round((1 - $factor) * 100),
                'catalog_source_commit' => ProjectCostCatalog::SOURCE_COMMIT,
            ],
        );
    }

    /**
     * @param  array<string, int>  $currentLevels
     * @param  array<string, int>  $targetLevels
     */
    public function research(
        array $currentLevels,
        array $targetLevels,
        bool $militaryDoctrine,
        ?MarketPriceSet $prices = null,
    ): CalculatorResult {
        $current = $this->normalizeResearchLevels($currentLevels);
        $target = $this->normalizeResearchLevels($targetLevels);
        $totalUpgrades = array_sum($current);
        $groupUpgrades = ['ground' => 0, 'air' => 0, 'naval' => 0];

        foreach ($current as $branch => $level) {
            $groupUpgrades[self::RESEARCH_BRANCHES[$branch]] += $level;
        }

        $factor = $militaryDoctrine ? 0.95 : 1.0;
        $costs = ['money' => 0.0, 'gasoline' => 0.0, 'munitions' => 0.0, 'steel' => 0.0, 'aluminum' => 0.0, 'food' => 0.0];

        foreach (self::RESEARCH_BRANCHES as $branch => $group) {
            if ($target[$branch] < $current[$branch]) {
                throw new InvalidArgumentException('Research target levels cannot be lower than current levels.');
            }

            for ($level = $current[$branch] + 1; $level <= $target[$branch]; $level++) {
                $totalSequence = $totalUpgrades + 1;
                $treeSequence = $groupUpgrades[$group] + 1;
                $costs['money'] += (600_000 * $totalSequence)
                    + (45_000 * ($totalSequence ** 1.75) * $totalSequence / 20);
                $treeCost = (100 * $treeSequence)
                    + ((int) round($treeSequence / 5, 0, PHP_ROUND_HALF_UP) * 500)
                    + ((int) round($treeSequence / 10, 0, PHP_ROUND_HALF_UP) * 1_000)
                    + ((int) round($treeSequence / 20, 0, PHP_ROUND_HALF_UP) * 2_000);
                $costs['gasoline'] += $treeCost;
                $costs['munitions'] += $treeCost;
                $costs['steel'] += $treeCost;
                $costs['aluminum'] += (400 * $treeSequence)
                    + ((int) round($treeSequence / 5, 0, PHP_ROUND_HALF_UP) * 1_000)
                    + ((int) round($treeSequence / 10, 0, PHP_ROUND_HALF_UP) * 2_000)
                    + ((int) round($treeSequence / 20, 0, PHP_ROUND_HALF_UP) * 4_000);
                $costs['food'] += $level * 10_000;
                $totalUpgrades++;
                $groupUpgrades[$group]++;
            }
        }

        $costs = collect($costs)
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => $amount * $factor])
            ->all();
        $money = $costs['money'];
        unset($costs['money']);

        return new CalculatorResult(
            calculator: 'military_research_purchase',
            breakdowns: ['purchase' => CostBreakdown::acquisition($money, $costs, $prices)],
            modifiers: [
                $this->modifier('military_doctrine', 'Military Doctrine', 0.05, $militaryDoctrine),
            ],
            assumptions: [
                'All six current research levels are included because total and branch upgrade counts affect price.',
                'Research cannot be sold or reduced.',
            ],
            metrics: [
                'current_levels' => $current,
                'target_levels' => $target,
                'purchased_levels' => array_sum($target) - array_sum($current),
                'discount_percent' => CostBreakdown::round((1 - $factor) * 100),
            ],
        );
    }

    public function infrastructureBaseCost(float $current, float $target): float
    {
        $current = round($current, 2, PHP_ROUND_HALF_UP);
        $target = round($target, 2, PHP_ROUND_HALF_UP);

        if ($target === $current) {
            return 0.0;
        }

        if ($target < $current) {
            return 150 * ($target - $current);
        }

        $fromCents = (int) round($current * 100, 0, PHP_ROUND_HALF_UP);
        $toCents = (int) round($target * 100, 0, PHP_ROUND_HALF_UP);
        $hundredthCentTotal = 0;

        for ($blockEnd = $toCents; $blockEnd > $fromCents; $blockEnd -= 10_000) {
            $amountInHundredths = min(10_000, $blockEnd - $fromCents);
            $blockStart = $blockEnd - $amountInHundredths;
            $unitCostCents = (int) round($this->infrastructureUnitPrice($blockStart / 100) * 100, 0, PHP_ROUND_HALF_UP);
            $hundredthCentTotal += $unitCostCents * $amountInHundredths;
        }

        $totalCents = intdiv($hundredthCentTotal + 50, 100);

        return $totalCents / 100;
    }

    public function infrastructureUnitPrice(float $infrastructure): float
    {
        return 300 + ((max($infrastructure - 10, 20) ** 2.2) / 710);
    }

    public function landBaseCost(float $current, float $target): float
    {
        if ($target <= $current) {
            return 0.0;
        }

        $total = 0.0;

        for ($level = max(0.0, $current); $level < $target; $level += 500) {
            $unitCost = (0.002 * (max(20, $level - 20) ** 2)) + 50;
            $amount = min(500, $target - $level);
            $total += $unitCost * $amount;
        }

        return $total;
    }

    /**
     * @return array{0: float, 1: list<array{key: string, label: string, rate: float|null, applied: bool, note?: string}>}
     */
    private function policyDiscounts(
        string $policyKey,
        string $policyLabel,
        bool $policy,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
    ): array {
        $factor = 1.0;
        $factor -= $policy ? 0.05 : 0.0;
        $factor -= $policy && $governmentSupportAgency ? 0.025 : 0.0;
        $factor -= $policy && $bureauOfDomesticAffairs ? 0.0125 : 0.0;

        return [$factor, [
            $this->modifier($policyKey, $policyLabel, 0.05, $policy),
            $this->modifier('government_support_agency', 'Government Support Agency synergy', 0.025, $policy && $governmentSupportAgency, "Requires {$policyLabel}."),
            $this->modifier('bureau_of_domestic_affairs', 'Bureau of Domestic Affairs synergy', 0.0125, $policy && $bureauOfDomesticAffairs, "Requires {$policyLabel}."),
        ]];
    }

    /**
     * @return array{key: string, label: string, rate: float, applied: bool, note?: string}
     */
    private function modifier(string $key, string $label, float $rate, bool $applied, ?string $note = null): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'rate' => $rate,
            'applied' => $applied,
            'note' => $note,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function assertPurchaseRange(float $current, float $target, float $maximum, string $label): void
    {
        if (! is_finite($current) || ! is_finite($target)) {
            throw new InvalidArgumentException("{$label} values must be finite numbers.");
        }

        if ($current < 0 || $target < 0 || $current > $maximum || $target > $maximum) {
            throw new InvalidArgumentException("{$label} must be between 0 and ".number_format($maximum, 0).'.');
        }

        if ($target < $current) {
            throw new InvalidArgumentException("Target {$label} cannot be lower than current {$label} for a purchase calculation.");
        }
    }

    /**
     * @param  array<string, int>  $levels
     * @return array<string, int>
     */
    private function normalizeResearchLevels(array $levels): array
    {
        $normalized = [];

        foreach (self::RESEARCH_BRANCHES as $branch => $group) {
            $level = $levels[$branch] ?? 0;

            if (! is_int($level) || $level < 0 || $level > self::MAX_RESEARCH_LEVEL) {
                throw new InvalidArgumentException('Research levels must be whole numbers between 0 and 20.');
            }

            $normalized[$branch] = $level;
        }

        return $normalized;
    }
}
