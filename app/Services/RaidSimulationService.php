<?php

namespace App\Services;

use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Services\Calculators\GamePurchaseCostCalculator;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\WarSimulator\Support\PercentileCalculator;
use App\Services\WarSimulator\Support\RaidLootFormula;
use App\Services\WarSimulator\Support\WarSimRng;
use App\Services\WarSimulator\WarSimulationService;

/**
 * Evaluates a raid as a sequence of supported war actions.
 *
 * This service deliberately keeps resource quantities separate from their
 * monetary value until the final aggregation. That prevents ground cash loot
 * from being counted a second time as victory loot and allows the same plan to
 * be valued with either acquisition or liquidation prices.
 */
final class RaidSimulationService
{
    public const MODEL_VERSION = 'raid-simulation-v2';

    private const DEFAULT_ITERATIONS = 128;

    private const MIN_ITERATIONS = 16;

    private const MAX_ITERATIONS = 512;

    /**
     * The public war timeline advances in two-hour turns. This is recorded
     * from the game's war UI and is not an inferred economic rate.
     *
     * @see https://forum.politicsandwar.com/index.php?%2Ftopic%2F36121-vikings-of-anarch-dowdoe%2F=
     */
    private const DEFAULT_TURN_HOURS = 2.0;

    /**
     * Sixty two-hour turns is the default five-day war expiration.
     *
     * @see https://forum.politicsandwar.com/index.php?%2Ftopic%2F33423-beige-the-final-season%2F=
     */
    private const DEFAULT_EXPIRATION_HOURS = 120.0;

    /** Callers should replace this estimate with the captured attacker MAP. */
    private const DEFAULT_STARTING_MAP = 3.0;

    /**
     * The war UI exposes a twelve-point MAP meter.
     *
     * @see https://forum.politicsandwar.com/index.php?%2Ftopic%2F36121-vikings-of-anarch-dowdoe%2F=
     */
    private const MAX_MAP = 12.0;

    private const MAX_PLAN_ACTIONS = 40;

    /**
     * Action costs recorded in the public war action selector.
     *
     * @see https://forum.politicsandwar.com/index.php?%2Ftopic%2F36121-vikings-of-anarch-dowdoe%2F=
     *
     * @var array<string, float>
     */
    private const ACTION_MAP_COSTS = [
        'ground' => 3.0,
        'air' => 4.0,
        'naval' => 4.0,
    ];

    /**
     * @param  WarSimulationService|null  $warSimulationService  Nullable to
     *                                                           allow the service to be used from small calculator contexts; the
     *                                                           application container resolves the configured simulator when needed.
     */
    public function __construct(
        private ?WarSimulationService $warSimulationService = null,
        private ?MilitaryCostCalculator $militaryCostCalculator = null,
        private ?GamePurchaseCostCalculator $gamePurchaseCostCalculator = null,
    ) {}

