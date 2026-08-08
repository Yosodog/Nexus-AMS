<?php

namespace App\Services\Calculators;

use App\DataTransferObjects\Calculators\CalculatorResult;
use App\DataTransferObjects\Calculators\CostBreakdown;
use App\DataTransferObjects\MarketPriceSet;
use InvalidArgumentException;

final class MilitaryCostCalculator
{
    public const SOURCE_URL = 'https://github.com/xdnw/locutus/blob/41c9fd07e38c4afd135ab9d84ba1ccaae1d2fbe8/src/main/java/link/locutus/discord/apiv1/enums/MilitaryUnit.java';

    public const MAX_QUANTITY = 100_000_000;

    /** @var list<string> */
    public const UNITS = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'];

    /** @var list<string> */
    public const RESEARCH_FIELDS = ['ground_cost', 'ground_capacity', 'air_cost', 'air_capacity', 'naval_cost', 'naval_capacity'];

    /**
     * @param  array<string, int>  $quantities
     * @param  array<string, int>  $researchLevels
     */
    public function calculate(
        array $quantities,
        array $researchLevels,
        bool $wartime,
        bool $imperialism,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
        ?MarketPriceSet $prices = null,
    ): CalculatorResult {
        $quantities = $this->normalizeQuantities($quantities);
        $research = $this->normalizeResearch($researchLevels);
        $upkeepFactor = 1.0;
        $upkeepFactor -= $imperialism ? 0.05 : 0.0;
        $upkeepFactor -= $imperialism && $governmentSupportAgency ? 0.025 : 0.0;
        $upkeepFactor -= $imperialism && $bureauOfDomesticAffairs ? 0.0125 : 0.0;
        $purchase = ['money' => 0.0];
        $upkeep = ['money' => 0.0];
        $unitRows = [];

        foreach ($quantities as $unit => $quantity) {
            $unitPurchase = $this->purchasePerUnit($unit, $research);
            $unitUpkeep = $this->upkeepPerUnit($unit, $research, $wartime, $upkeepFactor);
            $purchase = $this->addScaled($purchase, $unitPurchase, $quantity);
            $upkeep = $this->addScaled($upkeep, $unitUpkeep, $quantity);
            $unitRows[$unit] = [
                'quantity' => $quantity,
                'purchase_per_unit' => $this->breakdownFromMap($unitPurchase, $prices)->toArray(),
                'upkeep_per_unit' => $this->breakdownFromMap($unitUpkeep, $prices)->toArray(),
            ];
        }

        return new CalculatorResult(
            calculator: 'military_unit_cost',
            breakdowns: [
                'purchase' => $this->breakdownFromMap($purchase, $prices),
                'daily_upkeep' => $this->breakdownFromMap($upkeep, $prices),
            ],
            modifiers: [
                $this->modifier('wartime', 'Wartime upkeep', null, $wartime, 'Changes daily upkeep rates; it does not change purchase prices.'),
                $this->modifier('imperialism', 'Imperialism', 0.05, $imperialism),
                $this->modifier('government_support_agency', 'Government Support Agency synergy', 0.025, $imperialism && $governmentSupportAgency, 'Requires Imperialism.'),
                $this->modifier('bureau_of_domestic_affairs', 'Bureau of Domestic Affairs synergy', 0.0125, $imperialism && $bureauOfDomesticAffairs, 'Requires Imperialism.'),
                $this->modifier('ground_cost_research', 'Ground cost research', null, $research['ground_cost'] > 0, 'Reduces soldier and tank purchase prices and upkeep.'),
                $this->modifier('ground_capacity_research', 'Ground capacity research', null, $research['ground_capacity'] > 0, 'Also reduces soldier and tank upkeep.'),
                $this->modifier('air_cost_research', 'Air cost research', null, $research['air_cost'] > 0, 'Reduces aircraft purchase prices and upkeep.'),
                $this->modifier('air_capacity_research', 'Air capacity research', null, $research['air_capacity'] > 0, 'Also reduces aircraft upkeep.'),
                $this->modifier('naval_cost_research', 'Naval cost research', null, $research['naval_cost'] > 0, 'Reduces ship purchase prices and upkeep.'),
                $this->modifier('naval_capacity_research', 'Naval capacity research', null, $research['naval_capacity'] > 0, 'Also reduces ship upkeep.'),
            ],
            assumptions: [
                'Upkeep is per day and excludes gasoline and munitions consumed during combat.',
                'Unit capacity, project prerequisites, and daily purchase limits are not part of this cost-only calculation.',
                'Domestic-policy upkeep discounts apply to the post-research upkeep amount, matching the existing Nexus profitability engine.',
            ],
            metrics: [
                'wartime' => $wartime,
                'upkeep_discount_percent' => CostBreakdown::round((1 - $upkeepFactor) * 100),
                'research_levels' => $research,
                'units' => $unitRows,
            ],
        );
    }

