<?php

namespace App\Services\Calculators;

use App\DataTransferObjects\Calculators\CalculatorResult;
use App\DataTransferObjects\Calculators\CostBreakdown;
use App\DataTransferObjects\MarketPriceSet;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\PWHelperService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CityEconomicsCalculator
{
    /**
     * Projects currently consumed by EconomyCalculator or EconomyRules.
     *
     * @var array<string, string>
     */
    public const ECONOMY_PROJECTS = [
        'arms_stockpile' => 'Arms Stockpile',
        'bauxite_works' => 'Bauxite Works',
        'bureau_of_domestic_affairs' => 'Bureau of Domestic Affairs',
        'clinical_research_center' => 'Clinical Research Center',
        'emergency_gasoline_reserve' => 'Emergency Gasoline Reserve',
        'fallout_shelter' => 'Fallout Shelter',
        'government_support_agency' => 'Government Support Agency',
        'green_technologies' => 'Green Technologies',
        'international_trade_center' => 'International Trade Center',
        'iron_works' => 'Iron Works',
        'mass_irrigation' => 'Mass Irrigation',
        'recycling_initiative' => 'Recycling Initiative',
        'specialized_police_training_program' => 'Specialized Police Training Program',
        'telecommunications_satellite' => 'Telecommunications Satellite',
        'uranium_enrichment_program' => 'Uranium Enrichment Program',
    ];

    /** @var array<string, string> */
    private const PROJECT_EFFECT_NOTES = [
        'arms_stockpile' => 'Boosts munitions output when munitions factories are selected.',
        'bauxite_works' => 'Adjusts aluminum output and bauxite inputs when aluminum refineries are selected.',
        'bureau_of_domestic_affairs' => 'Adds its income bonus only when Open Markets is selected.',
        'clinical_research_center' => 'Strengthens hospitals and raises their per-city cap.',
        'emergency_gasoline_reserve' => 'Adjusts gasoline output and oil inputs when oil refineries are selected.',
        'fallout_shelter' => 'Mitigates radiation losses when farms are selected.',
        'government_support_agency' => 'Adds its income bonus only when Open Markets is selected.',
        'green_technologies' => 'Reduces raw and manufacturing upkeep and adjusts pollution for supported improvements.',
        'international_trade_center' => 'Adds commerce, raises maximum commerce, and raises the bank cap.',
        'iron_works' => 'Adjusts steel output and iron and coal inputs when steel mills are selected.',
        'mass_irrigation' => 'Boosts farm output from land.',
        'recycling_initiative' => 'Raises the recycling-center cap and its pollution reduction.',
        'specialized_police_training_program' => 'Adds commerce and strengthens police stations.',
        'telecommunications_satellite' => 'With International Trade Center, adds commerce and raises maximum commerce and the mall cap.',
        'uranium_enrichment_program' => 'Boosts uranium output when uranium mines are selected.',
    ];

    public function __construct(private readonly EconomyCalculator $economyCalculator) {}

    /**
     * @param  array{continent: string, num_cities: int, domestic_policy: string, treasure_income_modifier: float}  $nationInput
     * @param  array{infrastructure: float, land: float, age_days: int, powered: bool}  $cityInput
     * @param  array<string, int>  $buildings
     * @param  array<string, bool>  $projects
     */
    public function calculate(
        array $nationInput,
        array $cityInput,
        array $buildings,
        array $projects,
        ?RadiationSnapshot $radiationSnapshot,
        ?MarketPriceSet $prices,
        CarbonInterface $asOf,
        int $roiDays,
    ): CalculatorResult {
        $continent = is_string($nationInput['continent'] ?? null)
            ? strtoupper($nationInput['continent'])
            : '';
        $this->validateNation($nationInput, $continent);
        $this->validateCity($cityInput, $roiDays);
        $normalizedProjects = $this->normalizeProjects($projects);
        $hasProject = fn (string $project): bool => $normalizedProjects[$project] ?? false;
        $normalizedBuildings = $this->normalizeBuildings($buildings, $cityInput['infrastructure'], $continent, $hasProject);

        if (($normalizedBuildings['farm'] ?? 0) > 0 && $radiationSnapshot?->game_date === null) {
            throw new InvalidArgumentException('A current world radiation snapshot with a game date is required when farms are included.');
        }

        $nation = new Nation;
        $nation->forceFill([
            'id' => 0,
            'leader_name' => 'Manual calculator',
            'nation_name' => 'Manual calculator',
            'continent' => $continent,
            'num_cities' => $nationInput['num_cities'],
            'domestic_policy' => $nationInput['domestic_policy'],
            'treasure_income_modifier' => $nationInput['treasure_income_modifier'],
            'color_turn_bonus' => 0,
            'project_bits' => $this->projectBits($normalizedProjects),
        ]);

        $city = new City;
        $city->setRawAttributes([
            'id' => 0,
            'nation_id' => 0,
            'name' => 'Manual calculator city',
            'date' => $asOf->copy()->subDays($cityInput['age_days'])->toDateString(),
            'nuke_date' => null,
            'infrastructure' => $cityInput['infrastructure'],
            'land' => $cityInput['land'],
            'powered' => $cityInput['powered'],
            ...$normalizedBuildings,
        ]);

        $enginePrices = $prices ?? MarketPriceSet::symmetric(
            array_fill_keys(EconomyRules::TRADE_RESOURCES, 1.0),
            'unvalued calculator execution prices',
        );
        $metrics = $this->economyCalculator->calculateCityMetrics(
            $nation,
            $city,
            $radiationSnapshot,
            $enginePrices,
            $asOf,
        );
        $baselineCity = new City;
        $baselineCity->setRawAttributes([
            ...$city->getAttributes(),
            'powered' => false,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
        ]);
        $baselineMetrics = $this->economyCalculator->calculateCityMetrics(
            $nation,
            $baselineCity,
            $radiationSnapshot,
            $enginePrices,
            $asOf,
        );
        [$grossMoney, $grossResources] = $this->splitMoney($metrics['unrounded_resource_output_per_day']);
        [$expenseMoney, $expenseResources] = $this->splitMoney($metrics['unrounded_resource_expense_per_day']);
        [$netMoney, $netResources] = $this->splitMoney($metrics['unrounded_resource_profit_per_day']);
        [$incrementalMoney, $incrementalResources] = $this->splitMoney($this->subtractResources(
            $metrics['unrounded_resource_profit_per_day'],
            $baselineMetrics['unrounded_resource_profit_per_day'],
        ));
        [$investmentMoney, $investmentResources] = $this->improvementInvestment($normalizedBuildings);
        $grossIncome = CostBreakdown::liquidation($grossMoney, $grossResources, $prices);
        $expenses = CostBreakdown::acquisition($expenseMoney, $expenseResources, $prices);
        $net = CostBreakdown::net($netMoney, $netResources, $prices);
        $incrementalProfit = CostBreakdown::net($incrementalMoney, $incrementalResources, $prices);
        $investment = CostBreakdown::acquisition($investmentMoney, $investmentResources, $prices);
        $paybackDays = $investment->marketValue !== null && $investment->marketValue > 0
            && $incrementalProfit->marketValue !== null && $incrementalProfit->marketValue > 0
                ? $investment->marketValue / $incrementalProfit->marketValue
                : null;
        $roiPercent = $investment->marketValue !== null && $investment->marketValue > 0
            && $incrementalProfit->marketValue !== null
                ? (($incrementalProfit->marketValue * $roiDays) / $investment->marketValue) * 100
                : null;

        return new CalculatorResult(
            calculator: 'city_build_economics',
            breakdowns: [
                'gross_income_per_day' => $grossIncome,
                'expenses_per_day' => $expenses,
                'net_per_day' => $net,
                'incremental_profit_per_day' => $incrementalProfit,
                'improvement_investment' => $investment,
            ],
            modifiers: $this->modifiers($nationInput, $normalizedProjects, $normalizedBuildings, $metrics),
            assumptions: [
                'Gross income includes city cash income and produced resources valued at liquidation prices.',
                'Expenses use acquisition prices for consumed resources and include improvement and power upkeep plus food consumption.',
                'Improvement investment excludes the city, infrastructure, land, national projects, and military units.',
                'Incremental profit compares the selected build with the same city, infrastructure, land, age, and nation modifiers but no improvements.',
                'Improvement investment treats every selected improvement as a new purchase; existing improvements and salvage value are not deducted.',
                'Payback and ROI use incremental profit and assume the selected build, market prices, radiation, season, and city age remain unchanged.',
                'Color-bonus income and military upkeep are nation-level values and are excluded from this city result.',
            ],
            metrics: [
                'payback_days' => $paybackDays === null ? null : CostBreakdown::round($paybackDays),
                'roi_days' => $roiDays,
                'roi_percent' => $roiPercent === null ? null : CostBreakdown::round($roiPercent),
                'city_income_per_day' => $metrics['city_income_per_day'],
                'money_profit_per_day' => $metrics['money_profit_per_day'],
                'powered' => $metrics['powered'],
                'population' => $metrics['population'],
                'commerce' => $metrics['commerce'],
                'crime' => $metrics['crime'],
                'disease' => $metrics['disease'],
                'pollution' => $metrics['pollution'],
                'model_version' => EconomyRules::MODEL_VERSION,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $nationInput
     */
    private function validateNation(array $nationInput, string $continent): void
    {
        if (! in_array($continent, ['NA', 'SA', 'EU', 'AF', 'AS', 'AU', 'AN'], true)) {
            throw new InvalidArgumentException('Select a valid P&W continent.');
        }

        $cityCount = $nationInput['num_cities'] ?? null;
        if (! is_int($cityCount) || $cityCount < 1 || $cityCount > 1_000) {
            throw new InvalidArgumentException('City count must be between 1 and 1,000.');
        }

        if (! in_array($nationInput['domestic_policy'] ?? null, ['NONE', 'OPEN_MARKETS'], true)) {
            throw new InvalidArgumentException('Only Open Markets affects this city-economics calculation.');
        }

        $treasureModifier = $nationInput['treasure_income_modifier'] ?? null;
        if (
            ! $this->isFiniteNumber($treasureModifier)
            || $treasureModifier < 0
            || $treasureModifier > 1
        ) {
            throw new InvalidArgumentException('Treasure income modifier must be between 0% and 100%.');
        }
    }

    /**
     * @param  array<string, mixed>  $cityInput
     */
    private function validateCity(array $cityInput, int $roiDays): void
    {
        $infrastructure = $cityInput['infrastructure'] ?? null;
        if (
            ! $this->isFiniteNumber($infrastructure)
            || $infrastructure < 0
            || $infrastructure > GamePurchaseCostCalculator::MAX_INFRASTRUCTURE
        ) {
            throw new InvalidArgumentException('Infrastructure must be between 0 and 20,000.');
        }

        $land = $cityInput['land'] ?? null;
        if (
            ! $this->isFiniteNumber($land)
            || $land < 0
            || $land > GamePurchaseCostCalculator::MAX_LAND
        ) {
            throw new InvalidArgumentException('Land must be between 0 and 20,000.');
        }

        $ageDays = $cityInput['age_days'] ?? null;
        if (! is_int($ageDays) || $ageDays < 1 || $ageDays > 100_000) {
            throw new InvalidArgumentException('City age must be between 1 and 100,000 days.');
        }

        if (! is_bool($cityInput['powered'] ?? null)) {
            throw new InvalidArgumentException('Powered state must be true or false.');
        }

        if ($roiDays < 1 || $roiDays > 3_650) {
            throw new InvalidArgumentException('ROI period must be between 1 and 3,650 days.');
        }
    }

    private function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    /**
     * @param  array<string, int>  $buildings
     * @return array<string, int>
     */
    private function normalizeBuildings(
        array $buildings,
        float $infrastructure,
        string $continent,
        callable $hasProject,
    ): array {
        $normalized = [];

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $count = $buildings[$field] ?? 0;

            if (! is_int($count) || $count < 0) {
                throw new InvalidArgumentException('Improvement counts must be non-negative whole numbers.');
            }

            $cap = EconomyRules::improvementCap($field, $hasProject);
            if ($cap > 0 && $count > $cap) {
                throw new InvalidArgumentException(Str::headline($field)." cannot exceed {$cap} for the selected projects.");
            }

            if ($count > 0 && ! EconomyRules::isFieldAllowed($field, $continent)) {
                throw new InvalidArgumentException(Str::headline($field).' is not available on the selected continent.');
            }

            $normalized[$field] = $count;
        }

        $availableSlots = (int) floor($infrastructure / 50);
        if (array_sum($normalized) > $availableSlots) {
            throw new InvalidArgumentException("The build uses more than the {$availableSlots} improvement slots supplied by the selected infrastructure.");
        }

        return $normalized;
    }

    /**
     * @param  array<string, bool>  $projects
     * @return array<string, bool>
     */
    private function normalizeProjects(array $projects): array
    {
        return collect(self::ECONOMY_PROJECTS)
            ->mapWithKeys(fn (string $label, string $project): array => [$project => (bool) ($projects[$project] ?? false)])
            ->all();
    }

    /**
     * @param  array<string, bool>  $projects
     */
    private function projectBits(array $projects): int
    {
        $selected = collect($projects)
            ->filter()
            ->keys()
            ->map(fn (string $project): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($project)))
            ->all();
        $bits = 0;

        foreach (PWHelperService::PROJECTS as $project => $bit) {
            $normalized = (string) preg_replace('/[^a-z0-9]/', '', strtolower($project));
            if (in_array($normalized, $selected, true)) {
                $bits |= $bit;
            }
        }

        return $bits;
    }

    /**
     * @param  array<string, float|int>  $resources
     * @return array{0: float, 1: array<string, float>}
     */
    private function splitMoney(array $resources): array
    {
        $money = (float) ($resources['money'] ?? 0.0);
        unset($resources['money']);

        return [$money, collect($resources)
            ->mapWithKeys(fn (float|int $amount, string $resource): array => [$resource => (float) $amount])
            ->all()];
    }

    /**
     * @param  array<string, float|int>  $selected
     * @param  array<string, float|int>  $baseline
     * @return array<string, float>
     */
    private function subtractResources(array $selected, array $baseline): array
    {
        return collect(array_unique([...array_keys($selected), ...array_keys($baseline)]))
            ->mapWithKeys(fn (string $resource): array => [
                $resource => (float) ($selected[$resource] ?? 0.0) - (float) ($baseline[$resource] ?? 0.0),
            ])
            ->all();
    }

    /**
     * @param  array<string, int>  $buildings
     * @return array{0: float, 1: array<string, float>}
     */
    private function improvementInvestment(array $buildings): array
    {
        $costs = ['money' => 0.0];

        foreach ($buildings as $building => $count) {
            foreach (EconomyRules::BUILDING_PURCHASE_COSTS[$building] ?? [] as $resource => $amount) {
                $costs[$resource] = ($costs[$resource] ?? 0.0) + ($amount * $count);
            }
        }

        return $this->splitMoney($costs);
    }

    /**
     * @param  array<string, mixed>  $nationInput
     * @param  array<string, bool>  $projects
     * @param  array<string, int>  $buildings
     * @param  array<string, mixed>  $metrics
     * @return list<array{key: string, label: string, rate: float|null, applied: bool, note?: string}>
     */
    private function modifiers(array $nationInput, array $projects, array $buildings, array $metrics): array
    {
        $openMarkets = $nationInput['domestic_policy'] === 'OPEN_MARKETS';
        $calculationModifiers = $metrics['calculation_modifiers'];
        $modifiers = [
            ['key' => 'open_markets', 'label' => 'Open Markets', 'rate' => 0.01, 'applied' => $openMarkets],
            [
                'key' => 'government_support_agency',
                'label' => 'Government Support Agency income bonus',
                'rate' => 0.005,
                'applied' => $openMarkets && $projects['government_support_agency'],
                'note' => 'Requires Open Markets.',
            ],
            [
                'key' => 'bureau_of_domestic_affairs',
                'label' => 'Bureau of Domestic Affairs income bonus',
                'rate' => 0.0025,
                'applied' => $openMarkets && $projects['bureau_of_domestic_affairs'],
                'note' => 'Requires Open Markets.',
            ],
            [
                'key' => 'treasure_income_modifier',
                'label' => 'Treasure income modifier',
                'rate' => $nationInput['treasure_income_modifier'],
                'applied' => $nationInput['treasure_income_modifier'] > 0,
            ],
            $this->factorModifier(
                'new_player_income_bonus',
                'New-player income bonus',
                $calculationModifiers['new_player_income_factor'],
                'Declines with nation city count and reaches no bonus at 21 cities.',
            ),
            $this->factorModifier(
                'city_age_population_bonus',
                'City-age population bonus',
                $calculationModifiers['city_age_population_factor'],
                'Calculated from the entered city age and applied through population.',
            ),
            [
                'key' => 'powered_improvements',
                'label' => 'Powered improvements active',
                'rate' => null,
                'applied' => (bool) $metrics['powered'],
                'note' => 'Requires both the powered setting and enough installed generation for the selected infrastructure.',
            ],
        ];

        if (($buildings['farm'] ?? 0) > 0) {
            $modifiers[] = $this->factorModifier(
                'farm_radiation',
                'Farm radiation factor',
                $calculationModifiers['farm_radiation_factor'],
                'Uses the pinned world snapshot and includes Fallout Shelter when selected.',
            );
            $modifiers[] = $this->factorModifier(
                'farm_season',
                'Farm seasonal factor',
                $calculationModifiers['farm_season_factor'],
                'Uses the pinned game date and selected continent.',
            );
            $modifiers[] = $this->factorModifier(
                'farm_continent',
                'Farm continent factor',
                $calculationModifiers['farm_continent_factor'],
                'Antarctica has an additional production adjustment.',
            );
        }

        foreach ($projects as $project => $owned) {
            if (! $owned || in_array($project, ['government_support_agency', 'bureau_of_domestic_affairs'], true)) {
                continue;
            }

            $modifiers[] = [
                'key' => $project,
                'label' => self::ECONOMY_PROJECTS[$project],
                'rate' => null,
                'applied' => true,
                'note' => self::PROJECT_EFFECT_NOTES[$project],
            ];
        }

        return $modifiers;
    }

    /**
     * @return array{key: string, label: string, rate: float, applied: bool, note: string}
     */
    private function factorModifier(string $key, string $label, float $factor, string $note): array
    {
        $rate = $factor - 1.0;

        return [
            'key' => $key,
            'label' => $label,
            'rate' => $rate,
            'applied' => abs($rate) > 0.000000001,
            'note' => $note,
        ];
    }
}