    public function expectedBankLootFraction(
        float $attackerScore,
        float $allianceScore,
        float $lootMultiplier = 1.0,
    ): ?float {
        return RaidLootFormula::expectedBankLootFraction($attackerScore, $allianceScore, $lootMultiplier);
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $stockpile
     * @param  array<string, mixed>  $prices
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function evaluate(
        array $attacker,
        array $target,
        array $stockpile,
        array $prices,
        array $context = [],
    ): array {
        $context = $this->normalizeContext($attacker, $target, $context);
        $context['confidence'] = strtolower((string) ($context['confidence'] ?: ($stockpile['confidence'] ?? '')));
        $priceMaps = $this->normalizePrices($prices);
        $stockpileScenarios = $this->normalizeStockpileScenarios($stockpile, $target, $context);
        $attackerState = $this->normalizeNation($attacker);
        $targetState = $this->normalizeNation($target);
        $militarySuitability = $this->militarySuitability($attackerState, $targetState);
        $approachDefinitions = $this->approachDefinitions($attackerState, $targetState, $context);
        $iterations = $this->normalizeIterations($context['iterations'] ?? self::DEFAULT_ITERATIONS);

        $missingMilitary = [
            'attacker' => $this->missingMilitaryFields($attacker),
            'target' => $this->missingMilitaryFields($target),
        ];
        $missingMilitary = array_filter($missingMilitary, static fn (array $fields): bool => $fields !== []);
        if ($missingMilitary !== []) {
            $missingDescription = collect($missingMilitary)
                ->map(fn (array $fields, string $side): string => $side.': '.implode(', ', $fields))
                ->implode('; ');
            $approaches = array_map(function (array $definition) use ($missingDescription): array {

                return [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'expected_net' => null,
                    'gross_loot' => null,
                    'conservative_net' => null,
                    'slot_efficiency' => null,
                    'duration_hours' => null,
                    'win_probability' => 0.0,
                    'confidence' => 'unknown',
                    'status' => 'unavailable',
                    'available' => false,
                    'reason' => 'Required military observations are incomplete ('.$missingDescription.').',
                ];
            }, $approachDefinitions);

            return $this->emptyEvaluation(
                $approaches,
                $militarySuitability,
                $stockpileScenarios,
                $priceMaps,
                $context,
                [
                    'Required military observations are incomplete ('.$missingDescription.'); missing values remain unavailable instead of being treated as zero.',
                ],
            );
        }

        $approaches = [];
        $evaluatedApproaches = [];
        foreach ($approachDefinitions as $definition) {
            if (! $definition['feasible']) {
                $approaches[] = [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'expected_net' => null,
                    'gross_loot' => null,
                    'conservative_net' => null,
                    'slot_efficiency' => null,
                    'duration_hours' => null,
                    'win_probability' => 0.0,
                    'confidence' => 'unknown',
                    'status' => 'unavailable',
                    'available' => false,
                    'reason' => $definition['reason'],
                ];

                continue;
            }

            $approachResult = $this->evaluateApproach(
                $definition,
                $attackerState,
                $targetState,
                $stockpileScenarios,
                $priceMaps,
                $context,
                $iterations,
            );
            $evaluatedApproaches[$definition['key']] = $approachResult;
            $approaches[] = $approachResult['summary'];
        }

        $availableApproaches = collect($approaches)
            ->filter(fn (array $approach): bool => ($approach['available'] ?? false) && $approach['expected_net'] !== null)
            ->values();
        $selected = $availableApproaches
            ->sortByDesc(fn (array $approach): float => (float) ($approach['expected_net'] ?? -INF))
            ->first();

        if ($selected === null) {
            $hasEvaluatedApproach = collect($approaches)
                ->contains(fn (array $approach): bool => ($approach['available'] ?? false) === true);
            $evaluationAssumptions = collect($evaluatedApproaches)
                ->flatMap(fn (array $evaluation): array => (array) ($evaluation['assumptions'] ?? []))
                ->values()
                ->all();

            return $this->emptyEvaluation(
                $approaches,
                $militarySuitability,
                $stockpileScenarios,
                $priceMaps,
                $context,
                $hasEvaluatedApproach
                    ? ['No supported approach produced a complete monetary estimate from the supplied inputs.']
                    : $evaluationAssumptions,
            );
        }

        $selectedResult = $evaluatedApproaches[$selected['key']];

        $approaches = collect($approaches)
            ->map(function (array $approach) use ($selectedResult): array {
                if ($approach['key'] !== $selectedResult['approach']['key']) {
                    return $approach;
                }

                return $selectedResult['summary'];
            })
            ->values()
            ->all();

        return [
            'model_version' => self::MODEL_VERSION,
            'status' => $selectedResult['status'],
            'expected_net' => $selectedResult['expected_net'],
            'gross_loot' => $selectedResult['gross_loot'],
            'conservative_net' => $selectedResult['conservative_net'],
            'slot_efficiency' => $selectedResult['slot_efficiency'],
            'duration_hours' => $selectedResult['duration_hours'],
            'win_probability' => $selectedResult['win_probability'],
            'counter_risk' => $selectedResult['counter_risk'],
            'confidence' => $selectedResult['confidence'],
            'approach' => $selectedResult['approach'],
            'approaches' => $approaches,
            'valuation' => $selectedResult['valuation'],
            'components' => $selectedResult['components'],
            'loot_resources' => $selectedResult['loot_resources'],
            'cost_resources' => $selectedResult['cost_resources'],
            'assumptions' => $selectedResult['assumptions'],
            'scenarios' => $selectedResult['scenarios'],
            'military_suitability' => $militarySuitability,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  list<array<string, mixed>>  $stockpileScenarios
     * @param  array{acquisition: array<string, float>, liquidation: array<string, float>}  $prices
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function evaluateApproach(
        array $definition,
        array $attacker,
        array $target,
        array $stockpileScenarios,
        array $prices,
        array $context,
        int $iterations,
    ): array {
        $scenarioResults = [];
        $weighted = [
            'expected_net' => 0.0,
            'gross_loot' => 0.0,
            'win_probability' => 0.0,
            'duration_hours' => 0.0,
            'counter_risk' => 0.0,
            'components' => $this->emptyComponents(),
            'loot_resources' => [],
            'cost_resources' => [],
            'actions_executed' => 0.0,
            'map_remaining' => 0.0,
            'controls' => [],
        ];
        $weightedKnown = true;
        $componentKnown = array_fill_keys(array_keys($weighted['components']), true);
        $valuationBases = [];
        $unknownValuationComponents = [];
        $allAssumptions = [
            'All actions in the selected approach are simulated in order against the remaining military and stockpile state.',
            'Ground cash loot is removed before victory loot is calculated.',
            'Unit replacement costs use acquisition prices; loot uses liquidation prices.',
            'Fractional simulator casualty estimates carry through the sequence; replacement quantities round cumulatively at whole-unit boundaries.',
            'MAP regenerates by one point every two hours; ground, air, and naval actions use their documented MAP costs.',
            'Victory ends the war at the simulated victory time; an unfinished war remains occupied until the 120-hour expiration.',
        ];
        $allAssumptions = array_merge($allAssumptions, (array) ($context['behavior_assumptions'] ?? []));
        if (($context['attacker_funds_available'] ?? true) === false) {
            $allAssumptions[] = 'Attacker funds were unavailable at capture time; affordability is not verified.';
        }

        foreach ($stockpileScenarios as $scenarioIndex => $scenario) {
            $scenarioResult = $this->simulateScenario(
                $definition,
                $attacker,
                $target,
                $scenario,
                $prices,
                $context,
                $iterations,
                $scenarioIndex,
            );
            $scenarioResults[] = $scenarioResult;

            if ($scenarioResult['expected_net'] === null) {
                $weightedKnown = false;
            }

            $valuationBases[] = (string) data_get($scenarioResult, 'valuation.basis', 'unavailable');
            $unknownValuationComponents = array_merge(
                $unknownValuationComponents,
                (array) data_get($scenarioResult, 'valuation.unknown_components', []),
            );

            $weight = (float) $scenario['weight'];
            $weighted['expected_net'] += (float) ($scenarioResult['expected_net'] ?? 0.0) * $weight;
            $weighted['gross_loot'] += (float) ($scenarioResult['gross_loot'] ?? 0.0) * $weight;
            $weighted['win_probability'] += (float) ($scenarioResult['win_probability'] ?? 0.0) * $weight;
            $weighted['duration_hours'] += (float) ($scenarioResult['duration_hours'] ?? 0.0) * $weight;
            $weighted['counter_risk'] += (float) ($scenarioResult['counter_risk'] ?? 0.0) * $weight;
            $weighted['actions_executed'] += (float) ($scenarioResult['actions_executed'] ?? 0.0) * $weight;
            $weighted['map_remaining'] += (float) ($scenarioResult['map_remaining'] ?? 0.0) * $weight;
            $weighted['controls'] = $this->mergeWeightedControlProbabilities(
                $weighted['controls'],
                $scenarioResult['controls'] ?? [],
                $weight,
            );

            foreach ($weighted['components'] as $component => $value) {
                $scenarioValue = $scenarioResult['components'][$component] ?? null;
                if ($scenarioValue === null) {
                    $componentKnown[$component] = false;

                    continue;
                }

                $weighted['components'][$component] += (float) $scenarioValue * $weight;
            }

            $weighted['loot_resources'] = $this->mergeWeightedResourceMap(
                $weighted['loot_resources'],
                $scenarioResult['loot_resources'],
                $weight,
            );
            $weighted['cost_resources'] = $this->mergeWeightedResourceMap(
                $weighted['cost_resources'],
                $scenarioResult['cost_resources'],
                $weight,
            );
            $allAssumptions = array_merge($allAssumptions, $scenarioResult['assumptions']);
        }

        $netValues = collect($scenarioResults)
            ->flatMap(fn (array $scenario): array => $scenario['net_values'])
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values()
            ->all();
        $conservative = $netValues === [] ? null : PercentileCalculator::percentile($netValues, 0.1);
        $expectedNet = $weightedKnown ? $weighted['expected_net'] : null;
        $grossLoot = $weighted['gross_loot'];
        $duration = $scenarioResults === [] ? null : $weighted['duration_hours'];
        $slotEfficiency = $expectedNet !== null && $duration !== null && $duration > 0
            ? $expectedNet / $duration
            : null;
        $confidence = $this->confidence($stockpileScenarios, $prices, $context, $weightedKnown);
        $components = $weighted['components'];
        foreach ($componentKnown as $component => $known) {
            if (! $known) {
                $components[$component] = null;
            }
        }
        $status = $expectedNet === null ? 'unavailable' : (in_array(false, $componentKnown, true) ? 'degraded' : 'ready');
        $valuation = [
            'basis' => $this->valuationBasis($valuationBases),
            'lower_bound' => $this->roundNullable($expectedNet),
            'upper_bound' => $this->valuationBasis($valuationBases) === 'complete'
                ? $this->roundNullable($expectedNet)
                : null,
            'unknown_components' => array_values(array_unique($unknownValuationComponents)),
        ];
        if ($valuation['basis'] === 'lower_bound') {
            $allAssumptions[] = 'The reported gross and net values are explicit lower bounds because one or more loot components remain unknown.';
        }
        $summary = [
            'key' => $definition['key'],
            'label' => $definition['label'],
            'expected_net' => $this->roundNullable($expectedNet),
            'gross_loot' => $this->roundNullable($grossLoot),
            'conservative_net' => $this->roundNullable($conservative),
            'slot_efficiency' => $this->roundNullable($slotEfficiency),
            'duration_hours' => $this->roundNullable($duration),
            'win_probability' => round($weighted['win_probability'], 4),
            'attacks' => round($weighted['actions_executed'], 2),
            'mechanics' => [
                'actions_executed' => round($weighted['actions_executed'], 2),
                'map_remaining' => round($weighted['map_remaining'], 2),
                'controls' => $this->roundControlProbabilities($weighted['controls']),
            ],
            'counter_risk' => [
                'probability' => round($weighted['counter_risk'], 4),
                'basis' => 'heuristic',
                'model_version' => 'raid-behavior-v1',
            ],
            'valuation' => $valuation,
            'confidence' => $confidence,
            'status' => $status,
            'available' => $status !== 'unavailable',
            'reason' => $definition['reason'],
        ];

        return [
            'summary' => $summary,
            'expected_net' => $summary['expected_net'],
            'gross_loot' => $summary['gross_loot'],
            'conservative_net' => $summary['conservative_net'],
            'slot_efficiency' => $summary['slot_efficiency'],
            'duration_hours' => $summary['duration_hours'],
            'win_probability' => $summary['win_probability'],
            'mechanics' => $summary['mechanics'],
            'counter_risk' => $summary['counter_risk'],
            'valuation' => $valuation,
            'confidence' => $confidence,
            'status' => $status,
            'approach' => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'actions' => $definition['actions'],
                'attacks' => $summary['attacks'],
                'mechanics' => $summary['mechanics'],
                'valuation' => $valuation,
                'reason' => $definition['reason'],
            ],
            'components' => collect($components)
                ->mapWithKeys(fn (?float $amount, string $component): array => [$component => $amount === null ? null : round($amount, 2)])
                ->all(),
            'loot_resources' => $this->roundMap($weighted['loot_resources']),
            'cost_resources' => $this->roundMap($weighted['cost_resources']),
            'assumptions' => array_values(array_unique($allAssumptions)),
            'scenarios' => collect($scenarioResults)
                ->map(fn (array $scenario): array => [
                    'key' => $scenario['key'],
                    'label' => $scenario['label'],
                    'weight' => $scenario['weight'],
                    'expected_net' => $this->roundNullable($scenario['expected_net']),
                    'gross_loot' => $this->roundNullable($scenario['gross_loot']),
                    'win_probability' => round((float) $scenario['win_probability'], 4),
                    'attacks' => round((float) ($scenario['actions_executed'] ?? 0.0), 2),
                    'mechanics' => [
                        'actions_executed' => round((float) ($scenario['actions_executed'] ?? 0.0), 2),
                        'map_remaining' => round((float) ($scenario['map_remaining'] ?? 0.0), 2),
                        'controls' => $this->roundControlProbabilities($scenario['controls'] ?? []),
                    ],
                    'valuation' => $scenario['valuation'],
                    'assumptions' => $scenario['assumptions'],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $scenario
     * @param  array{acquisition: array<string, float>, liquidation: array<string, float>}  $prices
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function simulateScenario(
        array $definition,
        array $attacker,
        array $target,
        array $scenario,
        array $prices,
        array $context,
        int $iterations,
        int $scenarioIndex,
    ): array {
        $netValues = [];
        $grossValues = [];
        $winValues = [];
        $durationValues = [];
        $componentValues = [];
        $lootResourceValues = [];
        $costResourceValues = [];
        $actionCounts = [];
        $mapRemainingValues = [];
        $controlValues = [];
        $valuationBases = [];
        $unknownValuationComponents = [];
        $assumptions = (array) ($scenario['assumptions'] ?? []);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $seed = $this->iterationSeed($context['seed'] ?? null, $scenarioIndex, $iteration);
            $result = $this->simulatePlanIteration(
                $definition,
                $attacker,
                $target,
                $scenario,
                $prices,
                $context,
                $seed,
            );

            $netValues[] = $result['net'];
            $grossValues[] = $result['gross'];
            $winValues[] = $result['won'] ? 1.0 : 0.0;
            $durationValues[] = $result['duration_hours'];
            $componentValues[] = $result['components'];
            $lootResourceValues[] = $result['loot_resources'];
            $costResourceValues[] = $result['cost_resources'];
            $actionCounts[] = (float) ($result['actions_executed'] ?? 0.0);
            $mapRemainingValues[] = (float) ($result['map_remaining'] ?? 0.0);
            $controlValues[] = (array) ($result['controls'] ?? []);
            $valuationBases[] = (string) data_get($result, 'valuation.basis', 'unavailable');
            $unknownValuationComponents = array_merge(
                $unknownValuationComponents,
                (array) data_get($result, 'valuation.unknown_components', []),
            );
            $assumptions = array_merge($assumptions, $result['assumptions']);
        }

        $componentAverages = $this->averageComponents($componentValues);
        $lootAverages = $this->averageResourceMaps($lootResourceValues);
        $costAverages = $this->averageResourceMaps($costResourceValues);
        $expectedNet = $this->averageNullable($netValues);

        return [
            'key' => (string) $scenario['key'],
            'label' => (string) $scenario['label'],
            'weight' => (float) $scenario['weight'],
            'expected_net' => $expectedNet,
            'gross_loot' => $this->averageNullable($grossValues),
            'win_probability' => $this->averageNullable($winValues) ?? 0.0,
            'duration_hours' => $this->averageNullable($durationValues),
            'counter_risk' => max(0.0, min(1.0, $this->number($context['counter_risk'] ?? 0.0) ?? 0.0)),
            'actions_executed' => $this->averageNullable($actionCounts) ?? 0.0,
            'map_remaining' => $this->averageNullable($mapRemainingValues) ?? 0.0,
            'controls' => $this->controlProbabilities($controlValues),
            'components' => $componentAverages,
            'loot_resources' => $lootAverages,
            'cost_resources' => $costAverages,
            'net_values' => $netValues,
            'valuation' => [
                'basis' => $this->valuationBasis($valuationBases),
                'unknown_components' => array_values(array_unique($unknownValuationComponents)),
            ],
            'assumptions' => array_values(array_unique($assumptions)),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $scenario
     * @param  array{acquisition: array<string, float>, liquidation: array<string, float>}  $prices
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function simulatePlanIteration(
        array $definition,
        array $attacker,
        array $target,
        array $scenario,
        array $prices,
        array $context,
        ?int $seed,
    ): array {
        $attackerState = $this->militaryState($attacker);
        $defenderState = $this->militaryState($target);
        $stockpileResources = $this->projectStockpile(
            $this->resourceMap((array) ($scenario['resources'] ?? [])),
            $scenario,
            $context,
        );
        $unknownStockpileResources = array_values(array_unique((array) ($scenario['unknown_resources'] ?? [])));
        $bankResources = $scenario['bank_resources'];
        $bankResources = is_array($bankResources) ? $this->resourceMap($bankResources) : null;
        $initialMoney = array_key_exists('money', $stockpileResources)
            ? (float) $stockpileResources['money']
            : (! in_array('money', $unknownStockpileResources, true) && isset($target['money'])
                ? (float) $target['money']
                : null);
        if (! array_key_exists('money', $stockpileResources)
            && ! in_array('money', $unknownStockpileResources, true)
            && $initialMoney !== null) {
            $stockpileResources['money'] = $initialMoney;
        }
        $defenderState['money'] = $initialMoney;
        $resistance = max(1, (int) round((float) ($context['resistance'] ?? 100)));
        $maxMap = max(1.0, (float) ($context['max_map'] ?? self::MAX_MAP));
        $attackerMap = max(0.0, min($maxMap, (float) ($context['attacker_map'] ?? self::DEFAULT_STARTING_MAP)));
        $turnHours = max(0.01, (float) ($context['turn_hours'] ?? self::DEFAULT_TURN_HOURS));
        $expirationHours = max(0.0, (float) ($context['expiration_hours'] ?? self::DEFAULT_EXPIRATION_HOURS));
        $elapsedHours = 0.0;
        $airSuperiorityOwner = (string) ($context['air_superiority_owner'] ?? 'none');
        $groundControlOwner = (string) ($context['ground_control_owner'] ?? 'none');
        $blockadeOwner = (string) ($context['blockade_owner'] ?? 'none');
        $lootResources = [];
        $costResources = [];
        $components = $this->emptyComponents();
        $assumptions = [];
        $won = false;
        $actionsExecuted = 0;
        $executionBlocked = false;
        $attackerLossTotals = array_fill_keys(['soldiers', 'tanks', 'aircraft', 'ships'], 0.0);
        $chargedLossTotals = array_fill_keys(['soldiers', 'tanks', 'aircraft', 'ships'], 0);
        $rng = new WarSimRng($seed);
        $attackerResourceBudget = $this->resourceBudget($attacker['resources'] ?? null);
        if ($attackerResourceBudget === null) {
            $assumptions[] = 'Attacker fuel, munitions, and replacement funding were not supplied; affordability is unverified.';
        } elseif ($attackerResourceBudget['unknown'] !== []) {
            $assumptions[] = 'Attacker resource balances are incomplete; missing fuel or munitions remain unavailable for affordability checks.';
        }
        if ($attackerResourceBudget !== null && ! array_key_exists('money', $attackerResourceBudget['available'])) {
            $assumptions[] = 'Attacker money balance was not supplied; replacement funding remains unverified.';
        }

        foreach ($definition['actions'] as $actionDefinition) {
            if ($resistance <= 0) {
                break;
            }

            $action = $this->buildAction($actionDefinition, $attackerState, $defenderState);
            if ($action === null) {
                $assumptions[] = sprintf('The %s action was skipped because no eligible attacking units remained.', $actionDefinition['type']);

                continue;
            }

            $mapCost = $this->actionMapCost((string) ($actionDefinition['type'] ?? 'ground'), $context);
            if ($mapCost > $maxMap) {
                $assumptions[] = sprintf('The %s action requires %.2f MAP, above the configured %.2f MAP maximum, so it was unavailable.', $action['type'], $mapCost, $maxMap);

                break;
            }

            $turnsToWait = $attackerMap >= $mapCost
                ? 0
                : (int) ceil($mapCost - $attackerMap);
            if ($turnsToWait > 0) {
                $elapsedHours += $turnsToWait * $turnHours;
                $attackerMap = min($maxMap, $attackerMap + $turnsToWait);
                $assumptions[] = sprintf('The plan waits %d MAP turn%s (%.2f hours) before the %s action.', $turnsToWait, $turnsToWait === 1 ? '' : 's', $turnsToWait * $turnHours, $action['type']);
            }

            if ($elapsedHours >= $expirationHours) {
                $assumptions[] = 'The war expired before the next action could be executed.';

                break;
            }

            if ($attackerMap < $mapCost) {
                $assumptions[] = sprintf('The %s action could not be executed with the remaining MAP.', $action['type']);

                break;
            }

            $request = $this->buildRequest($attackerState, $defenderState, $action, [
                ...$context,
                'air_superiority_owner' => $airSuperiorityOwner,
                'ground_control_owner' => $groundControlOwner,
                'blockade_owner' => $blockadeOwner,
            ]);
            $consumables = $this->simulator()->actionConsumables($request);
            if ($attackerResourceBudget !== null) {
                $unaffordable = $this->unaffordableResources($consumables, $attackerResourceBudget);
                if ($unaffordable !== []) {
                    $assumptions[] = sprintf('The %s action was not simulated because attacker %s were insufficient or unknown.', $action['type'], implode(', ', $unaffordable));
                    $executionBlocked = true;

                    break;
                }
            }

            $result = $this->simulator()->simulateAction($request, $rng);
            $actionsExecuted++;
            $attackerMap -= $mapCost;
            $attackerResourceBudget = $this->subtractBudget($attackerResourceBudget, $consumables);
            $costResources = $this->mergeResources($costResources, $consumables);
            $components['consumables'] = $this->addComponentValue(
                $components['consumables'],
                $this->componentsValue($consumables, $prices['acquisition']),
            );

            $attackerLosses = $this->normalizeUnitMap((array) ($result['attacker_losses'] ?? []));
            $defenderLosses = $this->normalizeUnitMap((array) ($result['defender_losses'] ?? []));
            $attackerState = $this->applyUnitLosses($attackerState, $attackerLosses);
            $defenderState = $this->applyUnitLosses($defenderState, $defenderLosses);

            foreach ($attackerLossTotals as $unit => $loss) {
                $attackerLossTotals[$unit] += $attackerLosses[$unit] ?? 0.0;
            }
            $chargeableLosses = [];
            foreach ($attackerLossTotals as $unit => $loss) {
                // The action simulators expose fractional expected casualties.
                // Carry those through the mutable state, then charge each
                // whole replacement only when the cumulative loss crosses it.
                $roundedTotal = (int) ceil(max(0.0, $loss) - 0.000000001);
                $chargeableLosses[$unit] = max(0, $roundedTotal - $chargedLossTotals[$unit]);
                $chargedLossTotals[$unit] = max($chargedLossTotals[$unit], $roundedTotal);
            }
            $militaryCosts = $this->militaryReplacementCosts($chargeableLosses, $attacker, $context);
            $costResources = $this->mergeResources($costResources, $militaryCosts);
            $components['military_losses'] = $this->addComponentValue(
                $components['military_losses'],
                $this->componentsValue($militaryCosts, $prices['acquisition']),
            );

            $infraDestroyed = max(0.0, (float) ($result['infra_destroyed'] ?? 0.0));
            $infraCosts = $this->infrastructureReplacementCosts($infraDestroyed, $defenderState, $attacker, $context);
            $costResources = $this->mergeResources($costResources, $infraCosts);
            $components['infrastructure_losses'] = $this->addComponentValue(
                $components['infrastructure_losses'],
                $this->componentsValue($infraCosts, $prices['acquisition']),
            );
            $defenderState['highest_city_infra'] = max(
                0.0,
                (float) ($defenderState['highest_city_infra'] ?? 0.0) - $infraDestroyed,
            );

            $groundLoot = 0.0;
            if ($action['type'] === 'ground') {
                if ($defenderState['money'] === null || ! is_numeric($result['money_looted'] ?? null)) {
                    $components['ground_loot'] = null;
                    $assumptions[] = 'Ground cash loot was unavailable because the target money balance was not known at evaluation time.';
                } else {
                    $groundLoot = min(
                        max(0.0, (float) $result['money_looted']),
                        max(0.0, (float) $defenderState['money']),
                    );
                    $defenderState['money'] = max(0.0, (float) $defenderState['money'] - $groundLoot);
                }
            }

            if ($groundLoot > 0.0) {
                $lootResources['money'] = ($lootResources['money'] ?? 0.0) + $groundLoot;
                $components['ground_loot'] += $groundLoot;
            }

            $outcomeTier = max(0, min(3, (int) ($result['outcome'] ?? 0)));
            $resistance = max(0, $resistance - $this->simulator()->resistanceDamage($action['type'], $outcomeTier));

            if ($action['type'] === 'air' && $outcomeTier >= 2) {
                $airSuperiorityOwner = 'attacker';
            }

            if ($action['type'] === 'ground' && $outcomeTier >= 2) {
                $groundControlOwner = 'attacker';
            }

            if ($action['type'] === 'naval' && $outcomeTier >= 2) {
                $blockadeOwner = 'attacker';
            }

            if ($resistance === 0) {
                $won = true;
                $victoryRequest = $this->buildRequest($attackerState, $defenderState, $action, [
                    ...$context,
                    'air_superiority_owner' => $airSuperiorityOwner,
                    'ground_control_owner' => $groundControlOwner,
                    'blockade_owner' => $blockadeOwner,
                ]);
                $victoryFraction = $this->victoryLootFraction($victoryRequest, $context);
                $victoryStockpile = $stockpileResources;
                if ($defenderState['money'] !== null) {
                    $victoryStockpile['money'] = max(0.0, (float) $defenderState['money']);
                }
                $nationLoot = $this->scaleResources($victoryStockpile, $victoryFraction);
                if (array_key_exists('money', $nationLoot) && $defenderState['money'] !== null) {
                    $nationLoot['money'] = min(
                        (float) $nationLoot['money'],
                        max(0.0, (float) $defenderState['money']),
                    );
                }
                $lootResources = $this->mergeResources($lootResources, $nationLoot);
                $components['nation_loot'] = $unknownStockpileResources === []
                    ? $this->componentsValue($nationLoot, $prices['liquidation'])
                    : null;

                if ($bankResources !== null) {
                    $bankFraction = $this->bankLootFraction($context, $rng, $victoryRequest);
                    if ($bankFraction === null) {
                        $components['bank_loot'] = null;
                        $assumptions[] = 'Alliance-bank holdings were present, but a verified bank-loot fraction was unavailable; bank loot is excluded from the estimate.';
                    } else {
                        $bankLoot = $this->scaleResources($bankResources, $bankFraction);
                        $lootResources = $this->mergeResources($lootResources, $bankLoot);
                        $components['bank_loot'] = $this->componentsValue($bankLoot, $prices['liquidation']);
                    }
                } else {
                    $components['bank_loot'] = null;
                    $assumptions[] = 'Alliance-bank holdings were unavailable; bank loot is excluded from the estimate.';
                }

                if (($context['bounty_known'] ?? false) === true) {
                    $components['bounty'] = max(0.0, (float) ($context['bounty'] ?? 0.0));
                } else {
                    $components['bounty'] = null;
                    $assumptions[] = 'The eligible raid bounty was not present in the frozen input; gross loot remains unavailable until it is verified.';
                }

                $defenderReturnProbability = (float) ($context['defender_return_probability'] ?? 0.0);
                if ($defenderReturnProbability > 0.0) {
                    $assumptions[] = 'Defender return probability and recovered-loot share are heuristic inputs, separate from battle randomness.';
                    if ($rng->nextFloat(0.0, 1.0) < $defenderReturnProbability) {
                        $recoveredShare = max(0.0, min(1.0, (float) ($context['defender_return_recovery_fraction'] ?? 0.5)));
                        $retainedShare = 1.0 - $recoveredShare;
                        $lootResources = $this->scaleResources($lootResources, $retainedShare);
                        foreach (['ground_loot', 'nation_loot', 'bank_loot'] as $component) {
                            $components[$component] = $this->scaleNullable($components[$component], $retainedShare);
                        }
                        $assumptions[] = 'The simulated defender return recovered the configured share of loot.';
                    }
                }

                break;
            }
        }

        if (! $won) {
            $assumptions[] = 'The simulated approach did not reduce resistance to zero within the available MAP.';
        }

        if ($unknownStockpileResources !== []) {
            $assumptions[] = 'The following stockpile resources were unknown at evaluation time: '.implode(', ', $unknownStockpileResources).'.';
        }

        $lootValue = $this->componentsValue($lootResources, $prices['liquidation']);
        $unknownGrossComponents = [];
        foreach (['ground_loot', 'nation_loot', 'bank_loot', 'bounty'] as $component) {
            if ($components[$component] === null) {
                $unknownGrossComponents[] = $component;
            }
        }
        $gross = $lootValue === null || $unknownGrossComponents !== []
            ? null
            : $lootValue + (float) $components['bounty'];
        $grossBasis = 'complete';
        if ($gross === null && $lootValue !== null && $components['bounty'] !== null) {
            // Every omitted loot component is non-negative. The known portion
            // is therefore a valid lower bound, provided its prices are known.
            $gross = $lootValue + (float) $components['bounty'];
            $grossBasis = 'lower_bound';
            $assumptions[] = 'Gross loot excludes unknown non-negative components and is reported as an explicit lower bound.';
        } elseif ($gross === null) {
            $grossBasis = 'unavailable';
        }
        $cost = $this->componentsValue($costResources, $prices['acquisition']);
        $net = $gross === null || $cost === null ? null : $gross - $cost;
        if ($executionBlocked && $actionsExecuted === 0) {
            $net = null;
            $gross = null;
        }
        $duration = $won ? $elapsedHours : $expirationHours;

        if ($this->mapHasUnknownPrices($lootResources, $prices['liquidation']) || $this->mapHasUnknownPrices($costResources, $prices['acquisition'])) {
            $assumptions[] = 'One or more non-zero resources had no usable price; monetary totals are unavailable.';
            $grossBasis = 'unavailable';
            $net = null;
            $gross = null;
        }

        if ((float) ($context['defender_return_probability'] ?? 0.0) > 0.0 && $won) {
            $assumptions[] = 'Defender return risk is supplied as a scenario input and is kept separate from battle-simulator results.';
        }

        return [
            'net' => $net,
            'gross' => $gross,
            'won' => $won,
            'duration_hours' => $duration,
            'components' => $components,
            'loot_resources' => $lootResources,
            'cost_resources' => $costResources,
            'assumptions' => array_values(array_unique($assumptions)),
            'actions_executed' => $actionsExecuted,
            'execution_blocked' => $executionBlocked,
            'map_remaining' => $attackerMap,
            'valuation' => [
                'basis' => $net === null ? 'unavailable' : $grossBasis,
                'unknown_components' => $unknownGrossComponents,
            ],
            'controls' => [
                'air_superiority' => $airSuperiorityOwner,
                'ground_control' => $groundControlOwner,
                'blockade' => $blockadeOwner,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $actionDefinition
     * @param  array<string, int|float|null>  $attacker
     * @param  array<string, int|float|null>  $defender
     * @return array<string, mixed>|null
     */
    private function buildAction(array $actionDefinition, array $attacker, array $defender): ?array
    {
        $type = (string) ($actionDefinition['type'] ?? 'ground');
        $action = [
            'type' => $type,
            'attacking_soldiers' => 0,
            'attacking_tanks' => 0,
            'arm_soldiers_with_munitions' => true,
            'attacking_aircraft' => 0,
            'target' => (string) ($actionDefinition['target'] ?? 'infra'),
            'attacking_ships' => 0,
        ];

        if ($type === 'air') {
            $aircraft = $this->fractionOfUnits($attacker['aircraft'], $actionDefinition['fraction'] ?? 1.0);
            if ($aircraft < 1 || (int) $defender['aircraft'] < 1) {
                return null;
            }

            $action['attacking_aircraft'] = $aircraft;

            return $action;
        }

        if ($type === 'naval') {
            $ships = $this->fractionOfUnits($attacker['ships'], $actionDefinition['fraction'] ?? 1.0);
            if ($ships < 1 || (int) $defender['ships'] < 1) {
                return null;
            }

            $action['attacking_ships'] = $ships;

            return $action;
        }

        $soldiers = $this->fractionOfUnits($attacker['soldiers'], $actionDefinition['soldier_fraction'] ?? 1.0);
        $tanks = $this->fractionOfUnits($attacker['tanks'], $actionDefinition['tank_fraction'] ?? 1.0);
        if ($soldiers < 1 && $tanks < 1) {
            return null;
        }

        $action['attacking_soldiers'] = $soldiers;
        $action['attacking_tanks'] = $tanks;
        $action['arm_soldiers_with_munitions'] = (bool) ($actionDefinition['arm_soldiers_with_munitions'] ?? true);

        return $action;
    }

    private function actionMapCost(string $type, array $context): float
    {
        $configured = $context['action_map_costs'][$type] ?? null;
        $cost = $this->number($configured);

        return max(0.0, $cost ?? self::ACTION_MAP_COSTS[$type] ?? self::ACTION_MAP_COSTS['ground']);
    }

    /**
     * @return array{available: array<string, float>, unknown: list<string>}|null
     */
    private function resourceBudget(mixed $resources): ?array
    {
        if (! is_array($resources)) {
            return null;
        }

        $available = [];
        $unknown = [];
        foreach ($resources as $resource => $amount) {
            $numeric = $this->number($amount);
            if ($numeric === null) {
                $unknown[] = (string) $resource;

                continue;
            }

            $available[(string) $resource] = max(0.0, $numeric);
        }

        return [
            'available' => $available,
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * @param  array<string, float>  $required
     * @param  array{available: array<string, float>, unknown: list<string>}  $budget
     * @return list<string>
     */
    private function unaffordableResources(array $required, array $budget): array
    {
        $unaffordable = [];
        foreach ($required as $resource => $amount) {
            if ($amount <= 0.0) {
                continue;
            }

            if (in_array($resource, $budget['unknown'], true)
                || ! array_key_exists($resource, $budget['available'])
                || $budget['available'][$resource] < $amount) {
                $unaffordable[] = $resource;
            }
        }

        return $unaffordable;
    }

    /**
     * @param  array{available: array<string, float>, unknown: list<string>}|null  $budget
     * @param  array<string, float>  $spent
     * @return array{available: array<string, float>, unknown: list<string>}|null
     */
    private function subtractBudget(?array $budget, array $spent): ?array
    {
        if ($budget === null) {
            return null;
        }

        foreach ($spent as $resource => $amount) {
            if (array_key_exists($resource, $budget['available'])) {
                $budget['available'][$resource] = max(0.0, $budget['available'][$resource] - $amount);
            }
        }

        return $budget;
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $defender
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     */
    private function buildRequest(array $attacker, array $defender, array $action, array $context): WarSimRequestData
    {
        return WarSimRequestData::fromArray([
            'iterations' => 1,
            'seed' => $context['seed'] ?? null,
            'nation_attacker' => $attacker,
            'nation_defender' => $defender,
            'context' => [
                'war_type' => $context['war_type'],
                'attacker_policy' => $context['attacker_policy'],
                'defender_policy' => $context['defender_policy'],
                'air_superiority_owner' => $context['air_superiority_owner'],
                'ground_control_owner' => $context['ground_control_owner'],
                'blockade_owner' => $context['blockade_owner'],
                'blitz_active_attacker' => $context['blitz_active_attacker'],
                'blitz_active_defender' => $context['blitz_active_defender'],
                'attacker_pirate_economy' => $context['attacker_pirate_economy'] ?? false,
                'attacker_advanced_pirate_economy' => $context['attacker_advanced_pirate_economy'] ?? false,
            ],
            'action' => $action,
        ]);
    }

    /**
     * @param  array<string, mixed>  $nation
     * @return list<string>
     */
    private function missingMilitaryFields(array $nation): array
    {
        $military = is_array($nation['military'] ?? null) ? $nation['military'] : [];
        $missing = [];
        foreach (['soldiers', 'tanks', 'aircraft', 'ships'] as $unit) {
            $value = array_key_exists($unit, $military)
                ? $military[$unit]
                : ($nation[$unit] ?? null);
            if ($this->number($value) === null) {
                $missing[] = $unit;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $nation
     * @return array<string, mixed>
     */
    private function normalizeNation(array $nation): array
    {
        $military = is_array($nation['military'] ?? null) ? $nation['military'] : [];
        $cities = is_array($nation['cities'] ?? null) ? $nation['cities'] : [];
        $cityRows = array_values(array_filter($cities, 'is_array'));
        $infrastructures = collect($cityRows)
            ->map(fn (array $city): float => (float) ($city['infrastructure'] ?? $city['infra'] ?? 0.0))
            ->filter(fn (float $value): bool => $value > 0)
            ->values();
        $populations = collect($cityRows)
            ->map(fn (array $city): float => (float) ($city['population'] ?? 0.0))
            ->filter(fn (float $value): bool => $value > 0)
            ->values();
        $highestInfra = $this->number($nation['highest_city_infra'] ?? $nation['highest_infra'] ?? null);
        if ($highestInfra === null && $infrastructures->isNotEmpty()) {
            $highestInfra = (float) $infrastructures->max();
        }

        $highestPopulation = $this->number($nation['highest_city_population'] ?? $nation['highest_population'] ?? null);
        if ($highestPopulation === null && $populations->isNotEmpty()) {
            $highestPopulation = (float) $populations->max();
        }
        $averageInfra = $this->number($nation['avg_infra'] ?? null);
        if ($averageInfra === null && $infrastructures->isNotEmpty()) {
            $averageInfra = (float) $infrastructures->average();
        }

        $cityCount = $this->number($nation['cities_count'] ?? $nation['num_cities'] ?? null);
        if ($cityCount === null && is_numeric($nation['cities'] ?? null)) {
            $cityCount = (float) $nation['cities'];
        }
        if ($cityCount === null && $cityRows !== []) {
            $cityCount = count($cityRows);
        }

        $research = is_array($nation['research'] ?? null)
            ? $nation['research']
            : (is_array($nation['military_research'] ?? null) ? $nation['military_research'] : []);
        $projects = is_array($nation['projects'] ?? null) ? $nation['projects'] : [];

        return [
            'nation_id' => isset($nation['nation_id'])
                ? (int) $nation['nation_id']
                : (isset($nation['id']) && is_numeric($nation['id']) ? (int) $nation['id'] : null),
            'score' => $this->number($nation['score'] ?? $nation['nation_score'] ?? null),
            'soldiers' => $this->integer($military['soldiers'] ?? $nation['soldiers'] ?? 0),
            'tanks' => $this->integer($military['tanks'] ?? $nation['tanks'] ?? 0),
            'aircraft' => $this->integer($military['aircraft'] ?? $nation['aircraft'] ?? 0),
            'ships' => $this->integer($military['ships'] ?? $nation['ships'] ?? 0),
            'war_policy' => strtoupper((string) ($nation['war_policy'] ?? $nation['policy'] ?? 'NONE')),
            'is_fortified' => (bool) ($nation['is_fortified'] ?? $nation['fortified'] ?? false),
            'money' => $this->number($nation['money'] ?? null),
            'cities' => max(0, (int) ($cityCount ?? 0)),
            'highest_city_infra' => max(0.0, $highestInfra ?? 0.0),
            'highest_city_population' => max(0, (int) ($highestPopulation ?? 0)),
            'avg_infra' => $averageInfra,
            'research' => $research,
            'military_research' => is_array($nation['military_research'] ?? null) ? $nation['military_research'] : [],
            'projects' => $projects,
            'imperialism' => (bool) ($nation['imperialism'] ?? $this->projectEnabled($nation, 'imperialism')),
            'government_support_agency' => (bool) ($nation['government_support_agency'] ?? $this->projectEnabled($nation, 'government_support_agency')),
            'bureau_of_domestic_affairs' => (bool) ($nation['bureau_of_domestic_affairs'] ?? $this->projectEnabled($nation, 'bureau_of_domestic_affairs')),
            'resources' => is_array($nation['resources'] ?? null) ? $nation['resources'] : null,
            'alliance' => is_array($nation['alliance'] ?? null) ? $nation['alliance'] : null,
            'alliance_score' => $this->number($nation['alliance_score'] ?? null),
            'daily_net' => is_array($nation['daily_net'] ?? null) ? $nation['daily_net'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $attacker, array $target, array $context): array
    {
        $attackerPolicy = strtoupper((string) ($context['attacker_policy'] ?? $attacker['war_policy'] ?? $attacker['policy'] ?? 'NONE'));
        $defenderPolicy = strtoupper((string) ($context['defender_policy'] ?? $target['war_policy'] ?? $target['policy'] ?? 'NONE'));
        $alliance = is_array($target['alliance'] ?? null) ? $target['alliance'] : [];
        $allianceScore = $this->number(
            $context['alliance_score']
                ?? $target['alliance_score']
                ?? $alliance['score']
                ?? null,
        );
        $attackerScore = $this->number(
            $context['attacker_score']
                ?? $attacker['score']
                ?? $attacker['nation_score']
                ?? null,
        );
        $warType = strtoupper((string) ($context['war_type'] ?? 'RAID'));
        $bountySnapshot = $context['bounty_snapshot']
            ?? $context['bounty']
            ?? $context['bounties']
            ?? $target['bounties']
            ?? null;
        $bounty = $this->bountyAmount($bountySnapshot, $warType);
        $bountyKnown = $warType !== 'RAID'
            || ($bountySnapshot !== null && (
                array_key_exists('bounty_snapshot', $context)
                || array_key_exists('bounty', $context)
                || array_key_exists('bounties', $context)
                || array_key_exists('bounties', $target)
            ));
        $pirateEconomy = (bool) ($context['attacker_pirate_economy']
            ?? $attacker['pirate_economy']
            ?? $this->projectEnabled($attacker, 'pirate_economy'));
        $advancedPirateEconomy = (bool) ($context['attacker_advanced_pirate_economy']
            ?? $attacker['advanced_pirate_economy']
            ?? $this->projectEnabled($attacker, 'advanced_pirate_economy'));

        $defensiveWars = max(0, (int) round($this->number(
            $context['defensive_wars']
                ?? $target['defensive_wars_count']
                ?? $target['defensive_wars']
                ?? 0,
        ) ?? 0.0));
        $asOf = $context['as_of'] ?? $target['as_of'] ?? null;
        $lastActive = $target['last_active'] ?? $target['last_active_at'] ?? null;
        $activityHours = $this->elapsedHours($lastActive, $asOf);
        $hoursSinceObservation = array_key_exists('hours_since_observation', $context)
            ? max(0.0, $this->number($context['hours_since_observation']) ?? 0.0)
            : max(0.0, $this->elapsedHours($target['observed_at'] ?? null, $asOf) ?? 0.0);
        $returnProbability = $this->number($context['defender_return_probability'] ?? null);
        $competitionProbability = $this->number($context['competition_probability'] ?? null);
        $competitionDepletionFraction = $this->number($context['competition_depletion_fraction'] ?? null);
        $behaviorAssumptions = [
            'Behavior risks use the documented raid-behavior-v1 heuristic until enough finalized outcomes exist for calibration.',
            'Counter risk is a heuristic signal based on target activity, defensive pressure, and remaining military strength; it is not a battle outcome probability.',
        ];
        if ($pirateEconomy || $advancedPirateEconomy) {
            $behaviorAssumptions[] = 'Pirate-economy project adjustments are model inputs and remain subject to validation against finalized raid outcomes.';
        }
        if ($returnProbability === null) {
            $returnProbability = $this->estimateDefenderReturnProbability($activityHours, $defensiveWars);
            $behaviorAssumptions[] = 'Defender return probability was estimated from target activity and defensive-war pressure.';
        }
        if ($competitionProbability === null) {
            $competitionProbability = min(0.75, 0.10 + ($defensiveWars * 0.10));
            $behaviorAssumptions[] = 'Competition probability was estimated from the target’s currently occupied defensive pressure.';
        }
        if ($competitionDepletionFraction === null) {
            $competitionDepletionFraction = min(0.30, 0.05 + ($defensiveWars * 0.05));
            $behaviorAssumptions[] = 'The competition scenario removes a bounded fraction of the reconstructed nation balance before the plan runs.';
        }
        $counterRisk = $this->number($context['counter_risk'] ?? null);
        if ($counterRisk === null) {
            $counterRisk = $this->estimateCounterRisk($attacker, $target, $activityHours, $defensiveWars);
        }
        $actionMapCosts = self::ACTION_MAP_COSTS;
        if (is_array($context['action_map_costs'] ?? null)) {
            foreach ($context['action_map_costs'] as $type => $cost) {
                $numericCost = $this->number($cost);
                if ($numericCost !== null && array_key_exists((string) $type, $actionMapCosts)) {
                    $actionMapCosts[(string) $type] = max(0.0, $numericCost);
                }
            }
        }
        $expirationHours = $this->number(
            $context['expiration_hours']
                ?? $context['war_expiration_hours']
                ?? $context['slot_hours']
                ?? null,
        ) ?? self::DEFAULT_EXPIRATION_HOURS;

        return [
            'war_type' => $warType,
            'attacker_policy' => $attackerPolicy,
            'defender_policy' => $defenderPolicy,
            'air_superiority_owner' => strtolower((string) ($context['air_superiority_owner'] ?? $context['air_control'] ?? 'none')),
            'ground_control_owner' => strtolower((string) ($context['ground_control_owner'] ?? $context['ground_control'] ?? 'none')),
            'blockade_owner' => strtolower((string) ($context['blockade_owner'] ?? $context['blockade'] ?? 'none')),
            'blitz_active_attacker' => (bool) ($context['blitz_active_attacker'] ?? false),
            'blitz_active_defender' => (bool) ($context['blitz_active_defender'] ?? false),
            'iterations' => $context['iterations'] ?? self::DEFAULT_ITERATIONS,
            'seed' => isset($context['seed']) ? (int) $context['seed'] : null,
            'attacker_map' => max(0.0, min(self::MAX_MAP, $this->number($context['attacker_map'] ?? $context['map'] ?? self::DEFAULT_STARTING_MAP) ?? self::DEFAULT_STARTING_MAP)),
            'defender_map' => max(0.0, min(self::MAX_MAP, $this->number($context['defender_map'] ?? self::DEFAULT_STARTING_MAP) ?? self::DEFAULT_STARTING_MAP)),
            'max_map' => max(1.0, $this->number($context['max_map'] ?? self::MAX_MAP) ?? self::MAX_MAP),
            'turn_hours' => max(0.01, $this->number($context['turn_hours'] ?? self::DEFAULT_TURN_HOURS) ?? self::DEFAULT_TURN_HOURS),
            'expiration_hours' => max(0.0, $expirationHours),
            'action_map_costs' => $actionMapCosts,
            'resistance' => max(1.0, $this->number($context['resistance'] ?? $target['resistance'] ?? 100.0) ?? 100.0),
            'hours_since_observation' => $hoursSinceObservation,
            'production_per_day' => is_array($context['production_per_day'] ?? null)
                ? $context['production_per_day']
                : (is_array($target['daily_net'] ?? null) ? $target['daily_net'] : []),
            'known_depletion' => is_array($context['known_depletion'] ?? null) ? $context['known_depletion'] : [],
            'competition_depletion' => is_array($context['competition_depletion'] ?? null) ? $context['competition_depletion'] : [],
            'bank_loot_fraction' => $this->number($context['bank_loot_fraction'] ?? null),
            'attacker_score' => $attackerScore,
            'alliance_score' => $allianceScore,
            'attacker_pirate_economy' => $pirateEconomy,
            'attacker_advanced_pirate_economy' => $advancedPirateEconomy,
            'bounty' => $bounty,
            'bounty_known' => $bountyKnown,
            'defender_return_probability' => max(0.0, min(1.0, $returnProbability)),
            'defender_return_recovery_fraction' => max(0.0, min(1.0, $this->number($context['defender_return_recovery_fraction'] ?? 0.5) ?? 0.5)),
            'competition_probability' => max(0.0, min(1.0, $competitionProbability)),
            'competition_depletion_fraction' => max(0.0, min(1.0, $competitionDepletionFraction)),
            'counter_risk' => max(0.0, min(1.0, $counterRisk)),
            'defensive_wars' => $defensiveWars,
            'activity_hours' => $activityHours,
            'behavior_assumptions' => array_values(array_unique($behaviorAssumptions)),
            'victory_loot_fraction' => $this->number($context['victory_loot_fraction'] ?? null),
            'confidence' => strtolower((string) ($context['confidence'] ?? '')),
            'attacker_funds_available' => array_key_exists('attacker_funds_available', $context)
                ? (bool) $context['attacker_funds_available']
                : true,
        ];
    }

    private function bountyAmount(mixed $snapshot, string $warType): float
    {
        if ($warType !== 'RAID') {
            return 0.0;
        }

        if (is_numeric($snapshot)) {
            return max(0.0, (float) $snapshot);
        }

        if (! is_array($snapshot)) {
            return 0.0;
        }

        if (array_key_exists('amount', $snapshot) || array_key_exists('value', $snapshot)) {
            $snapshotType = strtoupper((string) ($snapshot['war_type'] ?? $snapshot['type'] ?? 'RAID'));

            return $snapshotType === 'RAID'
                ? max(0.0, $this->number($snapshot['amount'] ?? $snapshot['value'] ?? null) ?? 0.0)
                : 0.0;
        }

        $amount = 0.0;
        foreach ($snapshot as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryType = strtoupper((string) ($entry['war_type'] ?? $entry['type'] ?? ''));
            if ($entryType === 'RAID') {
                $amount += max(0.0, $this->number($entry['amount'] ?? $entry['value'] ?? null) ?? 0.0);
            }
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @return list<array<string, mixed>>
     */
    private function approachDefinitions(array $attacker, array $target, array $context): array
    {
        $hasGround = ($attacker['soldiers'] + $attacker['tanks']) > 0;
        $hasAirSupport = $attacker['aircraft'] > 0 && $target['aircraft'] > 0;
        $hasNavalSupport = $attacker['ships'] > 0 && $target['ships'] > 0;
        $groundReason = $hasGround ? 'Uses the available ground force and follows up while MAP and resistance remain.' : 'No soldiers or tanks are available.';
        $groundActions = $this->groundActionDefinitions($context);

        return [
            [
                'key' => 'ground_focused',
                'label' => 'Ground focused',
                'feasible' => $hasGround,
                'reason' => $groundReason,
                'actions' => $groundActions,
            ],
            [
                'key' => 'air_supported',
                'label' => 'Air supported',
                'feasible' => $hasGround && $hasAirSupport,
                'reason' => $hasGround && $hasAirSupport
                    ? 'Uses an air action to establish superiority before the ground sequence.'
                    : 'Requires both a ground force and aircraft while the target has aircraft available.',
                'actions' => [
                    ['type' => 'air', 'target' => 'aircraft', 'fraction' => 1.0],
                    ...$groundActions,
                ],
            ],
            [
                'key' => 'naval_supported',
                'label' => 'Naval supported',
                'feasible' => $hasGround && $hasNavalSupport,
                'reason' => $hasGround && $hasNavalSupport
                    ? 'Uses a naval action to establish blockade pressure before the ground sequence.'
                    : 'Requires both a ground force and ships while the target has ships available.',
                'actions' => [
                    ['type' => 'naval', 'target' => 'infra', 'fraction' => 1.0],
                    ...$groundActions,
                ],
            ],
        ];
    }

    /**
     * Build enough follow-up ground actions for the observed resistance while
     * retaining a hard bound for incomplete or adversarial inputs. The MAP
     * and expiration checks in the simulator decide how many can actually be
     * executed.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function groundActionDefinitions(array $context): array
    {
        $resistance = max(1, (int) ceil((float) ($context['resistance'] ?? 100.0)));
        $actionCount = max(3, min(self::MAX_PLAN_ACTIONS, $resistance));
        $action = [
            'type' => 'ground',
            'target' => 'infra',
            'soldier_fraction' => 1.0,
            'tank_fraction' => 1.0,
        ];

        return array_fill(0, $actionCount, $action);
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function militarySuitability(array $attacker, array $target): array
    {
        $attackerGround = $attacker['soldiers'] + ($attacker['tanks'] * 40.0);
        $targetGround = $target['soldiers'] + ($target['tanks'] * 40.0) + ($target['highest_city_population'] / 400.0);
        $groundRatio = $targetGround > 0 ? $attackerGround / $targetGround : null;
        $airRatio = $target['aircraft'] > 0 ? $attacker['aircraft'] / $target['aircraft'] : null;
        $navalRatio = $target['ships'] > 0 ? $attacker['ships'] / $target['ships'] : null;
        $warnings = [];

        if ($groundRatio !== null && $groundRatio < 1.0) {
            $warnings[] = 'The attacking ground strength is below the estimated defending strength.';
        }

        if ($target['aircraft'] > 0 && $airRatio !== null && $airRatio < 1.0) {
            $warnings[] = 'The target has more aircraft available for an air contest.';
        }

        if ($target['ships'] > 0 && $navalRatio !== null && $navalRatio < 1.0) {
            $warnings[] = 'The target has more ships available for a naval contest.';
        }

        return [
            'feasible' => $attackerGround > 0,
            'score' => $groundRatio === null ? null : round(max(0.0, min(2.0, $groundRatio)) * 50.0, 2),
            'attacker_ground_strength' => round($attackerGround, 2),
            'defender_ground_strength' => round($targetGround, 2),
            'ground_ratio' => $this->roundNullable($groundRatio),
            'air_ratio' => $this->roundNullable($airRatio),
            'naval_ratio' => $this->roundNullable($navalRatio),
            'attacker' => [
                'soldiers' => $attacker['soldiers'],
                'tanks' => $attacker['tanks'],
                'aircraft' => $attacker['aircraft'],
                'ships' => $attacker['ships'],
            ],
            'target' => [
                'soldiers' => $target['soldiers'],
                'tanks' => $target['tanks'],
                'aircraft' => $target['aircraft'],
                'ships' => $target['ships'],
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $stockpile
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function normalizeStockpileScenarios(array $stockpile, array $target, array $context): array
    {
        $rows = [];
        $rawScenarios = is_array($stockpile['scenarios'] ?? null) ? $stockpile['scenarios'] : [];
        if ($rawScenarios !== []) {
            foreach ($rawScenarios as $index => $rawScenario) {
                if (! is_array($rawScenario)) {
                    continue;
                }

                $resources = $this->scenarioResources($rawScenario);

                $scenarioKey = (string) ($rawScenario['key'] ?? $index);
                $defaultWeight = match ($scenarioKey) {
                    'lower', 'low', 'conservative' => 0.25,
                    'upper', 'high', 'optimistic' => 0.25,
                    default => 0.5,
                };

                $rows[] = [
                    'key' => (string) ($rawScenario['key'] ?? "scenario_{$index}"),
                    'label' => (string) ($rawScenario['label'] ?? $rawScenario['name'] ?? ucfirst((string) $index)),
                    'weight' => max(0.0, (float) ($rawScenario['weight'] ?? $rawScenario['probability'] ?? $defaultWeight)),
                    'resources' => $this->resourceMap($resources),
                    'unknown_resources' => $this->unknownResources($resources, true),
                    'bank_resources' => array_key_exists('bank_resources', $rawScenario)
                        ? $this->normalizeBankResources($rawScenario['bank_resources'])
                        : $this->normalizeBankResources($stockpile['bank_resources'] ?? null),
                    'production_per_day' => is_array($rawScenario['production_per_day'] ?? null)
                        ? $rawScenario['production_per_day']
                        : null,
                    'depletion' => is_array($rawScenario['depletion'] ?? null)
                        ? $rawScenario['depletion']
                        : [],
                    'assumptions' => array_values(array_unique(array_merge(
                        (array) ($stockpile['assumptions'] ?? []),
                        (array) ($rawScenario['assumptions'] ?? []),
                    ))),
                ];
            }
        } else {
            $resources = $stockpile['resources'] ?? $this->scenarioResources($stockpile);
            if (! is_array($resources)) {
                $resources = [];
            }

            if ($resources === [] && array_key_exists('money', $target)) {
                $resources['money'] = $target['money'];
            }

            $rows[] = [
                'key' => 'baseline',
                'label' => 'Baseline',
                'weight' => 1.0,
                'resources' => $this->resourceMap($resources),
                'unknown_resources' => $this->unknownResources($resources, true),
                'bank_resources' => $this->normalizeBankResources($stockpile['bank_resources'] ?? null),
                'production_per_day' => is_array($stockpile['production_per_day'] ?? null)
                    ? $stockpile['production_per_day']
                    : null,
                'depletion' => is_array($stockpile['depletion'] ?? null)
                    ? $stockpile['depletion']
                    : [],
                'assumptions' => array_values(array_unique((array) ($stockpile['assumptions'] ?? []))),
            ];
        }

        $competitionDepletion = $context['competition_depletion'];
        $competitionFraction = max(0.0, min(1.0, (float) ($context['competition_depletion_fraction'] ?? 0.0)));
        if ($competitionDepletion !== [] || $competitionFraction > 0.0) {
            $baselineIndex = collect($rows)
                ->search(fn (array $row): bool => in_array($row['key'], ['base', 'baseline'], true));
            $baselineIndex = $baselineIndex === false ? 0 : $baselineIndex;
            $baseline = $rows[$baselineIndex] ?? null;
            if ($baseline !== null && $baseline['resources'] !== []) {
                $competitionResources = $competitionDepletion !== []
                    ? $this->subtractResources(
                        $baseline['resources'],
                        $this->resourceMap($competitionDepletion),
                    )
                    : $this->scaleResources($baseline['resources'], 1.0 - $competitionFraction);
                $competitionWeight = max(0.0, min(1.0, $this->number($context['competition_probability'] ?? 0.15) ?? 0.15));
                $rows[] = [
                    'key' => 'competition',
                    'label' => 'Competing depletion',
                    'weight' => $competitionWeight,
                    'resources' => $competitionResources,
                    'unknown_resources' => $baseline['unknown_resources'],
                    'bank_resources' => $baseline['bank_resources'],
                    'assumptions' => [
                        $competitionDepletion !== []
                            ? 'Known competing loot is removed before this scenario is evaluated.'
                            : sprintf('The competition heuristic removes %.1f%% of the reconstructed nation balance before this scenario is evaluated.', $competitionFraction * 100),
                    ],
                ];
                $rows[$baselineIndex]['weight'] = max(0.0, $rows[$baselineIndex]['weight'] - $competitionWeight);
            }
        }

        $weightTotal = array_sum(array_column($rows, 'weight'));
        if ($weightTotal <= 0.0) {
            $rows[0]['weight'] = 1.0;
            $weightTotal = 1.0;
        }

        return array_map(function (array $row) use ($weightTotal): array {
            $row['weight'] = (float) $row['weight'] / $weightTotal;

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function scenarioResources(array $scenario): array
    {
        $resources = $scenario['resources'] ?? $scenario['stockpile'] ?? null;
        if (is_array($resources)) {
            return $resources;
        }

        return array_diff_key($scenario, array_flip([
            'key',
            'label',
            'name',
            'weight',
            'probability',
            'assumptions',
            'bank_resources',
            'depletion',
            'production_per_day',
            'confidence',
            'observed_at',
            'provenance_war_ids',
            'status',
            'model_version',
        ]));
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeBankResources(mixed $resources): ?array
    {
        if (! is_array($resources) || $this->unknownResources($resources, true) !== []) {
            return null;
        }

        return $this->resourceMap($resources);
    }

    /**
     * @param  array<string, float>  $resources
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function projectStockpile(array $resources, array $scenario, array $context): array
    {
        $hours = max(0.0, (float) ($context['hours_since_observation'] ?? 0.0));
        $production = $scenario['production_per_day'] ?? $context['production_per_day'];
        $unknownResources = array_fill_keys((array) ($scenario['unknown_resources'] ?? []), true);
        if ($hours > 0.0 && is_array($production)) {
            foreach ($this->signedResourceMap($production) as $resource => $amount) {
                // A known net flow cannot turn an unknown starting balance
                // into a known balance. Keep that resource unavailable until
                // a later observation supplies an anchor.
                if (isset($unknownResources[$resource]) || ! array_key_exists($resource, $resources)) {
                    continue;
                }

                $resources[$resource] = max(0.0, $resources[$resource] + ($amount * $hours / 24.0));
            }
        }

        $depletion = $this->resourceMap((array) ($context['known_depletion'] ?? []));
        if (is_array($scenario['depletion'] ?? null)) {
            $depletion = $this->mergeResources($depletion, $this->resourceMap($scenario['depletion']));
        }

        return $this->subtractResources($resources, $depletion);
    }

    /**
     * @param  array<string, mixed>  $prices
     * @return array{acquisition: array<string, float>, liquidation: array<string, float>}
     */
    private function normalizePrices(array $prices): array
    {
        $acquisition = $prices['acquisition'] ?? $prices['buy'] ?? $prices;
        $liquidation = $prices['liquidation'] ?? $prices['sell'] ?? $prices;

        return [
            'acquisition' => $this->priceMap(is_array($acquisition) ? $acquisition : []),
            'liquidation' => $this->priceMap(is_array($liquidation) ? $liquidation : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $prices
     * @return array<string, float>
     */
    private function priceMap(array $prices): array
    {
        $normalized = ['money' => 1.0];
        foreach ($prices as $resource => $price) {
            $numeric = $this->number($price);
            if ($numeric !== null && $numeric > 0) {
                $normalized[(string) $resource] = $numeric;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, float>  $resources
     * @param  array<string, float>  $prices
     */
    private function componentsValue(array $resources, array $prices): ?float
    {
        $total = 0.0;
        foreach ($resources as $resource => $amount) {
            if (abs((float) $amount) < 0.000000001) {
                continue;
            }

            $price = $prices[$resource] ?? null;
            if ($price === null || $price <= 0) {
                return null;
            }

            $total += (float) $amount * $price;
        }

        return $total;
    }

    private function addComponentValue(?float $current, ?float $addition): ?float
    {
        if ($current === null || $addition === null) {
            return null;
        }

        return $current + $addition;
    }

    /**
     * @param  array<string, float>  $resources
     * @param  array<string, float>  $prices
     */
    private function mapHasUnknownPrices(array $resources, array $prices): bool
    {
        foreach ($resources as $resource => $amount) {
            if (abs((float) $amount) > 0.000000001 && (! isset($prices[$resource]) || $prices[$resource] <= 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, float>  $losses
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function militaryReplacementCosts(array $losses, array $attacker, array $context): array
    {
        $quantities = [
            'soldiers' => (int) ceil(max(0.0, $losses['soldiers'] ?? 0.0)),
            'tanks' => (int) ceil(max(0.0, $losses['tanks'] ?? 0.0)),
            'aircraft' => (int) ceil(max(0.0, $losses['aircraft'] ?? 0.0)),
            'ships' => (int) ceil(max(0.0, $losses['ships'] ?? 0.0)),
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 0,
        ];
        if (array_sum($quantities) === 0) {
            return [];
        }

        $research = is_array($attacker['research'] ?? null) ? $attacker['research'] : [];
        foreach (MilitaryCostCalculator::RESEARCH_FIELDS as $field) {
            $research[$field] = max(0, min(20, (int) ($research[$field]
                ?? $attacker[$field]
                ?? $attacker[$field.'_research']
                ?? 0)));
        }
        $result = $this->costCalculator()->calculate(
            $quantities,
            $research,
            true,
            (bool) ($attacker['imperialism'] ?? $this->projectEnabled($attacker, 'imperialism')),
            (bool) ($attacker['government_support_agency'] ?? $this->projectEnabled($attacker, 'government_support_agency')),
            (bool) ($attacker['bureau_of_domestic_affairs'] ?? $this->projectEnabled($attacker, 'bureau_of_domestic_affairs')),
            null,
        );
        $breakdown = $result->breakdowns['purchase'];

        return $this->mergeResources(
            ['money' => $breakdown->money],
            $breakdown->resources,
        );
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function infrastructureReplacementCosts(float $infraDestroyed, array $target, array $attacker, array $context): array
    {
        if ($infraDestroyed <= 0.0) {
            return [];
        }

        $highestInfra = max(0.0, (float) ($target['highest_city_infra'] ?? 0.0));
        if ($highestInfra <= 0.0) {
            return [];
        }

        $current = max(0.0, $highestInfra - $infraDestroyed);
        $calculator = $this->gamePurchaseCalculator();
        $cost = $calculator->infrastructureBaseCost($current, $highestInfra);

        return ['money' => max(0.0, $cost)];
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function militaryState(array $nation): array
    {
        return [
            'soldiers' => max(0.0, (float) ($nation['soldiers'] ?? 0)),
            'tanks' => max(0.0, (float) ($nation['tanks'] ?? 0)),
            'aircraft' => max(0.0, (float) ($nation['aircraft'] ?? 0)),
            'ships' => max(0.0, (float) ($nation['ships'] ?? 0)),
            'money' => $nation['money'] ?? null,
            'war_policy' => $nation['war_policy'] ?? 'NONE',
            'is_fortified' => (bool) ($nation['is_fortified'] ?? false),
            'cities' => (int) ($nation['cities'] ?? 0),
            'highest_city_infra' => (float) ($nation['highest_city_infra'] ?? 0.0),
            'highest_city_population' => (int) ($nation['highest_city_population'] ?? 0),
            'avg_infra' => $nation['avg_infra'] ?? null,
        ];
    }

    /**
     * @param  array<string, int|float|null>  $state
     * @param  array<string, float>  $losses
     * @return array<string, int|float|null>
     */
    private function applyUnitLosses(array $state, array $losses): array
    {
        foreach (['soldiers', 'tanks', 'aircraft', 'ships'] as $unit) {
            $state[$unit] = max(0.0, (float) ($state[$unit] ?? 0.0) - (float) ($losses[$unit] ?? 0.0));
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, float>
     */
    private function normalizeUnitMap(array $state): array
    {
        return collect(['soldiers', 'tanks', 'aircraft', 'ships'])
            ->mapWithKeys(fn (string $unit): array => [$unit => max(0.0, (float) ($state[$unit] ?? 0.0))])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function emptyEvaluation(
        array $approaches,
        array $militarySuitability,
        array $scenarios,
        array $prices,
        array $context,
        array $additionalAssumptions = [],
    ): array {
        return [
            'model_version' => self::MODEL_VERSION,
            'status' => 'unavailable',
            'expected_net' => null,
            'gross_loot' => null,
            'conservative_net' => null,
            'slot_efficiency' => null,
            'duration_hours' => null,
            'win_probability' => 0.0,
            'counter_risk' => [
                'probability' => round(max(0.0, min(1.0, (float) ($context['counter_risk'] ?? 0.0))), 4),
                'basis' => 'heuristic',
                'model_version' => 'raid-behavior-v1',
            ],
            'valuation' => [
                'basis' => 'unavailable',
                'lower_bound' => null,
                'upper_bound' => null,
                'unknown_components' => ['military_or_supported_approach'],
            ],
            'confidence' => 'unknown',
            'approach' => null,
            'approaches' => $approaches,
            'mechanics' => [
                'actions_executed' => 0.0,
                'map_remaining' => round((float) ($context['attacker_map'] ?? self::DEFAULT_STARTING_MAP), 2),
                'controls' => [
                    'air_superiority' => ['attacker' => 0.0, 'defender' => 0.0, 'none' => 1.0],
                    'ground_control' => ['attacker' => 0.0, 'defender' => 0.0, 'none' => 1.0],
                    'blockade' => ['attacker' => 0.0, 'defender' => 0.0, 'none' => 1.0],
                ],
            ],
            'components' => $this->emptyComponents(),
            'loot_resources' => [],
            'cost_resources' => [],
            'assumptions' => array_values(array_unique(array_merge(
                (array) ($context['behavior_assumptions'] ?? []),
                $additionalAssumptions,
                [
                    'No supported approach was feasible for the supplied military state.',
                ],
            ))),
            'scenarios' => collect($scenarios)
                ->map(fn (array $scenario): array => [
                    'key' => $scenario['key'],
                    'label' => $scenario['label'],
                    'weight' => $scenario['weight'],
                ])
                ->all(),
            'military_suitability' => $militarySuitability,
        ];
    }

    /**
     * @return array<string, float|null>
     */
    private function emptyComponents(): array
    {
        return [
            'ground_loot' => 0.0,
            'nation_loot' => 0.0,
            'bank_loot' => 0.0,
            'bounty' => 0.0,
            'consumables' => 0.0,
            'military_losses' => 0.0,
            'infrastructure_losses' => 0.0,
        ];
    }

    private function simulator(): WarSimulationService
    {
        return $this->warSimulationService ??= app(WarSimulationService::class);
    }

    private function costCalculator(): MilitaryCostCalculator
    {
        return $this->militaryCostCalculator ??= app(MilitaryCostCalculator::class);
    }

    private function gamePurchaseCalculator(): GamePurchaseCostCalculator
    {
        return $this->gamePurchaseCostCalculator ??= app(GamePurchaseCostCalculator::class);
    }

    /**
     * @param  array<string, mixed>  $nation
     */
    private function projectEnabled(array $nation, string $project): bool
    {
        $projects = $nation['projects'] ?? [];
        if (! is_array($projects)) {
            return false;
        }

        return (bool) ($projects[$project] ?? in_array($project, $projects, true));
    }

    private function victoryLootFraction(WarSimRequestData $request, array $context): float
    {
        $override = $context['victory_loot_fraction'] ?? null;
        if ($override !== null && is_numeric($override)) {
            return max(0.0, min(1.0, (float) $override));
        }

        return $this->simulator()->victoryLootFraction($request);
    }

    private function bankLootFraction(
        array $context,
        WarSimRng $rng,
        WarSimRequestData $request,
    ): ?float {
        $knownFraction = $context['bank_loot_fraction'] ?? null;
        if ($knownFraction !== null && is_numeric($knownFraction)) {
            return max(0.0, min(RaidLootFormula::BANK_LOOT_CAP, (float) $knownFraction));
        }

        $attackerScore = $this->number($context['attacker_score'] ?? null);
        $allianceScore = $this->number($context['alliance_score'] ?? null);
        if ($attackerScore === null || $allianceScore === null) {
            return null;
        }

        return RaidLootFormula::bankLootFractionForRoll(
            $attackerScore,
            $allianceScore,
            $rng->nextFloat(0.0, 1.0),
            $this->simulator()->bankLootMultiplier($request),
        );
    }

    /**
     * @param  array<string, float>  $map
     * @param  array<string, float>  $other
     * @return array<string, float>
     */
    private function mergeResources(array $map, array $other): array
    {
        foreach ($other as $resource => $amount) {
            $map[$resource] = ($map[$resource] ?? 0.0) + (float) $amount;
        }

        return $map;
    }

    /**
     * @param  array<string, float>  $map
     * @param  array<string, float>  $other
     * @return array<string, float>
     */
    private function mergeWeightedResourceMap(array $map, array $other, float $weight): array
    {
        foreach ($other as $resource => $amount) {
            $map[$resource] = ($map[$resource] ?? 0.0) + ((float) $amount * $weight);
        }

        return $map;
    }

    /**
     * @param  array<string, float>  $resources
     * @param  array<string, float>  $depletion
     * @return array<string, float>
     */
    private function subtractResources(array $resources, array $depletion): array
    {
        foreach ($depletion as $resource => $amount) {
            if (! array_key_exists($resource, $resources)) {
                continue;
            }

            $resources[$resource] = max(0.0, $resources[$resource] - max(0.0, (float) $amount));
        }

        return $resources;
    }

    /**
     * @param  array<string, float>  $resources
     * @return array<string, float>
     */
    private function scaleResources(array $resources, float $factor): array
    {
        return collect($resources)
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => max(0.0, $amount * $factor)])
            ->all();
    }

    private function scaleNullable(?float $value, float $factor): ?float
    {
        return $value === null ? null : max(0.0, $value * $factor);
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return array<string, float>
     */
    private function resourceMap(array $resources): array
    {
        $normalized = [];
        foreach ($resources as $resource => $amount) {
            $numeric = $this->number($amount);
            if ($numeric !== null) {
                $normalized[(string) $resource] = max(0.0, $numeric);
            }
        }

        return $normalized;
    }

    /**
     * Production and consumption vectors are signed. Stockpiles and
     * depletion maps use resourceMap(), which intentionally clamps negative
     * balances; using it for a daily-net vector would silently discard
     * consumption.
     *
     * @param  array<string, mixed>  $resources
     * @return array<string, float>
     */
    private function signedResourceMap(array $resources): array
    {
        $normalized = [];
        foreach ($resources as $resource => $amount) {
            $numeric = $this->number($amount);
            if ($numeric !== null) {
                $normalized[(string) $resource] = $numeric;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return list<string>
     */
    private function unknownResources(array $resources, bool $includeMissing = false): array
    {
        $unknown = [];
        foreach ($resources as $resource => $amount) {
            if ($this->number($amount) === null) {
                $unknown[] = (string) $resource;
            }
        }

        if ($includeMissing) {
            foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                if (! array_key_exists($resource, $resources)) {
                    $unknown[] = $resource;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, float>
     */
    private function roundMap(array $map): array
    {
        return collect($map)
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => round($amount, 4)])
            ->all();
    }

    /**
     * @param  list<array<string, float>>  $maps
     * @return array<string, float>
     */
    private function averageResourceMaps(array $maps): array
    {
        if ($maps === []) {
            return [];
        }

        $totals = [];
        foreach ($maps as $map) {
            foreach ($map as $resource => $amount) {
                $totals[$resource] = ($totals[$resource] ?? 0.0) + (float) $amount;
            }
        }

        return collect($totals)
            ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => $amount / count($maps)])
            ->all();
    }

    /**
     * @param  list<array<string, string>>  $controls
     * @return array<string, array<string, float>>
     */
    private function controlProbabilities(array $controls): array
    {
        $keys = ['air_superiority', 'ground_control', 'blockade'];
        $owners = ['attacker', 'defender', 'none'];
        $counts = [];
        foreach ($keys as $key) {
            $counts[$key] = array_fill_keys($owners, 0.0);
        }

        foreach ($controls as $control) {
            foreach ($keys as $key) {
                $owner = strtolower((string) ($control[$key] ?? 'none'));
                if (! array_key_exists($owner, $counts[$key])) {
                    $owner = 'none';
                }
                $counts[$key][$owner]++;
            }
        }

        $total = count($controls);
        if ($total === 0) {
            return collect($counts)
                ->mapWithKeys(fn (array $owners, string $key): array => [$key => array_fill_keys(array_keys($owners), 0.0)])
                ->all();
        }

        return collect($counts)
            ->mapWithKeys(fn (array $owners, string $key): array => [
                $key => collect($owners)
                    ->mapWithKeys(fn (float $count, string $owner): array => [$owner => $count / $total])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  array<string, array<string, float>>  $left
     * @param  array<string, array<string, float>>  $right
     * @return array<string, array<string, float>>
     */
    private function mergeWeightedControlProbabilities(array $left, array $right, float $weight): array
    {
        foreach ($right as $control => $owners) {
            foreach ($owners as $owner => $probability) {
                $left[$control][$owner] = ($left[$control][$owner] ?? 0.0) + ((float) $probability * $weight);
            }
        }

        return $left;
    }

    /**
     * @param  array<string, array<string, float>>  $probabilities
     * @return array<string, array<string, float>>
     */
    private function roundControlProbabilities(array $probabilities): array
    {
        return collect($probabilities)
            ->mapWithKeys(fn (array $owners, string $control): array => [
                $control => collect($owners)
                    ->mapWithKeys(fn (float $probability, string $owner): array => [$owner => round($probability, 4)])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, float|null>>  $components
     * @return array<string, float|null>
     */
    private function averageComponents(array $components): array
    {
        $averages = $this->emptyComponents();
        if ($components === []) {
            return $averages;
        }

        foreach ($averages as $key => $value) {
            $values = collect($components)
                ->map(fn (array $component): mixed => $component[$key] ?? null)
                ->all();
            if (collect($values)->contains(fn (mixed $amount): bool => $amount === null)) {
                $averages[$key] = null;

                continue;
            }

            $averages[$key] = array_sum(array_map('floatval', $values)) / count($values);
        }

        return $averages;
    }

    /**
     * @param  list<float|null>  $values
     */
    private function averageNullable(array $values): ?float
    {
        $known = collect($values)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->all();

        return $known === [] ? null : array_sum($known) / count($known);
    }

    /**
     * @param  list<array<string, mixed>>  $scenarios
     * @param  array{acquisition: array<string, float>, liquidation: array<string, float>}  $prices
     * @param  array<string, mixed>  $context
     */
    private function confidence(array $scenarios, array $prices, array $context, bool $knownTotals): string
    {
        $score = 0.72;
        $inputConfidence = strtolower((string) ($context['confidence'] ?? ''));
        $score += match ($inputConfidence) {
            'high' => 0.15,
            'medium' => 0.05,
            'low' => -0.2,
            'unknown' => -0.35,
            default => 0.0,
        };

        foreach ($scenarios as $scenario) {
            if (($scenario['resources'] ?? []) === []) {
                $score -= 0.35;
            }

            if (($scenario['bank_resources'] ?? null) === null) {
                $score -= 0.03;
            }
        }

        if (! $knownTotals) {
            $score -= 0.35;
        }

        if (count($prices['liquidation']) < 4 || count($prices['acquisition']) < 4) {
            $score -= 0.25;
        }

        return match (true) {
            $score >= 0.8 => 'high',
            $score >= 0.55 => 'medium',
            $score >= 0.25 => 'low',
            default => 'unknown',
        };
    }

    private function estimateDefenderReturnProbability(?float $activityHours, int $defensiveWars): float
    {
        $probability = match (true) {
            $activityHours === null => 0.20,
            $activityHours <= 6.0 => 0.65,
            $activityHours <= 24.0 => 0.50,
            $activityHours <= 72.0 => 0.35,
            $activityHours <= 168.0 => 0.20,
            default => 0.08,
        };

        return max(0.0, min(0.90, $probability + min(0.20, $defensiveWars * 0.05)));
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     */
    private function estimateCounterRisk(
        array $attacker,
        array $target,
        ?float $activityHours,
        int $defensiveWars,
    ): float {
        $attackerStrength = $this->militaryStrength($attacker);
        $targetStrength = $this->militaryStrength($target);
        $relativeStrength = $targetStrength / max(1.0, $attackerStrength);
        $activityFactor = match (true) {
            $activityHours === null => 0.10,
            $activityHours <= 6.0 => 0.25,
            $activityHours <= 24.0 => 0.18,
            $activityHours <= 72.0 => 0.10,
            default => 0.03,
        };

        return max(0.0, min(0.90, 0.05 + $activityFactor + min(0.45, $relativeStrength * 0.20) + min(0.20, $defensiveWars * 0.04)));
    }

    /**
     * @param  array<string, mixed>  $nation
     */
    private function militaryStrength(array $nation): float
    {
        $military = is_array($nation['military'] ?? null) ? $nation['military'] : [];
        $unit = static fn (string $name): float => max(0.0, (float) ($military[$name] ?? $nation[$name] ?? 0.0));

        return $unit('soldiers')
            + ($unit('tanks') * 40.0)
            + ($unit('aircraft') * 3.0)
            + ($unit('ships') * 4.0);
    }

    private function elapsedHours(mixed $from, mixed $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            $fromDate = new \DateTimeImmutable((string) $from);
            $toDate = new \DateTimeImmutable((string) $to);
        } catch (\Throwable) {
            return null;
        }

        return max(0.0, $toDate->getTimestamp() - $fromDate->getTimestamp()) / 3600.0;
    }

    private function normalizeIterations(mixed $iterations): int
    {
        $value = is_numeric($iterations) ? (int) $iterations : self::DEFAULT_ITERATIONS;

        return max(self::MIN_ITERATIONS, min(self::MAX_ITERATIONS, $value));
    }

    /**
     * @param  list<string>  $bases
     */
    private function valuationBasis(array $bases): string
    {
        if (in_array('unavailable', $bases, true) || $bases === []) {
            return 'unavailable';
        }

        if (in_array('lower_bound', $bases, true)) {
            return 'lower_bound';
        }

        return 'complete';
    }

    private function iterationSeed(?int $seed, int $scenarioIndex, int $iteration): ?int
    {
        if ($seed === null) {
            return null;
        }

        return (int) sprintf('%u', crc32(sprintf('%d:%d:%d', $seed, $scenarioIndex, $iteration)));
    }

    private function fractionOfUnits(int|float $units, float|int $fraction): int
    {
        $fraction = max(0.0, min(1.0, (float) $fraction));
        $availableUnits = max(0, (int) floor($units));

        return max(0, min($availableUnits, (int) ceil($availableUnits * $fraction)));
    }

    private function integer(mixed $value): int
    {
        return max(0, (int) round($this->number($value) ?? 0.0));
    }

    private function number(mixed $value): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        return (float) $value;
    }

    private function roundNullable(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