    /**
     * @param  array<string, int>  $research
     * @return array<string, float>
     */
    private function purchasePerUnit(string $unit, array $research): array
    {
        return match ($unit) {
            'soldiers' => ['money' => max(0.0, 5.0 - (0.1 * $research['ground_cost']))],
            'tanks' => [
                'money' => max(0.0, 60.0 - $research['ground_cost']),
                'steel' => max(0.0, 0.5 - (0.01 * $research['ground_cost'])),
            ],
            'aircraft' => [
                'money' => max(0.0, 4_000.0 - (50.0 * $research['air_cost'])),
                'aluminum' => max(0.0, 10.0 - (0.2 * $research['air_cost'])),
            ],
            'ships' => [
                'money' => max(0.0, 50_000.0 - (500.0 * $research['naval_cost'])),
                'steel' => max(0.0, 30.0 - (0.5 * $research['naval_cost'])),
            ],
            'missiles' => ['money' => 150_000.0, 'aluminum' => 150.0, 'gasoline' => 100.0, 'munitions' => 100.0],
            'nukes' => ['money' => 1_750_000.0, 'aluminum' => 1_000.0, 'gasoline' => 500.0, 'uranium' => 500.0],
            'spies' => ['money' => 50_000.0],
            default => throw new InvalidArgumentException('Unsupported military unit.'),
        };
    }

    /**
     * @param  array<string, int>  $research
     * @return array<string, float>
     */
    private function upkeepPerUnit(string $unit, array $research, bool $wartime, float $factor): array
    {
        $costs = match ($unit) {
            'soldiers' => [
                'money' => max(0.0, ($wartime ? 1.875 : 1.25)
                    - ($research['ground_cost'] * ($wartime ? 0.03 : 0.02))
                    - ($research['ground_capacity'] * ($wartime ? 0.06 : 0.04))),
                'food' => 1 / max(1, ($wartime ? 500 : 750)
                    + ($research['ground_cost'] * ($wartime ? 30 : 20))),
            ],
            'tanks' => [
                'money' => max(0.0, ($wartime ? 75.0 : 50.0)
                    - ($research['ground_cost'] * ($wartime ? 1.5 : 1.0))
                    - ($research['ground_capacity'] * ($wartime ? 3.0 : 2.0))),
            ],
            'aircraft' => [
                'money' => max(0.0, ($wartime ? 1_000.0 : 750.0)
                    - ($research['air_cost'] * ($wartime ? 10.0 : 15.0))
                    - ($research['air_capacity'] * ($wartime ? 20.0 : 30.0))),
            ],
            'ships' => [
                'money' => max(0.0, ($wartime ? 5_000.0 : 3_300.0)
                    - ($research['naval_cost'] * ($wartime ? 50.0 : 30.0))
                    - ($research['naval_capacity'] * ($wartime ? 100.0 : 60.0))),
            ],
            'missiles' => ['money' => $wartime ? 31_500.0 : 21_000.0],
            'nukes' => ['money' => $wartime ? 52_500.0 : 35_000.0],
            'spies' => ['money' => 2_400.0],
            default => throw new InvalidArgumentException('Unsupported military unit.'),
        };

        return collect($costs)
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => $amount * $factor])
            ->all();
    }

    /**
     * @param  array<string, float>  $total
     * @param  array<string, float>  $perUnit
     * @return array<string, float>
     */
    private function addScaled(array $total, array $perUnit, int $quantity): array
    {
        foreach ($perUnit as $resource => $amount) {
            $total[$resource] = ($total[$resource] ?? 0.0) + ($amount * $quantity);
        }

        return $total;
    }

    /**
     * @param  array<string, float>  $costs
     */
    private function breakdownFromMap(array $costs, ?MarketPriceSet $prices): CostBreakdown
    {
        $money = (float) ($costs['money'] ?? 0.0);
        unset($costs['money']);

        return CostBreakdown::acquisition($money, $costs, $prices);
    }

    /**
     * @param  array<string, int>  $quantities
     * @return array<string, int>
     */
    private function normalizeQuantities(array $quantities): array
    {
        $normalized = [];

        foreach (self::UNITS as $unit) {
            $quantity = $quantities[$unit] ?? 0;

            if (! is_int($quantity) || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
                throw new InvalidArgumentException('Unit quantities must be whole numbers between 0 and 100,000,000.');
            }

            $normalized[$unit] = $quantity;
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $research
     * @return array<string, int>
     */
    private function normalizeResearch(array $research): array
    {
        $normalized = [];

        foreach (self::RESEARCH_FIELDS as $field) {
            $level = $research[$field] ?? 0;

            if (! is_int($level) || $level < 0 || $level > 20) {
                throw new InvalidArgumentException('Military research levels must be whole numbers between 0 and 20.');
            }

            $normalized[$field] = $level;
        }

        return $normalized;
    }

    /**
     * @return array{key: string, label: string, rate: float|null, applied: bool, note?: string}
     */
    private function modifier(string $key, string $label, ?float $rate, bool $applied, ?string $note = null): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'rate' => $rate,
            'applied' => $applied,
            'note' => $note,
        ], fn (mixed $value, string $arrayKey): bool => $arrayKey === 'rate' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
