<?php

namespace App\Services;

use App\Services\Economy\EconomyRules;
use App\Services\WarSimulator\Support\RaidLootFormula;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Throwable;

/**
 * Reconstruct a target nation's resource stockpile from public observations.
 *
 * The estimator deliberately accepts snapshots instead of loading a Nation's
 * private resources. This keeps the target model usable for public nations and
 * makes every estimate reproducible from the data that was available at the
 * time it was made.
 */
final class RaidStockpileEstimator
{
    public const MODEL_VERSION = 1;

    private const UNKNOWN_MODIFIER_MIN_FRACTION = 0.05;

    private const UNKNOWN_MODIFIER_MAX_FRACTION = 0.20;

    private const PRODUCTION_ESTIMATE_MAX_DAYS = 30;

    private const SECONDS_PER_DAY = 86_400;

    /**
     * @param  array<string, mixed>  $nation
     * @param  list<array<string, mixed>>  $observations
     * @param  list<array<string, mixed>>  $attacks
     * @return array{
     *     resources: array<string, float|null>|null,
     *     scenarios: array{lower: array<string, float|null>, base: array<string, float|null>, upper: array<string, float|null>},
     *     confidence: 'high'|'medium'|'low',
     *     assumptions: list<string>,
     *     observed_at: string|null,
     *     provenance_war_ids: list<int>,
     *     bank_resources: null,
     *     status: 'observed'|'estimated'|'partial'|'unknown',
     *     model_version: int,
     *     calculation: array<string, mixed>
     * }
     */
    public function estimate(
        array $nation,
        array $observations,
        array $attacks,
        CarbonImmutable $asOf,
        ?int $excludedWarId = null,
    ): array {
        $asOf = $asOf->utc();
        $assumptions = [];
        $excludedWarIds = $excludedWarId === null ? [] : [$excludedWarId];

        $normalisedObservations = $this->normaliseObservations(
            $observations,
            $asOf,
            $excludedWarIds,
            $assumptions,
        );
        $baselines = $this->latestBaselines($normalisedObservations, $assumptions);
        $normalisedAttacks = $this->normaliseAttacks(
            $attacks,
            $asOf,
            $excludedWarIds,
            $assumptions,
        );

        $production = $this->productionModel($nation, $assumptions, $excludedWarIds);
        $productionFallbackResources = [];
        $usedProductionFallback = false;
        if ($production['has_vectors']) {
            [$fallbackBaselines, $productionFallbackResources] = $this->productionFallbackBaselines(
                $nation,
                $production,
                $baselines,
                $asOf,
                $assumptions,
            );
            foreach ($fallbackBaselines as $resource => $baseline) {
                if (! array_key_exists($resource, $baselines)) {
                    $baselines[$resource] = $baseline;
                    $usedProductionFallback = true;
                }
            }
        }
        $scenarios = $this->initialScenarioStates($baselines);
        $provenanceWarIds = $this->baselineProvenance($baselines);
        $usedProjection = false;

        if ($baselines !== []) {
            $usedProjection = $this->projectFromBaselines(
                $nation,
                $scenarios,
                $baselines,
                $normalisedAttacks,
                $production,
                $asOf,
                $provenanceWarIds,
                $assumptions,
            );

            if ($usedProductionFallback) {
                $upperScenarios = $this->initialScenarioStates($baselines);
                $upperProvenanceWarIds = $provenanceWarIds;
                $upperAssumptions = [];
                $this->projectFromBaselines(
                    $nation,
                    $upperScenarios,
                    $baselines,
                    $normalisedAttacks,
                    $this->productionWithoutExpenses($production),
                    $asOf,
                    $upperProvenanceWarIds,
                    $upperAssumptions,
                );
                foreach ($productionFallbackResources as $resource) {
                    $scenarios['lower'][$resource] = 0.0;
                    $scenarios['upper'][$resource] = $upperScenarios['base'][$resource];
                }
                foreach ($upperAssumptions as $assumption) {
                    $this->addAssumption($assumptions, $assumption);
                }
                $this->addAssumption($assumptions, 'The lower production scenario assumes no income is retained; the upper scenario retains production before operating expenses.');
            }
        }

        // An all-null scenario is useful to the simulator because it preserves
        // the unknown components, but the public result must still distinguish
        // “no absolute balance can be reconstructed” from a known zero.
        $resources = $baselines === [] ? null : $scenarios['base'];
        $baselineCount = count($baselines);
        $resourceCount = count(EconomyRules::RESOURCE_KEYS);
        $hasUnknownModifier = collect($baselines)
            ->contains(fn (array $baseline): bool => $baseline['modifier_known'] === false);
        $hasUnknownScenario = collect($scenarios['base'])
            ->contains(fn (?float $amount): bool => $amount === null);
        $hasProduction = $production['has_vectors'];

        if ($baselineCount === 0) {
            $status = 'unknown';
            $confidence = 'low';
        } elseif ($baselineCount < $resourceCount || $hasUnknownScenario) {
            $status = 'partial';
            $confidence = $hasUnknownModifier || $hasProduction ? 'low' : 'medium';
        } elseif ($hasUnknownModifier || $usedProjection || $hasProduction || $usedProductionFallback) {
            $status = 'estimated';
            $confidence = $hasUnknownModifier || $usedProductionFallback ? 'low' : 'high';
        } else {
            $status = 'observed';
            $confidence = 'high';
        }

        $observedAt = collect($baselines)
            ->map(fn (array $baseline): CarbonImmutable => $baseline['observed_at'])
            ->sort()
            ->last();

        if ($baselineCount === 0) {
            $missing = implode(', ', EconomyRules::RESOURCE_KEYS);
            $this->addAssumption($assumptions, "No reliable stockpile observation exists for: {$missing}.");
        } elseif ($baselineCount < $resourceCount) {
            $missingResources = collect(EconomyRules::RESOURCE_KEYS)
                ->reject(fn (string $resource): bool => array_key_exists($resource, $baselines))
                ->implode(', ');
            $this->addAssumption($assumptions, "The following resources remain unknown because no usable baseline was observed: {$missingResources}.");
        }

        $this->addAssumption($assumptions, 'Alliance-bank resources are separate from nation resources and are unavailable without a bank observation.');

        $calculation = $this->buildCalculationEvidence(
            $nation,
            $normalisedObservations,
            $baselines,
            $normalisedAttacks,
            $scenarios,
            $assumptions,
            $asOf,
            $status,
        );

        return [
            'resources' => $resources,
            'scenarios' => $scenarios,
            'confidence' => $confidence,
            'assumptions' => array_values($assumptions),
            'observed_at' => $observedAt?->toIso8601String(),
            'provenance_war_ids' => array_values(array_unique(array_map('intval', $provenanceWarIds))),
            'bank_resources' => null,
            'status' => $status,
            'model_version' => self::MODEL_VERSION,
            'calculation' => $calculation,
        ];
    }

    /**
     * Build the small, serialisable evidence record shown with a finder
     * prediction. This intentionally contains the baseline events used by the
     * estimator rather than the complete attack history. The full history can
     * be large, while one row per resource is enough to audit the calculation.
     *
     * @param  array<string, mixed>  $nation
     * @param  list<array<string, mixed>>  $observations
     * @param  array<string, array<string, mixed>>  $baselines
     * @param  list<array<string, mixed>>  $attacks
     * @param  array{lower: array<string, float|null>, base: array<string, float|null>, upper: array<string, float|null>}  $scenarios
     * @param  array<string, string>  $assumptions
     * @return array<string, mixed>
     */
    private function buildCalculationEvidence(
        array $nation,
        array $observations,
        array $baselines,
        array $attacks,
        array $scenarios,
        array $assumptions,
        CarbonImmutable $asOf,
        string $status,
    ): array {
        $observationRows = [];
        $resources = [];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $baseline = $baselines[$resource] ?? null;
            $observation = $baseline === null ? null : collect($observations)
                ->first(fn (array $candidate): bool => $candidate['id'] === $baseline['event_id']);
            $fraction = $baseline['fraction'] ?? null;
            $fractionSource = $baseline['fraction_source'] ?? null;
            $reportedLoot = $baseline['reported_loot'] ?? null;
            $beforeLoot = $baseline['before_loot'] ?? null;
            $postLoot = ($baseline['kind'] ?? null) === 'victory' ? ($baseline['base'] ?? null) : null;
            $baselineBalance = $baseline['baseline_balance'] ?? null;
            $current = $scenarios['base'][$resource] ?? null;
            $lower = $scenarios['lower'][$resource] ?? null;
            $upper = $scenarios['upper'][$resource] ?? null;
            $source = $baseline === null ? null : ($baseline['kind'] ?? null);
            $netChange = is_numeric($current) && is_numeric($baselineBalance)
                ? (float) $current - (float) $baselineBalance
                : null;

            if ($baseline !== null && $observation !== null && ($baseline['kind'] ?? null) === 'victory') {
                $observationRows[] = [
                    'war_id' => $baseline['source_war_id'],
                    'attack_id' => $baseline['source_attack_id'],
                    'observed_at' => $baseline['observed_at']->toIso8601String(),
                    'resource' => $resource,
                    'looted' => $reportedLoot,
                    'fraction' => $fraction,
                    'fraction_lower' => $baseline['fraction_lower'] ?? null,
                    'fraction_upper' => $baseline['fraction_upper'] ?? null,
                    'fraction_source' => $fractionSource,
                    'war_type' => $baseline['war_type'] ?? null,
                    'fraction_inputs' => $baseline['fraction_inputs'] ?? [],
                    'before_loot' => $beforeLoot,
                    'post_loot' => $postLoot,
                ];
            }

            $resources[$resource] = [
                'reported_loot' => $reportedLoot,
                'fraction' => $fraction,
                'fraction_lower' => $baseline['fraction_lower'] ?? null,
                'fraction_upper' => $baseline['fraction_upper'] ?? null,
                'fraction_source' => $fractionSource,
                'war_type' => $baseline['war_type'] ?? null,
                'fraction_inputs' => $baseline['fraction_inputs'] ?? [],
                'baseline_balance' => $baselineBalance,
                'before_loot' => $beforeLoot,
                'post_loot' => $postLoot,
                // Production and depletion are deliberately nullable until a
                // segment trace is available. A net balance difference would
                // incorrectly claim those two effects were observed.
                'production' => null,
                'depletion' => null,
                'net_change' => $netChange,
                'current' => $current,
                'lower' => $lower,
                'upper' => $upper,
                'source' => $source,
                'observed_at' => $baseline !== null && $baseline['observed_at'] instanceof CarbonImmutable
                    ? $baseline['observed_at']->toIso8601String()
                    : null,
                'war_id' => $baseline['source_war_id'] ?? null,
                'attack_id' => $baseline['source_attack_id'] ?? null,
            ];
        }

        $coverageLabel = match ($status) {
            'observed' => 'Observed stockpile evidence',
            'estimated' => 'Estimated from public observations',
            'partial' => 'Partial evidence',
            default => 'Insufficient evidence',
        };

        return [
            'as_of' => $asOf->toIso8601String(),
            'prices_at' => null,
            'model_version' => self::MODEL_VERSION,
            'coverage' => [
                'status' => $status,
                'label' => $coverageLabel,
                'baseline_resources' => count($baselines),
                'resource_count' => count(EconomyRules::RESOURCE_KEYS),
                'history' => [
                    'status' => 'unverified',
                    'label' => 'History completeness is unverified',
                ],
            ],
            'observations' => $observationRows,
            'resources' => $resources,
            'uncertainties' => array_values($assumptions),
            'depletion_events' => $this->depletionEvidence($attacks, $this->integer($nation['id'] ?? $nation['nation_id'] ?? null)),
        ];
    }

    /**
     * Return a compact list of observed target depletion events. These are
     * supporting evidence only; the stockpile projection remains authoritative
     * for the current balances above.
     *
     * @param  list<array<string, mixed>>  $attacks
     * @return list<array<string, mixed>>
     */
    private function depletionEvidence(array $attacks, ?int $targetId): array
    {
        $events = [];
        foreach ($attacks as $attack) {
            if ($attack['resources'] === [] || $attack['bank_resources'] !== [] || ! $this->attackAffectsTarget($attack, $targetId)) {
                continue;
            }

            $events[] = [
                'war_id' => $attack['war_id'],
                'attack_id' => $attack['id'],
                'occurred_at' => $attack['date']->toIso8601String(),
                'resources' => $attack['resources'],
            ];
        }

        return array_slice($events, -24);
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @param  list<int>  $excludedWarIds
     * @param  array<string, string>  $assumptions
     * @return list<array{
     *     id: int,
     *     observed_at: CarbonImmutable,
     *     kind: string,
     *     resources: array<string, float>,
     *     loot_fraction: float|null,
     *     modifier_known: bool,
     *     fraction_source: string,
     *     fraction_inputs: array<string, mixed>,
     *     war_type: string|null,
     *     source_war_id: int|null,
     *     source_attack_id: int|null,
     *     provenance_war_ids: list<int>
     * }>
     */
    private function normaliseObservations(
        array $observations,
        CarbonImmutable $asOf,
        array $excludedWarIds,
        array &$assumptions,
    ): array {
        $normalised = [];

        foreach (array_values($observations) as $index => $observation) {
            $date = $this->parseDate($observation['observed_at'] ?? $observation['date'] ?? null);

            if ($date === null) {
                $this->addAssumption($assumptions, 'An observation without a valid timestamp was ignored.');

                continue;
            }

            if ($date->greaterThan($asOf)) {
                continue;
            }

            $sourceWarId = $this->integer($observation['source_war_id'] ?? $observation['war_id'] ?? null);
            $sourceAttackId = $this->integer($observation['source_attack_id'] ?? $observation['attack_id'] ?? null);
            $provenanceWarIds = $this->normaliseIds($observation['provenance_war_ids'] ?? []);
            if ($sourceWarId !== null) {
                $provenanceWarIds[] = $sourceWarId;
            }
            $provenanceWarIds = array_values(array_unique($provenanceWarIds));

            if ($this->containsExcludedId($sourceWarId, $excludedWarIds)
                || $this->containsExcludedId($provenanceWarIds, $excludedWarIds)) {
                continue;
            }

            $kind = strtolower(trim((string) ($observation['kind'] ?? '')));
            if (! in_array($kind, ['victory', 'stockpile'], true)) {
                $this->addAssumption($assumptions, 'An observation with an unsupported kind was ignored.');

                continue;
            }

            $resources = $this->normaliseResourceMap($observation['resources'] ?? []);
            if ($kind === 'victory') {
                // A zero loot report does not reveal a useful lower bound: it
                // may mean the balance was below the report threshold or that
                // this resource was not eligible for that action.
                $resources = array_filter($resources, static fn (float $amount): bool => $amount > 0);
            }
            if ($resources === []) {
                $this->addAssumption($assumptions, $kind === 'victory'
                    ? 'A victory-loot observation without positive resource quantities was ignored.'
                    : 'An observation without usable resource quantities was ignored.');

                continue;
            }

            $rawLootFraction = $observation['loot_fraction'] ?? null;
            $lootFraction = $this->normaliseLootFraction($rawLootFraction);
            if ($kind === 'victory' && $rawLootFraction !== null && $lootFraction === null) {
                $this->addAssumption($assumptions, 'A victory-loot observation with an invalid historical fraction was ignored.');

                continue;
            }
            $modifierKnown = array_key_exists('modifier_known', $observation)
                ? (bool) $observation['modifier_known']
                : $kind === 'stockpile';
            if ($kind === 'victory' && $lootFraction === null) {
                $modifierKnown = false;
            }
            $fractionSource = trim((string) ($observation['fraction_source'] ?? ''));
            if ($fractionSource === '') {
                $fractionSource = $kind === 'stockpile'
                    ? 'stockpile_observation'
                    : ($lootFraction === null
                        ? 'default_scenario_range'
                        : ($modifierKnown ? 'verified_fraction' : 'calculated_unverified_modifiers'));
            }
            $fractionInputs = is_array($observation['fraction_inputs'] ?? null)
                ? $observation['fraction_inputs']
                : [];
            $warType = trim((string) ($observation['war_type'] ?? '')) ?: null;

            $id = $this->integer($observation['id'] ?? null) ?? $index;

            $normalised[] = [
                'id' => $id,
                'observed_at' => $date,
                'kind' => $kind,
                'resources' => $resources,
                'loot_fraction' => $lootFraction,
                'modifier_known' => $modifierKnown,
                'fraction_source' => $fractionSource,
                'fraction_inputs' => $fractionInputs,
                'war_type' => $warType,
                'source_war_id' => $sourceWarId,
                'source_attack_id' => $sourceAttackId,
                'provenance_war_ids' => $provenanceWarIds,
            ];
        }

        usort($normalised, static function (array $left, array $right): int {
            $dateComparison = $left['observed_at']->getTimestamp() <=> $right['observed_at']->getTimestamp();

            return $dateComparison !== 0 ? $dateComparison : $left['id'] <=> $right['id'];
        });

        return $normalised;
    }

    /**
     * @param  list<array{
     *     id: int,
     *     observed_at: CarbonImmutable,
     *     kind: string,
     *     resources: array<string, float>,
     *     loot_fraction: float|null,
     *     modifier_known: bool,
     *     source_war_id: int|null,
     *     source_attack_id: int|null,
     *     provenance_war_ids: list<int>
     * }>  $observations
     * @param  array<string, string>  $assumptions
     * @return array<string, array{
     *     observed_at: CarbonImmutable,
     *     event_id: int,
     *     kind: string,
     *     source_attack_id: int|null,
     *     source_war_id: int|null,
     *     provenance_war_ids: list<int>,
     *     modifier_known: bool,
     *     reported_loot: float,
     *     fraction: float|null,
     *     fraction_lower: float|null,
     *     fraction_upper: float|null,
     *     fraction_source: string,
     *     baseline_balance: float,
     *     before_loot: float|null,
     *     post_loot: float|null,
     *     lower: float,
     *     base: float,
     *     upper: float
     * }>
     */
    private function latestBaselines(array $observations, array &$assumptions): array
    {
        $baselines = [];

        foreach ($observations as $observation) {
            foreach ($observation['resources'] as $resource => $reportedAmount) {
                $baseline = $this->reconstructBaseline($observation, $reportedAmount, $resource, $assumptions);

                if ($baseline === null) {
                    continue;
                }

                // Input is sorted ascending, so the latest usable event wins for
                // each resource independently. This also handles partial reports.
                $baselines[$resource] = $baseline;
            }
        }

        return $baselines;
    }

    /**
     * @param  array<string, mixed>  $nation
     * @param  array{
     *     id: int,
     *     observed_at: CarbonImmutable,
     *     kind: string,
     *     resources: array<string, float>,
     *     loot_fraction: float|null,
     *     modifier_known: bool,
     *     source_war_id: int|null,
     *     source_attack_id: int|null,
     *     provenance_war_ids: list<int>
     * }  $observation
     * @param  array<string, string>  $assumptions
     * @return array{
     *     observed_at: CarbonImmutable,
     *     event_id: int,
     *     kind: string,
     *     source_attack_id: int|null,
     *     source_war_id: int|null,
     *     provenance_war_ids: list<int>,
     *     modifier_known: bool,
     *     reported_loot: float,
     *     fraction: float|null,
     *     fraction_lower: float|null,
     *     fraction_upper: float|null,
     *     fraction_source: string,
     *     baseline_balance: float,
     *     before_loot: float|null,
     *     post_loot: float|null,
     *     lower: float,
     *     base: float,
     *     upper: float
     * }|null
     */
    private function reconstructBaseline(array $observation, float $reportedAmount, string $resource, array &$assumptions): ?array
    {
        if ($reportedAmount < 0 || ! is_finite($reportedAmount)) {
            $this->addAssumption($assumptions, "A negative or non-finite {$resource} observation was ignored.");

            return null;
        }

        if ($observation['kind'] === 'stockpile') {
            $amount = $reportedAmount;

            return [
                'observed_at' => $observation['observed_at'],
                'event_id' => $observation['id'],
                'kind' => 'stockpile',
                'source_attack_id' => $observation['source_attack_id'],
                'source_war_id' => $observation['source_war_id'],
                'provenance_war_ids' => $observation['provenance_war_ids'],
                'modifier_known' => true,
                'reported_loot' => null,
                'fraction' => null,
                'fraction_lower' => null,
                'fraction_upper' => null,
                'fraction_source' => $observation['fraction_source'],
                'war_type' => $observation['war_type'],
                'fraction_inputs' => $observation['fraction_inputs'],
                'baseline_balance' => $amount,
                'before_loot' => null,
                'post_loot' => null,
                'lower' => $amount,
                'base' => $amount,
                'upper' => $amount,
            ];
        }

        $fraction = $observation['loot_fraction'];
        $fractionSource = $observation['fraction_source'];
        if ($fraction === null) {
            $fraction = RaidLootFormula::victoryLootFraction();
            $fractionSource = 'default_scenario_range';
            $this->addAssumption(
                $assumptions,
                'Victory-loot observations without a historical fraction use a 10% base case and an explicit 5%-20% scenario range.',
            );
        }

        if ($fraction <= 0 || $fraction >= 1) {
            $this->addAssumption($assumptions, "The {$resource} victory-loot observation had no usable loot fraction and was ignored.");

            return null;
        }

        $base = $this->stockpileBeforeLoot($reportedAmount, $fraction);
        $baseAfterLoot = max(0.0, $base - $reportedAmount);

        if ($observation['modifier_known']) {
            return [
                'observed_at' => $observation['observed_at'],
                'event_id' => $observation['id'],
                'kind' => 'victory',
                'source_attack_id' => $observation['source_attack_id'],
                'source_war_id' => $observation['source_war_id'],
                'provenance_war_ids' => $observation['provenance_war_ids'],
                'modifier_known' => true,
                'reported_loot' => $reportedAmount,
                'fraction' => $fraction,
                'fraction_lower' => $fraction,
                'fraction_upper' => $fraction,
                'fraction_source' => $fractionSource,
                'war_type' => $observation['war_type'],
                'fraction_inputs' => $observation['fraction_inputs'],
                'before_loot' => $base,
                'baseline_balance' => $baseAfterLoot,
                'lower' => $baseAfterLoot,
                'base' => $baseAfterLoot,
                'upper' => $baseAfterLoot,
            ];
        }

        $minimumFraction = $observation['loot_fraction'] === null
            ? self::UNKNOWN_MODIFIER_MIN_FRACTION
            : max(0.01, min(self::UNKNOWN_MODIFIER_MIN_FRACTION, $fraction * 0.5));
        $maximumFraction = $observation['loot_fraction'] === null
            ? self::UNKNOWN_MODIFIER_MAX_FRACTION
            : min(0.95, max(self::UNKNOWN_MODIFIER_MAX_FRACTION, $fraction * 2));
        $lower = max(0.0, $this->stockpileBeforeLoot($reportedAmount, $maximumFraction) - $reportedAmount);
        $upper = max(0.0, $this->stockpileBeforeLoot($reportedAmount, $minimumFraction) - $reportedAmount);

        $this->addAssumption(
            $assumptions,
            "Victory-loot observations have unknown historical modifiers; affected resources use a {$this->formatNumber($minimumFraction * 100)}%-{$this->formatNumber($maximumFraction * 100)}% loot-fraction range.",
        );

        return [
            'observed_at' => $observation['observed_at'],
            'event_id' => $observation['id'],
            'kind' => 'victory',
            'source_attack_id' => $observation['source_attack_id'],
            'source_war_id' => $observation['source_war_id'],
            'provenance_war_ids' => $observation['provenance_war_ids'],
            'modifier_known' => false,
            'reported_loot' => $reportedAmount,
            'fraction' => $fraction,
            'fraction_lower' => $minimumFraction,
            'fraction_upper' => $maximumFraction,
            'fraction_source' => $fractionSource,
            'war_type' => $observation['war_type'],
            'fraction_inputs' => $observation['fraction_inputs'],
            'baseline_balance' => $baseAfterLoot,
            'before_loot' => $base,
            'lower' => $lower,
            'base' => $baseAfterLoot,
            'upper' => $upper,
        ];
    }

    private function stockpileBeforeLoot(float $loot, float $fraction): float
    {
        if ($loot <= 0) {
            return 0.0;
        }

        return $loot / $fraction;
    }

    /**
     * @param  list<array<string, mixed>>  $attacks
     * @param  list<int>  $excludedWarIds
     * @param  array<string, string>  $assumptions
     * @return list<array{
     *     id: int,
     *     war_id: int|null,
     *     date: CarbonImmutable,
     *     att_id: int|null,
     *     def_id: int|null,
     *     victor: int|null,
     *     type: string,
     *     resources: array<string, float>,
     *     bank_resources: array<string, float>,
     *     provenance_war_ids: list<int>
     * }>
     */
    private function normaliseAttacks(
        array $attacks,
        CarbonImmutable $asOf,
        array $excludedWarIds,
        array &$assumptions,
    ): array {
        $normalised = [];

        foreach (array_values($attacks) as $index => $attack) {
            $date = $this->parseDate($attack['date'] ?? $attack['occurred_at'] ?? null);
            if ($date === null) {
                $this->addAssumption($assumptions, 'An attack without a valid timestamp was ignored for depletion projection.');

                continue;
            }

            if ($date->greaterThan($asOf)) {
                continue;
            }

            $warId = $this->integer($attack['war_id'] ?? null);
            $provenanceWarIds = $this->normaliseIds($attack['provenance_war_ids'] ?? []);
            if ($warId !== null) {
                $provenanceWarIds[] = $warId;
            }
            $provenanceWarIds = array_values(array_unique($provenanceWarIds));

            if ($this->containsExcludedId($warId, $excludedWarIds)
                || $this->containsExcludedId($provenanceWarIds, $excludedWarIds)) {
                continue;
            }

            $resources = $this->attackResources($attack);
            $isBankLoot = $this->isBankLootAttack($attack);
            $bankResources = $isBankLoot ? $resources : [];

            if ($isBankLoot) {
                $resources = [];
            }

            $normalised[] = [
                'id' => $this->integer($attack['id'] ?? null) ?? $index,
                'war_id' => $warId,
                'date' => $date,
                'att_id' => $this->integer($attack['att_id'] ?? null),
                'def_id' => $this->integer($attack['def_id'] ?? null),
                'victor' => $this->integer($attack['victor'] ?? null),
                'type' => strtoupper((string) ($attack['type'] ?? '')),
                'resources' => $resources,
                'bank_resources' => $bankResources,
                'provenance_war_ids' => $provenanceWarIds,
            ];
        }

        usort($normalised, static function (array $left, array $right): int {
            $dateComparison = $left['date']->getTimestamp() <=> $right['date']->getTimestamp();

            return $dateComparison !== 0 ? $dateComparison : $left['id'] <=> $right['id'];
        });

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $attack
     * @return array<string, float>
     */
    private function attackResources(array $attack): array
    {
        $resources = [];
        $resourceFieldsPresent = [];
        $moneyFieldsPresent = false;

        foreach (['money_stolen', 'money_looted'] as $moneyKey) {
            if (! array_key_exists($moneyKey, $attack)) {
                continue;
            }

            $amount = $this->number($attack[$moneyKey]);
            if ($amount === null) {
                continue;
            }

            $moneyFieldsPresent = true;
            if ($amount > 0) {
                $resources['money'] = ($resources['money'] ?? 0.0) + $amount;
            }
        }

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $field = $resource.'_looted';
            if (! array_key_exists($field, $attack)) {
                continue;
            }

            $amount = $this->number($attack[$field]);
            if ($amount === null) {
                continue;
            }

            $resourceFieldsPresent[$resource] = true;
            if ($amount > 0) {
                $resources[$resource] = $amount;
            }
        }

        // Some persisted payloads expose both the flattened fields and the
        // nested map. Flattened fields are authoritative; nested values fill
        // gaps only. This avoids counting one API payload twice.
        foreach ($this->normaliseResourceMap($attack['resource_looted'] ?? []) as $resource => $amount) {
            $wasPresent = $resource === 'money'
                ? $moneyFieldsPresent
                : ($resourceFieldsPresent[$resource] ?? false);

            if ($resource === 'money') {
                $moneyFieldsPresent = true;
            } else {
                $resourceFieldsPresent[$resource] = true;
            }

            if ($amount > 0 && $resource !== 'money' && ! $wasPresent) {
                $resources[$resource] = $amount;
            }
            if ($amount > 0 && $resource === 'money' && ! $wasPresent && ! array_key_exists('money', $resources)) {
                $resources['money'] = $amount;
            }
        }

        $lootInfo = $attack['loot_info'] ?? null;
        if (is_string($lootInfo) && trim($lootInfo) !== '') {
            try {
                $decoded = json_decode($lootInfo, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                foreach ($this->normaliseResourceMap(
                    $decoded['resource_looted'] ?? $decoded['resources'] ?? $decoded['loot'] ?? $decoded,
                ) as $resource => $amount) {
                    if ($amount > 0 && ! ($resourceFieldsPresent[$resource] ?? false) && ! array_key_exists($resource, $resources)) {
                        $resources[$resource] = $amount;
                    }
                }

                if (! $moneyFieldsPresent) {
                    $decodedMoney = 0.0;
                    $hasDecodedMoney = false;
                    foreach (['money_stolen', 'money_looted'] as $moneyKey) {
                        if (! array_key_exists($moneyKey, $decoded)) {
                            continue;
                        }

                        $amount = $this->number($decoded[$moneyKey]);
                        if ($amount === null) {
                            continue;
                        }

                        $hasDecodedMoney = true;
                        $decodedMoney += max(0.0, $amount);
                    }

                    if (! $hasDecodedMoney && array_key_exists('money', $decoded)) {
                        $decodedMoney = max(0.0, $this->number($decoded['money']) ?? 0.0);
                        $hasDecodedMoney = $decodedMoney > 0;
                    }

                    if ($hasDecodedMoney && $decodedMoney > 0) {
                        $resources['money'] = $decodedMoney;
                    }
                }
            }
        }

        return $resources;
    }

    /**
     * @param  array<string, mixed>  $attack
     */
    private function isBankLootAttack(array $attack): bool
    {
        $type = $attack['type'] ?? '';
        if (is_object($type) && property_exists($type, 'value')) {
            $type = $type->value;
        }
        $type = strtoupper((string) $type);

        return str_contains($type, 'ALLIANCE') || str_contains(strtoupper((string) ($attack['loot_info'] ?? '')), 'ALLIANCE BANK');
    }

    /**
     * Apply a depletion only when the target was the losing side. Victory
     * loot on a target's successful attack belongs to the opposing nation and
     * must not be subtracted from the target's own reconstructed balance.
     * Ground theft without a victor is still attributed to a target defender;
     * an attacker with no victor is left unclassified rather than guessed.
     *
     * @param  array{att_id: int|null, def_id: int|null, victor: int|null}  $attack
     */
    private function attackAffectsTarget(array $attack, ?int $nationId): bool
    {
        if ($nationId === null) {
            return true;
        }

        $isAttacker = $attack['att_id'] === $nationId;
        $isDefender = $attack['def_id'] === $nationId;
        if (! $isAttacker && ! $isDefender) {
            return false;
        }

        if ($attack['victor'] !== null) {
            return $attack['victor'] !== $nationId;
        }

        return $isDefender;
    }

    /**
     * @param  array<string, mixed>  $nation
     * @param  array<string, string>  $assumptions
     * @return array{
     *     output: array<string, float>,
     *     expense: array<string, float>,
     *     net: array<string, float>,
     *     processes: list<array{output: array<string, float>, inputs: array<string, float>}>,
     *     contexts: list<array<string, mixed>>,
     *     default_vacation_mode: bool,
     *     default_vacation_seconds: float|null,
     *     has_vectors: bool
     * }
     */
    private function productionModel(array $nation, array &$assumptions, array $excludedWarIds = []): array
    {
        $output = $this->firstResourceMap($nation, [
            'daily_output',
            'daily_outputs',
            'resource_output_per_day',
            'output_per_day',
        ]);
        $expense = $this->firstResourceMap($nation, [
            'daily_expense',
            'daily_expenses',
            'resource_expense_per_day',
            'expense_per_day',
        ], absolute: true);
        $net = $this->firstResourceMap($nation, [
            'daily_net',
            'daily_profit',
            'resource_profit_per_day',
            'net_per_day',
        ]);

        // Fill only missing resources from net. When raw output and expense
        // vectors are present, they remain authoritative because they carry
        // the input constraints needed by manufacturing buildings.
        foreach ($net as $resource => $amount) {
            if (($output[$resource] ?? 0.0) != 0.0 || ($expense[$resource] ?? 0.0) != 0.0) {
                continue;
            }

            if ($amount >= 0) {
                $output[$resource] = $amount;
            } else {
                $expense[$resource] = abs($amount);
            }
        }

        $contexts = $this->normaliseProductionContexts(
            $nation['production_context']
                ?? $nation['production_contexts']
                ?? $nation['production_context_snapshots']
                ?? [],
            $assumptions,
            $excludedWarIds,
        );
        $processes = $this->normaliseProcesses($nation['production_processes'] ?? [], $assumptions);
        $defaultVacationMode = $this->vacationMode($nation);
        $defaultVacationSeconds = $this->vacationModeSeconds($nation);

        if ($defaultVacationMode && $contexts === []) {
            $this->addAssumption($assumptions, 'Vacation mode is active in the supplied nation snapshot; accrual is suppressed for its remaining turns unless a later context changes it.');
        }

        return [
            'output' => $output,
            'expense' => $expense,
            'net' => $net,
            'processes' => $processes,
            'contexts' => $contexts,
            'default_vacation_mode' => $defaultVacationMode,
            'default_vacation_seconds' => $defaultVacationSeconds,
            'has_vectors' => $output !== [] || $expense !== [] || $net !== [] || $processes !== [] || $contexts !== [],
        ];
    }

    /**
     * Build synthetic zero-balance baselines for resources that have usable
     * production data but no public loot or stockpile observation. These are
     * deliberately marked through the caller's fallback flag rather than
     * being presented as measured balances.
     *
     * @param  array<string, mixed>  $nation
     * @param  array{
     *     output: array<string, float>,
     *     expense: array<string, float>,
     *     net: array<string, float>,
     *     processes: list<array{output: array<string, float>, inputs: array<string, float>}>,
     *     contexts: list<array<string, mixed>>,
     *     default_vacation_mode: bool,
     *     default_vacation_seconds: float|null,
     *     has_vectors: bool
     * }  $production
     * @param  array<string, array<string, mixed>>  $baselines
     * @param  array<string, string>  $assumptions
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private function productionFallbackBaselines(
        array $nation,
        array $production,
        array $baselines,
        CarbonImmutable $asOf,
        array &$assumptions,
    ): array {
        $anchor = $this->productionEstimateAnchor($nation, $production, $asOf);
        if ($anchor === null) {
            $this->addAssumption($assumptions, 'Production vectors were available, but no dated activity or observation anchor could be established for a production estimate.');

            return [[], []];
        }

        $resourceCandidates = $this->productionEstimateResources($production);
        $resourceCandidates = array_values(array_filter(
            $resourceCandidates,
            static fn (string $resource): bool => ! array_key_exists($resource, $baselines),
        ));
        if ($resourceCandidates === []) {
            return [[], []];
        }

        $start = $anchor['date'];
        $elapsedSeconds = max(0, $start->diffInSeconds($asOf));
        $maximumSeconds = self::PRODUCTION_ESTIMATE_MAX_DAYS * self::SECONDS_PER_DAY;
        if ($elapsedSeconds > $maximumSeconds) {
            $start = $asOf->subDays(self::PRODUCTION_ESTIMATE_MAX_DAYS);
            $this->addAssumption(
                $assumptions,
                'The production-only estimate is capped at 30 days before the capture time because the starting stockpile is unobserved.',
            );
        }

        $this->addAssumption(
            $assumptions,
            sprintf(
                'No reliable loot baseline exists for %s; the production-only estimate assumes a zero starting balance at the %s anchor (%s) and retains production through %s.',
                implode(', ', $resourceCandidates),
                $anchor['source'],
                $start->toIso8601String(),
                $asOf->toIso8601String(),
            ),
        );
        $this->addAssumption(
            $assumptions,
            'Production-only balances are low-confidence scenarios, not measured stockpile observations; spending, transfers, and taxes before the anchor remain unknown.',
        );

        $fallbackBaselines = [];
        foreach ($resourceCandidates as $index => $resource) {
            $fallbackBaselines[$resource] = [
                'observed_at' => $start,
                'event_id' => -1 - $index,
                'kind' => 'production',
                'source_attack_id' => null,
                'source_war_id' => null,
                'provenance_war_ids' => [],
                'modifier_known' => true,
                'reported_loot' => null,
                'fraction' => null,
                'fraction_lower' => null,
                'fraction_upper' => null,
                'fraction_source' => 'production_only_estimate',
                'war_type' => null,
                'fraction_inputs' => [],
                'baseline_balance' => 0.0,
                'before_loot' => null,
                'post_loot' => null,
                'lower' => 0.0,
                'base' => 0.0,
                'upper' => 0.0,
            ];
        }

        return [$fallbackBaselines, $resourceCandidates];
    }

    /**
     * Pick the best public date from which a production-only estimate can
     * start. Activity is preferred because the fallback models retained
     * income after the nation's last known activity; a snapshot date is the
     * next-best reproducible anchor.
     *
     * @param  array<string, mixed>  $nation
     * @param  array{
     *     contexts: list<array<string, mixed>>
     * }  $production
     * @return array{date: CarbonImmutable, source: string}|null
     */
    private function productionEstimateAnchor(array $nation, array $production, CarbonImmutable $asOf): ?array
    {
        foreach ([
            'last_active' => 'last activity',
            'last_active_at' => 'last activity',
            'activity_at' => 'last activity',
            'observed_at' => 'nation observation',
            'snapshot_at' => 'nation observation',
            'captured_at' => 'capture',
        ] as $key => $source) {
            $date = $this->parseDate($nation[$key] ?? null);
            if ($date !== null && $date->lessThanOrEqualTo($asOf)) {
                return ['date' => $date, 'source' => $source];
            }
        }

        $firstContext = $production['contexts'][0] ?? null;
        if (is_array($firstContext) && ($firstContext['_effective_at'] ?? null) instanceof CarbonImmutable) {
            $date = $firstContext['_effective_at'];
            if ($date->lessThanOrEqualTo($asOf)) {
                return ['date' => $date, 'source' => 'production context'];
            }
        }

        return null;
    }

    /**
     * Return resources for which production can provide a bounded estimate
     * from a zero starting balance. Expense-only resources remain unknown,
     * and manufacturing output is included only when its non-money inputs
     * can themselves be produced or are not required.
     *
     * @param  array{
     *     output: array<string, float>,
     *     expense: array<string, float>,
     *     net: array<string, float>,
     *     processes: list<array{output: array<string, float>, inputs: array<string, float>}>,
     *     contexts: list<array<string, mixed>>
     * }  $production
     * @return list<string>
     */
    private function productionEstimateResources(array $production): array
    {
        $candidates = [];
        $allProcesses = $production['processes'];
        $processOutputs = [];
        foreach ($production['processes'] as $process) {
            foreach ($process['output'] as $resource => $amount) {
                $processOutputs[$resource] = ($processOutputs[$resource] ?? 0.0) + max(0.0, $amount);
            }
        }

        $markRawVectors = function (array $output, array $expense, array $net, array $processOutputMap) use (&$candidates): void {
            foreach ($output as $resource => $amount) {
                $rawAmount = $amount - ($processOutputMap[$resource] ?? 0.0);
                if ($rawAmount > 0.0000001) {
                    $candidates[$resource] = true;
                }
            }

            foreach ($net as $resource => $amount) {
                $outputAmount = (float) ($output[$resource] ?? 0.0);
                $expenseAmount = (float) ($expense[$resource] ?? 0.0);
                if ($amount > 0.0000001
                    && $outputAmount == 0.0
                    && $expenseAmount == 0.0) {
                    $candidates[$resource] = true;
                }
            }
        };

        $markRawVectors($production['output'], $production['expense'], $production['net'], $processOutputs);
        foreach ($production['contexts'] as $context) {
            $output = $this->contextVector($context, ['daily_output', 'daily_outputs', 'resource_output_per_day', 'output_per_day']);
            $expense = $this->contextVector($context, ['daily_expense', 'daily_expenses', 'resource_expense_per_day', 'expense_per_day'], absolute: true);
            $net = $this->contextVector($context, ['daily_net', 'daily_profit', 'resource_profit_per_day', 'net_per_day']);
            $contextProcesses = $production['processes'];
            if (array_key_exists('production_processes', $context)) {
                $ignoredAssumptions = [];
                $contextProcesses = $this->normaliseProcesses($context['production_processes'], $ignoredAssumptions);
                $allProcesses = array_merge($allProcesses, $contextProcesses);
            }
            $contextProcessOutputs = [];
            foreach ($contextProcesses as $process) {
                foreach ($process['output'] as $resource => $amount) {
                    $contextProcessOutputs[$resource] = ($contextProcessOutputs[$resource] ?? 0.0) + max(0.0, $amount);
                }
            }
            $markRawVectors($output, $expense, $net, $contextProcessOutputs);
        }

        // A process output is estimable when every non-money input has a
        // production source. This also supports a raw resource feeding a
        // manufacturing building in the same chronological projection.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($allProcesses as $process) {
                $inputsAvailable = true;
                foreach ($process['inputs'] as $resource => $amount) {
                    if ($resource !== 'money' && ! isset($candidates[$resource])) {
                        $inputsAvailable = false;
                        break;
                    }
                }
                if (! $inputsAvailable) {
                    continue;
                }
                foreach ($process['output'] as $resource => $amount) {
                    if ($amount > 0 && ! isset($candidates[$resource])) {
                        $candidates[$resource] = true;
                        $changed = true;
                    }
                }
            }
        }

        return array_values(array_filter(
            EconomyRules::RESOURCE_KEYS,
            static fn (string $resource): bool => isset($candidates[$resource]),
        ));
    }

    /**
     * Create the upper production scenario by removing generic operating
     * expenses while keeping manufacturing input constraints. Unknown inputs
     * therefore remain unavailable instead of silently becoming free output.
     *
     * @param  array<string, mixed>  $production
     * @return array<string, mixed>
     */
    private function productionWithoutExpenses(array $production): array
    {
        $upper = $production;
        $upper['expense'] = [];
        $upper['net'] = $production['output'];
        foreach ($production['net'] as $resource => $amount) {
            if (! array_key_exists($resource, $upper['output']) && $amount > 0) {
                $upper['output'][$resource] = $amount;
                $upper['net'][$resource] = $amount;
            }
        }

        $upper['contexts'] = [];
        foreach ($production['contexts'] as $context) {
            $context['daily_output'] = $this->contextVector($context, ['daily_output', 'daily_outputs', 'resource_output_per_day', 'output_per_day']);
            $net = $this->contextVector($context, ['daily_net', 'daily_profit', 'resource_profit_per_day', 'net_per_day']);
            foreach ($net as $resource => $amount) {
                if (($context['daily_output'][$resource] ?? 0.0) == 0.0 && $amount > 0) {
                    $context['daily_output'][$resource] = $amount;
                }
            }
            $context['daily_expenses'] = [];
            $context['daily_net'] = $context['daily_output'];
            $upper['contexts'][] = $context;
        }

        return $upper;
    }

    /**
     * @param  array<string, string>  $assumptions
     * @return list<array<string, mixed>>
     */
    private function normaliseProductionContexts(mixed $contexts, array &$assumptions, array $excludedWarIds = []): array
    {
        if (! is_array($contexts)) {
            return [];
        }

        $normalised = [];
        foreach (array_values($contexts) as $context) {
            if (! is_array($context)) {
                continue;
            }
            $date = $this->parseDate(
                $context['effective_at']
                    ?? $context['observed_at']
                    ?? $context['date']
                    ?? $context['from']
                    ?? null,
            );
            if ($date === null) {
                $this->addAssumption($assumptions, 'A production context without a valid effective timestamp was ignored.');

                continue;
            }

            $contextWarId = $this->integer($context['source_war_id'] ?? $context['war_id'] ?? null);
            $contextProvenance = $this->normaliseIds($context['provenance_war_ids'] ?? []);
            if ($contextWarId !== null) {
                $contextProvenance[] = $contextWarId;
            }
            if ($this->containsExcludedId($contextWarId, $excludedWarIds)
                || $this->containsExcludedId($contextProvenance, $excludedWarIds)) {
                continue;
            }

            $context['_effective_at'] = $date;
            $normalised[] = $context;
        }

        usort($normalised, static function (array $left, array $right): int {
            return $left['_effective_at']->getTimestamp() <=> $right['_effective_at']->getTimestamp();
        });

        return $normalised;
    }

    /**
     * Normalize the process vectors emitted by the economy snapshot service.
     * A process contains only its own positive output and required inputs, so
     * it can be scaled independently when one manufacturing input is empty.
     *
     * @param  array<string, string>  $assumptions
     * @return list<array{output: array<string, float>, inputs: array<string, float>}>
     */
    private function normaliseProcesses(mixed $processes, array &$assumptions): array
    {
        if (! is_array($processes)) {
            return [];
        }

        $normalised = [];
        foreach (array_values($processes) as $process) {
            if (! is_array($process)) {
                continue;
            }

            $output = array_filter(
                $this->normaliseResourceMap($process['output'] ?? []),
                static fn (float $amount): bool => $amount > 0,
            );
            $inputs = array_filter(
                $this->normaliseResourceMap($process['inputs'] ?? [], absolute: true),
                static fn (float $amount): bool => $amount > 0,
            );
            if ($output === [] && $inputs === []) {
                $this->addAssumption($assumptions, 'An empty manufacturing process was ignored.');

                continue;
            }

            $normalised[] = ['output' => $output, 'inputs' => $inputs];
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $nation
     */
    private function vacationMode(array $nation): bool
    {
        $seconds = $this->vacationModeSeconds($nation);

        return $seconds === null || $seconds > 0;
    }

    /**
     * Return the remaining vacation duration represented by a snapshot. A
     * null duration means the snapshot explicitly says vacation mode is
     * active but provides no turn count, so production stays paused for the
     * interval rather than inventing an expiry.
     */
    private function vacationModeSeconds(array $nation): ?float
    {
        foreach (['vacation_mode_turns', 'vmode_turns'] as $key) {
            if (! array_key_exists($key, $nation)) {
                continue;
            }

            $turns = $this->number($nation[$key]);
            if ($turns === null) {
                return null;
            }

            return max(0.0, $turns) * (self::SECONDS_PER_DAY / EconomyRules::TURNS_PER_DAY);
        }

        foreach (['vacation_mode', 'vmode', 'is_vacation_mode'] as $key) {
            if (array_key_exists($key, $nation)) {
                return (bool) $nation[$key] ? null : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Resolve one absolute vacation window for the projection. Context
     * snapshots are anchored at their own effective timestamp; the default
     * nation snapshot is anchored once at the projection start.
     *
     * @param  array<string, mixed>|null  $context
     * @param  array{
     *     default_vacation_mode: bool,
     *     default_vacation_seconds: float|null
     * }  $production
     * @return array{active: bool, ends_at: CarbonImmutable|null}
     */
    private function vacationWindow(?array $context, CarbonImmutable $defaultAnchor, array $production): array
    {
        if ($context === null) {
            if (! $production['default_vacation_mode']) {
                return ['active' => false, 'ends_at' => $defaultAnchor];
            }

            $seconds = $production['default_vacation_seconds'];

            return [
                'active' => true,
                'ends_at' => $seconds === null
                    ? null
                    : $defaultAnchor->addSeconds((int) max(0.0, round($seconds))),
            ];
        }

        $effectiveAt = $context['_effective_at'] ?? null;
        if (! $effectiveAt instanceof CarbonImmutable) {
            return ['active' => true, 'ends_at' => null];
        }

        foreach (['vacation_mode_turns', 'vmode_turns'] as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $turns = $this->number($context[$key]);
            if ($turns === null) {
                return ['active' => true, 'ends_at' => null];
            }

            $seconds = max(0.0, $turns) * (self::SECONDS_PER_DAY / EconomyRules::TURNS_PER_DAY);

            return [
                'active' => $seconds > 0,
                'ends_at' => $effectiveAt->addSeconds((int) max(0.0, round($seconds))),
            ];
        }

        foreach (['vacation_mode', 'vmode', 'is_vacation_mode'] as $key) {
            if (array_key_exists($key, $context)) {
                return (bool) $context[$key]
                    ? ['active' => true, 'ends_at' => null]
                    : ['active' => false, 'ends_at' => $effectiveAt];
            }
        }

        return ['active' => false, 'ends_at' => $effectiveAt];
    }

    /**
     * @param  array<string, array{
     *     observed_at: CarbonImmutable,
     *     event_id: int,
     *     kind: string,
     *     source_attack_id: int|null,
     *     source_war_id: int|null,
     *     provenance_war_ids: list<int>,
     *     modifier_known: bool,
     *     reported_loot: float,
     *     fraction: float|null,
     *     fraction_lower: float|null,
     *     fraction_upper: float|null,
     *     fraction_source: string,
     *     before_loot: float|null,
     *     lower: float,
     *     base: float,
     *     upper: float
     * }>  $baselines
     * @return array{lower: array<string, float|null>, base: array<string, float|null>, upper: array<string, float|null>}
     */
    private function initialScenarioStates(array $baselines): array
    {
        $scenarios = [
            'lower' => array_fill_keys(EconomyRules::RESOURCE_KEYS, null),
            'base' => array_fill_keys(EconomyRules::RESOURCE_KEYS, null),
            'upper' => array_fill_keys(EconomyRules::RESOURCE_KEYS, null),
        ];

        foreach ($baselines as $resource => $baseline) {
            $scenarios['lower'][$resource] = $baseline['lower'];
            $scenarios['base'][$resource] = $baseline['base'];
            $scenarios['upper'][$resource] = $baseline['upper'];
        }

        return $scenarios;
    }

    /**
     * @param  array{lower: array<string, float|null>, base: array<string, float|null>, upper: array<string, float|null>}  $scenarios
     * @param  array<string, array{
     *     observed_at: CarbonImmutable,
     *     event_id: int,
     *     source_attack_id: int|null,
     *     source_war_id: int|null,
     *     provenance_war_ids: list<int>,
     *     modifier_known: bool,
     *     lower: float,
     *     base: float,
     *     upper: float
     * }>  $baselines
     * @param  list<array{
     *     id: int,
     *     war_id: int|null,
     *     date: CarbonImmutable,
     *     att_id: int|null,
     *     def_id: int|null,
     *     victor: int|null,
     *     type: string,
     *     resources: array<string, float>,
     *     bank_resources: array<string, float>,
     *     provenance_war_ids: list<int>
     * }>  $attacks
     * @param  array{
     *     output: array<string, float>,
     *     expense: array<string, float>,
     *     net: array<string, float>,
     *     processes: list<array{output: array<string, float>, inputs: array<string, float>}>,
     *     contexts: list<array<string, mixed>>,
     *     default_vacation_mode: bool,
     *     default_vacation_seconds: float|null,
     *     has_vectors: bool
     * }  $production
     * @param  list<int>  $provenanceWarIds
     * @param  array<string, string>  $assumptions
     */
    private function projectFromBaselines(
        array $nation,
        array &$scenarios,
        array $baselines,
        array $attacks,
        array $production,
        CarbonImmutable $asOf,
        array &$provenanceWarIds,
        array &$assumptions,
    ): bool {
        $usedProjection = false;

        $nationId = $this->integer($nation['id'] ?? $nation['nation_id'] ?? null);
        $projectionStart = collect($baselines)
            ->map(fn (array $baseline): CarbonImmutable => $baseline['observed_at'])
            ->sort()
            ->first();

        if (! $projectionStart instanceof CarbonImmutable) {
            return false;
        }

        // The nation-level vacation fields describe one snapshot. Anchor that
        // window once for this whole projection so attack and observation
        // boundaries cannot restart the same pause.
        $defaultVacationWindow = $this->vacationWindow(
            null,
            $projectionStart,
            $production,
        );

        // A victory observation already uses the loot from its source attack
        // to reconstruct the post-event balance. Keep the exclusion per
        // resource so a partial observation does not hide unrelated resource
        // depletion reported by the same attack.
        $sourceAttackIdsByResource = [];
        foreach ($baselines as $resource => $baseline) {
            if ($baseline['source_attack_id'] !== null) {
                $sourceAttackIdsByResource[$resource] = [$baseline['source_attack_id']];
            }
        }

        $boundaries = [$projectionStart, $asOf];
        foreach ($baselines as $baseline) {
            if ($baseline['observed_at']->greaterThan($projectionStart)
                && $baseline['observed_at']->lessThan($asOf)) {
                $boundaries[] = $baseline['observed_at'];
            }
        }
        foreach ($production['contexts'] as $context) {
            $effectiveAt = $context['_effective_at'];
            if ($effectiveAt->greaterThan($projectionStart) && $effectiveAt->lessThan($asOf)) {
                $boundaries[] = $effectiveAt;
            }
        }
        foreach ($attacks as $attack) {
            if ($attack['date']->greaterThan($projectionStart) && $attack['date']->lessThanOrEqualTo($asOf)
                && $this->attackAffectsTarget($attack, $nationId)) {
                $boundaries[] = $attack['date'];
            }
        }
        usort($boundaries, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());
        $boundaries = $this->uniqueDates($boundaries);

        for ($index = 0, $count = count($boundaries) - 1; $index < $count; $index++) {
            $segmentStart = $boundaries[$index];
            $segmentEnd = $boundaries[$index + 1];
            $activeResources = collect($baselines)
                ->filter(fn (array $baseline): bool => $baseline['observed_at']->lessThanOrEqualTo($segmentStart))
                ->keys()
                ->all();

            if ($activeResources !== []) {
                $this->advanceScenarioSegment(
                    $scenarios,
                    $activeResources,
                    $segmentStart,
                    $segmentEnd,
                    $production,
                    $assumptions,
                    $defaultVacationWindow,
                );
                $usedProjection = true;
            }

            foreach ($attacks as $attack) {
                if (! $attack['date']->equalTo($segmentEnd) || ! $this->attackAffectsTarget($attack, $nationId)) {
                    continue;
                }

                foreach ($activeResources as $resource) {
                    if (in_array($attack['id'], $sourceAttackIdsByResource[$resource] ?? [], true)) {
                        continue;
                    }

                    $amount = (float) ($attack['resources'][$resource] ?? 0.0);
                    if ($amount <= 0) {
                        continue;
                    }
                    foreach (array_keys($scenarios) as $scenario) {
                        $current = $scenarios[$scenario][$resource];
                        if ($current !== null) {
                            $scenarios[$scenario][$resource] = max(0.0, $current - $amount);
                        }
                    }
                }

                if ($attack['resources'] !== [] || $attack['bank_resources'] !== []) {
                    $provenanceWarIds = array_merge($provenanceWarIds, $attack['provenance_war_ids']);
                }
            }
        }

        // The final boundary may be equal to the start for an as-of snapshot;
        // in every other case the loop above advances through the full interval.
        if ($asOf->greaterThan($projectionStart) && count($boundaries) < 2) {
            $this->advanceScenarioSegment(
                $scenarios,
                array_keys($baselines),
                $projectionStart,
                $asOf,
                $production,
                $assumptions,
                $defaultVacationWindow,
            );
            $usedProjection = true;
        }

        return $usedProjection;
    }

    /**
     * @param  array{lower: array<string, float|null>, base: array<string, float|null>, upper: array<string, float|null>}  $scenarios
     * @param  array{
     *     output: array<string, float>,
     *     expense: array<string, float>,
     *     net: array<string, float>,
     *     processes: list<array{output: array<string, float>, inputs: array<string, float>}>,
     *     contexts: list<array<string, mixed>>,
     *     default_vacation_mode: bool,
     *     default_vacation_seconds: float|null,
     *     has_vectors: bool
     * }  $production
     * @param  array{active: bool, ends_at: CarbonImmutable|null}  $defaultVacationWindow
     * @param  array<string, string>  $assumptions
     */
    private function advanceScenarioSegment(
        array &$scenarios,
        array $activeResources,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $production,
        array &$assumptions,
        array $defaultVacationWindow,
    ): void {
        if (! $end->greaterThan($start)) {
            return;
        }

        $boundaries = [$start, $end];
        foreach ($production['contexts'] as $context) {
            $effectiveAt = $context['_effective_at'];
            if ($effectiveAt->greaterThan($start) && $effectiveAt->lessThan($end)) {
                $boundaries[] = $effectiveAt;
            }
        }
        usort($boundaries, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());

        for ($index = 0, $count = count($boundaries) - 1; $index < $count; $index++) {
            $segmentStart = $boundaries[$index];
            $segmentEnd = $boundaries[$index + 1];
            $context = $this->productionContextAt($production['contexts'], $segmentStart);
            $vacationWindow = $context === null
                ? $defaultVacationWindow
                : $this->vacationWindow($context, $segmentStart, $production);
            $productionStart = $segmentStart;
            if ($vacationWindow['active']) {
                if ($vacationWindow['ends_at'] === null) {
                    continue;
                }

                if ($vacationWindow['ends_at']->greaterThanOrEqualTo($segmentEnd)) {
                    continue;
                }

                if ($vacationWindow['ends_at']->greaterThan($segmentStart)) {
                    $productionStart = $vacationWindow['ends_at'];
                }
            }

            $days = $productionStart->diffInSeconds($segmentEnd) / self::SECONDS_PER_DAY;
            if ($days <= 0) {
                continue;
            }

            $output = $context === null
                ? $production['output']
                : $this->contextVector($context, ['daily_output', 'daily_outputs', 'resource_output_per_day', 'output_per_day']);
            $expense = $context === null
                ? $production['expense']
                : $this->contextVector($context, ['daily_expense', 'daily_expenses', 'resource_expense_per_day', 'expense_per_day'], absolute: true);
            $net = $context === null
                ? $production['net']
                : $this->contextVector($context, ['daily_net', 'daily_profit', 'resource_profit_per_day', 'net_per_day']);
            if ($context !== null) {
                foreach ($net as $resource => $amount) {
                    if (($output[$resource] ?? 0.0) != 0.0 || ($expense[$resource] ?? 0.0) != 0.0) {
                        continue;
                    }

                    if ($amount >= 0) {
                        $output[$resource] = $amount;
                    } else {
                        $expense[$resource] = abs($amount);
                    }
                }
            }

            $processes = $context === null
                ? $production['processes']
                : $this->contextProcesses($context, $assumptions, $production['processes']);
            $processOutputs = [];
            $processInputs = [];
            foreach ($processes as $process) {
                foreach ($process['output'] as $resource => $amount) {
                    $processOutputs[$resource] = ($processOutputs[$resource] ?? 0.0) + $amount;
                }
                foreach ($process['inputs'] as $resource => $amount) {
                    $processInputs[$resource] = ($processInputs[$resource] ?? 0.0) + $amount;
                }
            }

            foreach (array_keys($scenarios) as $scenario) {
                foreach ($activeResources as $resource) {
                    $current = $scenarios[$scenario][$resource];
                    if ($current === null) {
                        continue;
                    }

                    $grossOutput = max(0.0, (float) ($output[$resource] ?? 0.0) - (float) ($processOutputs[$resource] ?? 0.0)) * $days;
                    $grossExpense = max(0.0, (float) ($expense[$resource] ?? 0.0) - (float) ($processInputs[$resource] ?? 0.0)) * $days;

                    if ($grossOutput === 0.0 && $grossExpense === 0.0 && ! array_key_exists($resource, $output) && ! array_key_exists($resource, $expense)) {
                        $netAmount = (float) ($net[$resource] ?? 0.0) * $days;
                        $scenarios[$scenario][$resource] = max(0.0, $current + $netAmount);

                        continue;
                    }

                    // Raw production and non-process operating costs are
                    // applied without manufacturing scaling. A missing balance
                    // is still clamped at zero; only explicit process inputs
                    // prevent their associated output from being created.
                    $scenarios[$scenario][$resource] = max(0.0, $current + $grossOutput - $grossExpense);
                }

                // Every process in this segment draws from one shared budget.
                // A separate scale calculation against the starting balance
                // would allow two buildings to consume the same input.
                $remainingInputs = [];
                foreach ($processInputs as $input => $amount) {
                    $remainingInputs[$input] = in_array($input, $activeResources, true)
                        ? $scenarios[$scenario][$input]
                        : null;
                }

                foreach ($processes as $process) {
                    $scale = 1.0;
                    foreach ($process['inputs'] as $input => $amount) {
                        if ($amount <= 0) {
                            continue;
                        }
                        if (! array_key_exists($input, $remainingInputs) || $remainingInputs[$input] === null) {
                            $this->addAssumption($assumptions, "Manufacturing output using unknown {$input} stockpile was omitted from the projection.");
                            $scale = 0.0;
                            break;
                        }
                        $available = $remainingInputs[$input];
                        $required = $amount * $days;
                        if ($required > 0) {
                            $scale = min($scale, max(0.0, $available / $required));
                        }
                    }
                    $scale = max(0.0, min(1.0, $scale));

                    foreach ($process['output'] as $resource => $amount) {
                        if (in_array($resource, $activeResources, true) && $scenarios[$scenario][$resource] !== null) {
                            $produced = $amount * $days * $scale;
                            $scenarios[$scenario][$resource] = max(0.0, $scenarios[$scenario][$resource] + $produced);
                            if (array_key_exists($resource, $remainingInputs) && $remainingInputs[$resource] !== null) {
                                $remainingInputs[$resource] += $produced;
                            }
                        }
                    }
                    foreach ($process['inputs'] as $resource => $amount) {
                        if (in_array($resource, $activeResources, true) && $scenarios[$scenario][$resource] !== null) {
                            $consumed = $amount * $days * $scale;
                            $scenarios[$scenario][$resource] = max(0.0, $scenarios[$scenario][$resource] - $consumed);
                            if (array_key_exists($resource, $remainingInputs) && $remainingInputs[$resource] !== null) {
                                $remainingInputs[$resource] = max(0.0, $remainingInputs[$resource] - $consumed);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $contexts
     * @return array<string, mixed>|null
     */
    private function productionContextAt(array $contexts, CarbonImmutable $at): ?array
    {
        $selected = null;
        foreach ($contexts as $context) {
            if ($context['_effective_at']->greaterThan($at)) {
                break;
            }
            $selected = $context;
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $assumptions
     * @param  list<array{output: array<string, float>, inputs: array<string, float>}>  $fallback
     * @return list<array{output: array<string, float>, inputs: array<string, float>}>
     */
    private function contextProcesses(array $context, array &$assumptions, array $fallback): array
    {
        if (! array_key_exists('production_processes', $context)) {
            return $fallback;
        }

        return $this->normaliseProcesses($context['production_processes'], $assumptions);
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @return list<CarbonImmutable>
     */
    private function uniqueDates(array $dates): array
    {
        $unique = [];
        foreach ($dates as $date) {
            $unique[$date->format('Y-m-d H:i:s.uP')] = $date;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private function contextVector(array $context, array $keys, bool $absolute = false): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context)) {
                return $this->normaliseResourceMap($context[$key], absolute: $absolute);
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private function firstResourceMap(array $source, array $keys, bool $absolute = false): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return $this->normaliseResourceMap($source[$key], absolute: $absolute);
            }
        }

        return [];
    }

    /**
     * @return array<string, float>
     */
    private function normaliseResourceMap(mixed $value, bool $absolute = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $resources = [];
        foreach ($value as $key => $amount) {
            $resource = strtolower(trim((string) $key));
            $resource = preg_replace('/_looted$/', '', $resource) ?? $resource;
            if (! in_array($resource, EconomyRules::RESOURCE_KEYS, true)) {
                continue;
            }

            $number = $this->number($amount);
            if ($number === null) {
                continue;
            }

            $resources[$resource] = $absolute ? abs($number) : $number;
        }

        return $resources;
    }

    /**
     * @param  array<string, array{
     *     observed_at: CarbonImmutable,
     *     event_id: int,
     *     source_attack_id: int|null,
     *     source_war_id: int|null,
     *     provenance_war_ids: list<int>,
     *     modifier_known: bool,
     *     lower: float,
     *     base: float,
     *     upper: float
     * }>  $baselines
     * @return list<int>
     */
    private function baselineProvenance(array $baselines): array
    {
        $ids = [];
        foreach ($baselines as $baseline) {
            $ids = array_merge($ids, $baseline['provenance_war_ids']);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        try {
            if ($value instanceof CarbonImmutable) {
                return $value->utc();
            }

            if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc();
            }

            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value, 'UTC')->utc();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function normaliseLootFraction(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
            $percent = str_ends_with($value, '%');
            $value = rtrim($value, '%');
            if (! is_numeric($value)) {
                return null;
            }
            $value = (float) $value;
            if ($percent || $value > 1) {
                $value /= 100;
            }
        } elseif (is_numeric($value)) {
            $value = (float) $value;
            if ($value > 1) {
                $value /= 100;
            }
        } else {
            return null;
        }

        return is_finite($value) && $value > 0 && $value < 1 ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        if (! is_numeric($value) || is_bool($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function integer(mixed $value): ?int
    {
        $number = $this->number($value);
        if ($number === null || floor($number) !== $number) {
            return null;
        }

        return (int) $number;
    }

    /**
     * @return list<int>
     */
    private function normaliseIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (! is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = $this->integer($item);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  int|list<int>|null  $candidate
     * @param  list<int>  $excluded
     */
    private function containsExcludedId(int|array|null $candidate, array $excluded): bool
    {
        if ($excluded === []) {
            return false;
        }

        if (is_array($candidate)) {
            return array_intersect($candidate, $excluded) !== [];
        }

        return $candidate !== null && in_array($candidate, $excluded, true);
    }

    private function formatNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, string>  $assumptions
     */
    private function addAssumption(array &$assumptions, string $assumption): void
    {
        $assumptions[$assumption] = $assumption;
    }
}
