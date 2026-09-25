<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RaidLootEvent;
use App\Models\RaidModelParameter;
use App\Models\RaidPrediction;
use App\Models\War;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Produces a rolling, model-versioned prediction accuracy report.
 */
final class RaidAssessmentService
{
    /** @var array<string, array{0: int, 1: int|null}> */
    private const RANK_BUCKETS = [
        '1_5' => [1, 5],
        '6_10' => [6, 10],
        '11_25' => [11, 25],
        '26_plus' => [26, null],
    ];

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly RaidStockpileEstimator $estimator,
    ) {}

    /**
     * Build an assessment for completed prediction/outcome pairs.
     *
     * Signed error is actual net return minus predicted expected net return.
     * A positive value therefore means the model was conservative.
     *
     * @return array<string, mixed>
     */
    public function assess(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?int $attackerNationId = null,
    ): array {
        $to ??= CarbonImmutable::now();
        $from ??= CarbonImmutable::instance($to)->subDays(30);

        $query = RaidPrediction::query()
            ->whereBetween('declared_at', [$from, $to])
            ->whereNotNull('expected_net')
            ->whereNotNull('actual_net')
            ->whereIn('outcome_status', [
                RaidPrediction::OUTCOME_WON,
                RaidPrediction::OUTCOME_LOST,
                RaidPrediction::OUTCOME_PEACE,
                RaidPrediction::OUTCOME_EXPIRED,
            ])
            ->when($attackerNationId !== null, fn ($builder) => $builder->where('attacker_nation_id', $attackerNationId));

        $predictions = $query->orderBy('declared_at')->get();
        // Terminal records may carry a partial ledger while the bounded
        // reconciliation refresh is still waiting on delayed attack data.
        // Exclude those rows from accuracy metrics without discarding their
        // capture or normalized evidence counts.
        $predictions = $predictions->filter(function (RaidPrediction $prediction): bool {
            return in_array(data_get($prediction->outcome_metadata, 'evidence_status'), [
                'complete',
                'preserved',
            ], true);
        })->values();
        $observations = $predictions->map(fn (RaidPrediction $prediction): array => $this->observation($prediction));
        $captureQuery = RaidPrediction::query()
            ->whereBetween('declared_at', [$from, $to])
            ->when($attackerNationId !== null, fn ($builder) => $builder->where('attacker_nation_id', $attackerNationId));
        $captureTotal = (clone $captureQuery)->count();
        $captureReady = (clone $captureQuery)->where('capture_status', RaidPrediction::CAPTURE_READY)->count();
        $captureIncomplete = (clone $captureQuery)->where('capture_status', RaidPrediction::CAPTURE_INCOMPLETE)->count();
        $captureDegraded = (clone $captureQuery)->where('capture_status', RaidPrediction::CAPTURE_DEGRADED)->count();
        $readinessPercent = $captureTotal > 0 ? round(($captureReady / $captureTotal) * 100, 2) : null;
        $captureCoverage = $this->captureCoverage($from, $to, $attackerNationId);

        return [
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'days' => round($from->diffInSeconds($to) / 86400, 4),
            ],
            'model_versions' => $predictions->pluck('model_version')->filter()->unique()->values()->all(),
            'sample_count' => $observations->count(),
            'capture' => [
                'total' => $captureTotal,
                'ready' => $captureReady,
                'incomplete' => $captureIncomplete,
                'degraded' => $captureDegraded,
                'outcome_sample_count' => $observations->count(),
                'readiness_percent' => $readinessPercent,
                'coverage' => $captureCoverage,
            ],
            'capture_readiness_percent' => $readinessPercent,
            'capture_coverage' => $captureCoverage,
            'metrics' => $this->metrics($observations),
            'breakdowns' => [
                'activity' => $this->breakdown($observations, 'activity'),
                'observation_age' => $this->breakdown($observations, 'observation_age'),
                'confidence' => $this->breakdown($observations, 'confidence'),
                'competition' => $this->breakdown($observations, 'competition'),
            ],
            'ranking' => $this->ranking($observations),
            'estimator' => $this->estimatorAccuracy($from, $to),
        ];
    }

    /**
     * World-wide stockpile estimator accuracy from victory backtests.
     *
     * Each victory stores the prediction the loser's profile held before the loot
     * arrived beside the stockpile the loot revealed.
     *
     * @return array<string, mixed>
     */
    public function estimatorAccuracy(CarbonInterface $from, CarbonInterface $to): array
    {
        $samples = RaidLootEvent::query()
            ->where('kind', RaidLootEvent::KIND_VICTORY)
            ->where('predicted_value', '>', 0)
            ->where('revealed_value', '>', 0)
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['predicted_value', 'revealed_value', 'prediction_evidence_kind', 'prediction_age_hours', 'prediction_activity_bucket'])
            ->map(function (RaidLootEvent $event): array {
                $predicted = (float) $event->predicted_value;
                $revealed = (float) $event->revealed_value;
                $kind = (string) ($event->prediction_evidence_kind ?? 'unknown');
                $ageHours = (float) ($event->prediction_age_hours ?? 0.0);
                $factors = $this->estimator->intervalFactorsFor($kind, $ageHours);

                return [
                    'ratio' => $revealed / $predicted,
                    'absolute_percent_error' => abs($revealed - $predicted) / $revealed * 100,
                    'covered' => $revealed >= $predicted * $factors['low'] && $revealed <= $predicted * $factors['high'],
                    'evidence_kind' => $kind,
                    'age_bucket' => RaidStockpileEstimator::ageBucket($ageHours),
                    'activity_bucket' => (string) ($event->prediction_activity_bucket ?? 'unknown'),
                ];
            });
        $computedAt = RaidModelParameter::query()->max('computed_at');

        return [
            ...$this->estimatorMetrics($samples),
            'breakdowns' => [
                'evidence_kind' => $this->estimatorBreakdown($samples, 'evidence_kind'),
                'age' => $this->estimatorBreakdown($samples, 'age_bucket'),
                'activity' => $this->estimatorBreakdown($samples, 'activity_bucket'),
            ],
            'model_parameters_computed_at' => $computedAt === null ? null : CarbonImmutable::parse((string) $computedAt)->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $samples
     * @return array{sample_count: int, median_ratio: float|null, median_absolute_percent_error: float|null, interval_coverage_percent: float|null}
     */
    private function estimatorMetrics(Collection $samples): array
    {
        $count = $samples->count();

        return [
            'sample_count' => $count,
            'median_ratio' => $this->median($samples->pluck('ratio')->all(), 4),
            'median_absolute_percent_error' => $this->median($samples->pluck('absolute_percent_error')->all(), 2),
            'interval_coverage_percent' => $count > 0
                ? round($samples->where('covered', true)->count() / $count * 100, 2)
                : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $samples
     * @return array<string, array<string, mixed>>
     */
    private function estimatorBreakdown(Collection $samples, string $field): array
    {
        return $samples
            ->groupBy($field)
            ->map(fn (Collection $group): array => $this->estimatorMetrics($group))
            ->sortKeys()
            ->all();
    }

    /**
     * Finder ranking quality: outcomes grouped by the rank a target was shown at.
     *
     * @param  Collection<int, array<string, mixed>>  $observations
     * @return array{buckets: array<string, array{count: int, mean_expected_net: float|null, mean_actual_net: float|null}>, top5_share: float|null}
     */
    private function ranking(Collection $observations): array
    {
        $buckets = [];

        foreach ([...array_keys(self::RANK_BUCKETS), 'not_from_finder'] as $bucket) {
            $group = $observations->where('rank_bucket', $bucket);
            $buckets[$bucket] = [
                'count' => $group->count(),
                'mean_expected_net' => $this->average($group->pluck('expected_net')->all()),
                'mean_actual_net' => $this->average($group->pluck('actual_net')->all()),
            ];
        }

        $ranked = $observations->whereNotNull('finder_rank');

        return [
            'buckets' => $buckets,
            'top5_share' => $ranked->isEmpty()
                ? null
                : round($ranked->filter(fn (array $observation): bool => $observation['finder_rank'] <= 5)->count() / $ranked->count() * 100, 2),
        ];
    }

    private function rankBucket(?int $rank): string
    {
        if ($rank === null) {
            return 'not_from_finder';
        }

        foreach (self::RANK_BUCKETS as $bucket => [$minimum, $maximum]) {
            if ($rank >= $minimum && ($maximum === null || $rank <= $maximum)) {
                return $bucket;
            }
        }

        return 'not_from_finder';
    }

    /**
     * @param  list<float|int>  $values
     */
    private function median(array $values, int $precision): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];

        return round((float) $median, $precision);
    }

    /**
     * Measure capture coverage against qualifying world declarations visible
     * after the first prospective capture. Historical rows before that point
     * are intentionally outside the denominator so imports cannot become
     * fabricated missing captures.
     *
     * @return array<string, mixed>
     */
    private function captureCoverage(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $attackerNationId,
    ): array {
        $baselineQuery = RaidPrediction::query()
            ->whereNotNull('declared_at')
            ->when($attackerNationId !== null, fn ($builder) => $builder->where('attacker_nation_id', $attackerNationId));
        $firstCapturedAt = (clone $baselineQuery)->min('declared_at');
        if ($firstCapturedAt === null) {
            return [
                'status' => 'unknown',
                'percent' => null,
                'known_qualifying_declarations' => null,
                'captured_qualifying_declarations' => 0,
                'missing_capture_count' => null,
                'pending_capture_count' => null,
                'source' => 'no_captured_prediction_rows',
                'baseline_start' => null,
            ];
        }

        try {
            $firstCaptured = CarbonImmutable::parse((string) $firstCapturedAt);
        } catch (\Throwable) {
            return [
                'status' => 'unknown',
                'percent' => null,
                'known_qualifying_declarations' => null,
                'captured_qualifying_declarations' => 0,
                'missing_capture_count' => null,
                'pending_capture_count' => null,
                'source' => 'invalid_capture_baseline',
                'baseline_start' => null,
            ];
        }

        $baseline = $firstCaptured->greaterThan(CarbonImmutable::instance($from))
            ? $firstCaptured
            : CarbonImmutable::instance($from);
        if ($baseline->greaterThan(CarbonImmutable::instance($to))) {
            return [
                'status' => 'unknown',
                'percent' => null,
                'known_qualifying_declarations' => null,
                'captured_qualifying_declarations' => 0,
                'missing_capture_count' => null,
                'pending_capture_count' => null,
                'source' => 'report_window_before_capture_baseline',
                'baseline_start' => $baseline->toIso8601String(),
            ];
        }

        $allianceIds = $this->membershipService->getAllianceIds()
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($allianceIds === []) {
            return [
                'status' => 'unknown',
                'percent' => null,
                'known_qualifying_declarations' => 0,
                'captured_qualifying_declarations' => 0,
                'missing_capture_count' => 0,
                'pending_capture_count' => 0,
                'source' => 'known_world_wars_since_capture_baseline',
                'baseline_start' => $baseline->toIso8601String(),
            ];
        }

        $knownWarQuery = War::query()
            ->whereBetween('date', [$baseline, $to])
            ->where('war_type', 'RAID')
            ->whereIn('att_alliance_id', $allianceIds)
            ->where(function ($query): void {
                $query
                    ->whereNull('att_alliance_position')
                    ->orWhereRaw("UPPER(att_alliance_position) <> 'APPLICANT'");
            });

        if ($attackerNationId !== null) {
            $knownWarQuery->where('att_id', $attackerNationId);
        }

        $knownWarIds = $knownWarQuery->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
        if ($knownWarIds->isEmpty()) {
            return [
                'status' => 'unknown',
                'percent' => null,
                'known_qualifying_declarations' => 0,
                'captured_qualifying_declarations' => 0,
                'missing_capture_count' => 0,
                'pending_capture_count' => 0,
                'source' => 'known_world_wars_since_capture_baseline',
                'baseline_start' => $baseline->toIso8601String(),
            ];
        }

        $knownCount = $knownWarIds->count();
        $capturedQuery = RaidPrediction::query()
            ->whereIn('war_id', $knownWarIds->all())
            ->when($attackerNationId !== null, fn ($builder) => $builder->where('attacker_nation_id', $attackerNationId));
        $capturedCount = (clone $capturedQuery)->count();
        $pendingCount = (clone $capturedQuery)
            ->where(function ($query): void {
                $query
                    ->whereNull('capture_status')
                    ->orWhere('capture_status', '<>', RaidPrediction::CAPTURE_READY);
            })
            ->count();

        return [
            'status' => 'known',
            'percent' => round(($capturedCount / $knownCount) * 100, 2),
            'known_qualifying_declarations' => $knownCount,
            'captured_qualifying_declarations' => $capturedCount,
            'missing_capture_count' => max(0, $knownCount - $capturedCount),
            'pending_capture_count' => $pendingCount,
            'source' => 'known_world_wars_since_capture_baseline',
            'baseline_start' => $baseline->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function observation(RaidPrediction $prediction): array
    {
        $expected = (float) $prediction->expected_net;
        $actual = (float) $prediction->actual_net;
        $signedError = $actual - $expected;
        [$lower, $upper] = $this->predictionRange($prediction);

        return [
            'prediction_id' => (int) $prediction->id,
            'expected_net' => $expected,
            'actual_net' => $actual,
            'signed_error' => $signedError,
            'absolute_error' => abs($signedError),
            'range_lower' => $lower,
            'range_upper' => $upper,
            'range_covered' => $lower !== null && $upper !== null
                ? $actual >= $lower && $actual <= $upper
                : null,
            'expected_gross_loot' => $this->numeric($prediction->gross_loot),
            'actual_gross_loot' => $this->numeric($prediction->actual_gross_loot),
            'expected_duration_hours' => $this->numeric($prediction->duration_hours),
            'actual_duration_hours' => $this->numeric($prediction->actual_duration_hours),
            'expected_cost' => $this->expectedCost($prediction),
            'actual_cost' => $this->actualCost($prediction),
            'cost_error' => $this->costError($prediction),
            'component_error' => $this->componentErrors($prediction),
            'resource_error' => $this->resourceErrors($prediction->loot_resources, $prediction->actual_loot_resources),
            'resource_error_basis' => 'realized_loot_vs_prediction',
            'cost_resource_error' => $this->resourceErrors($prediction->cost_resources, $prediction->actual_cost_resources),
            'observation_age' => $this->observationAgeBucket($prediction),
            'confidence' => $this->confidenceBucket($prediction),
            'competition' => $this->competitionBucket($prediction),
            'activity' => $this->activityBucket($prediction),
            'plan_adherence' => $this->planAdherence($prediction),
            'stockpile_error' => (array) data_get($prediction->outcome_metadata, 'stockpile_estimation.stockpile_error', []),
            'finder_rank' => $prediction->finder_rank,
            'rank_bucket' => $this->rankBucket($prediction->finder_rank),
            'win_probability' => $this->numeric($prediction->win_probability),
            'victory_probability' => $this->numeric($prediction->victory_probability),
            'won' => $prediction->outcome_status === RaidPrediction::OUTCOME_WON,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $observations @return array<string, mixed> */
    private function metrics(Collection $observations): array
    {
        $count = $observations->count();
        $coverage = $observations->whereNotNull('range_covered');
        $covered = $coverage->where('range_covered', true)->count();
        $durationPairs = $observations->filter(
            fn (array $observation): bool => $observation['expected_duration_hours'] !== null
                && $observation['actual_duration_hours'] !== null,
        );
        $componentErrors = [];
        foreach ($observations as $observation) {
            foreach ($observation['component_error'] as $component => $error) {
                $componentErrors[$component][] = $error;
            }
        }
        $resourceErrors = $this->errorMap($observations, 'resource_error');
        $costResourceErrors = $this->errorMap($observations, 'cost_resource_error');
        $planRows = $observations->pluck('plan_adherence')->filter(
            fn (mixed $plan): bool => is_array($plan) && ($plan['status'] ?? null) === 'observed',
        );

        return [
            'signed_error' => $this->average($observations->pluck('signed_error')->all()),
            'mean_absolute_error' => $this->average($observations->pluck('absolute_error')->all()),
            'expected_net_total' => round((float) $observations->sum('expected_net'), 2),
            'actual_net_total' => round((float) $observations->sum('actual_net'), 2),
            'expected_gross_loot_total' => round((float) $observations->sum('expected_gross_loot'), 2),
            'actual_gross_loot_total' => round((float) $observations->sum('actual_gross_loot'), 2),
            'expected_cost_total' => round((float) $observations->sum('expected_cost'), 2),
            'actual_cost_total' => round((float) $observations->sum('actual_cost'), 2),
            'cost_error' => $this->average($observations->pluck('cost_error')->filter(fn (mixed $error): bool => $error !== null)->all()),
            'range_coverage' => [
                'covered' => $covered,
                'eligible' => $coverage->count(),
                'percent' => $coverage->count() > 0 ? round(($covered / $coverage->count()) * 100, 2) : null,
            ],
            'duration_error_hours' => $this->average($durationPairs->map(
                fn (array $observation): float => (float) $observation['actual_duration_hours']
                    - (float) $observation['expected_duration_hours'],
            )->all()),
            'component_errors' => collect($componentErrors)
                ->map(fn (array $errors): ?float => $this->average($errors))
                ->all(),
            'resource_errors' => $resourceErrors,
            'resource_errors_basis' => 'realized_loot_vs_prediction; conditional on execution and competition',
            'stockpile_errors' => $this->errorMap($observations, 'stockpile_error'),
            'cost_resource_errors' => $costResourceErrors,
            'plan_adherence' => [
                'known' => $planRows->count(),
                'mean_score' => $this->average($planRows->pluck('score')->filter(fn (mixed $score): bool => $score !== null)->map(fn (mixed $score): float => (float) $score)->all()),
                'matched_actions' => (int) $planRows->sum(fn (array $plan): int => (int) ($plan['matched_actions'] ?? 0)),
                'observed_actions' => (int) $planRows->sum(fn (array $plan): int => count((array) ($plan['observed_actions'] ?? []))),
            ],
            'victory_calibration' => $this->victoryCalibration($observations),
            'sample_count' => $count,
        ];
    }

    /**
     * Mean predicted victory probability against the realised win share.
     *
     * @param  Collection<int, array<string, mixed>>  $observations
     * @return array{sample_count: int, predicted_percent: float|null, actual_percent: float|null}
     */
    private function victoryCalibration(Collection $observations): array
    {
        $predicted = $observations->whereNotNull('victory_probability');

        return [
            'sample_count' => $predicted->count(),
            'predicted_percent' => $predicted->isEmpty() ? null : round($predicted->avg('victory_probability') * 100, 2),
            'actual_percent' => $predicted->isEmpty() ? null : round($predicted->where('won', true)->count() / $predicted->count() * 100, 2),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $observations @return array<string, mixed> */
    private function breakdown(Collection $observations, string $field): array
    {
        return $observations
            ->groupBy($field)
            ->map(fn (Collection $group): array => [
                'sample_count' => $group->count(),
                'metrics' => $this->metrics($group),
            ])
            ->sortKeys()
            ->all();
    }

    /** @return array{0: float|null, 1: float|null} */
    private function predictionRange(RaidPrediction $prediction): array
    {
        $lower = $this->numeric($prediction->expected_net_low);
        $upper = $this->numeric($prediction->expected_net_high);

        if ($lower === null || $upper === null) {
            return [$lower, $upper];
        }

        return [min($lower, $upper), max($lower, $upper)];
    }

    /** @return array<string, float> */
    private function componentErrors(RaidPrediction $prediction): array
    {
        $expected = is_array($prediction->components) ? $prediction->components : [];
        $actual = is_array($prediction->actual_components) ? $prediction->actual_components : [];
        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        $errors = [];
        foreach ($keys as $key) {
            if (is_numeric($expected[$key] ?? null) && is_numeric($actual[$key] ?? null)) {
                $errors[$key] = (float) $actual[$key] - (float) $expected[$key];
            }
        }

        return $errors;
    }

    /** @param array<string, mixed>|null $expected @param array<string, mixed>|null $actual @return array<string, float> */
    private function resourceErrors(?array $expected, ?array $actual): array
    {
        $expected ??= [];
        $actual ??= [];
        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        $errors = [];
        foreach ($keys as $key) {
            if (is_numeric($expected[$key] ?? null) && is_numeric($actual[$key] ?? null)) {
                $errors[(string) $key] = (float) $actual[$key] - (float) $expected[$key];
            }
        }

        return $errors;
    }

    /** @param Collection<int, array<string, mixed>> $observations @return array<string, float> */
    private function errorMap(Collection $observations, string $field): array
    {
        $values = [];
        foreach ($observations as $observation) {
            foreach ((array) ($observation[$field] ?? []) as $resource => $error) {
                if (is_numeric($error)) {
                    $values[(string) $resource][] = (float) $error;
                }
            }
        }

        return collect($values)
            ->map(fn (array $errors): ?float => $this->average($errors))
            ->all();
    }

    private function expectedCost(RaidPrediction $prediction): ?float
    {
        $components = is_array($prediction->components) ? $prediction->components : [];
        $values = [];
        foreach (['consumables', 'military_losses', 'infrastructure_losses', 'resource_costs', 'costs'] as $key) {
            if (is_numeric($components[$key] ?? null)) {
                $values[] = (float) $components[$key];
            }
        }

        return $values === [] ? null : array_sum($values);
    }

    private function actualCost(RaidPrediction $prediction): ?float
    {
        $components = is_array($prediction->actual_components) ? $prediction->actual_components : [];
        $values = [];
        foreach (['consumables', 'military_losses', 'infrastructure_losses', 'resource_costs', 'military_loss_value', 'infrastructure_loss_value'] as $key) {
            if (is_numeric($components[$key] ?? null)) {
                $values[] = (float) $components[$key];
            }
        }

        return $values === [] ? null : array_sum($values);
    }

    private function costError(RaidPrediction $prediction): ?float
    {
        $expected = $this->expectedCost($prediction);
        $actual = $this->actualCost($prediction);

        return $expected === null || $actual === null ? null : $actual - $expected;
    }

    private function observationAgeBucket(RaidPrediction $prediction): string
    {
        if ($prediction->observed_at === null || $prediction->captured_at === null) {
            return 'unknown';
        }

        $hours = abs($prediction->observed_at->diffInSeconds($prediction->captured_at)) / 3600;

        return match (true) {
            $hours < 24 => 'under_1_day',
            $hours < 72 => '1_to_3_days',
            $hours < 168 => '4_to_7_days',
            default => 'over_7_days',
        };
    }

    private function activityBucket(RaidPrediction $prediction): string
    {
        $target = is_array($prediction->target_snapshot) ? $prediction->target_snapshot : [];

        if (is_string($target['activity_bucket'] ?? null) && $target['activity_bucket'] !== '') {
            return $target['activity_bucket'];
        }

        $activity = $target['activity'] ?? $target['activity_level'] ?? $target['last_active'] ?? $target['last_active_at'] ?? null;

        if (is_bool($activity)) {
            return $activity ? 'active' : 'inactive';
        }

        if (is_numeric($activity)) {
            $numeric = (float) $activity;
            if ($numeric > 100000000) {
                try {
                    $activity = CarbonImmutable::createFromTimestamp((int) $numeric);
                } catch (\Throwable) {
                    return 'unknown';
                }
            } else {
                return $this->hoursActivityBucket($numeric);
            }
        }

        if (is_string($activity) && $activity !== '') {
            try {
                $activity = CarbonImmutable::parse($activity);
            } catch (\Throwable) {
                return strtolower($activity);
            }
        }

        if ($activity instanceof CarbonInterface && $prediction->declared_at !== null) {
            return $this->hoursActivityBucket(abs($activity->diffInSeconds($prediction->declared_at)) / 3600);
        }

        return 'unknown';
    }

    private function hoursActivityBucket(float $hours): string
    {
        return match (true) {
            $hours < 24 => 'under_1_day',
            $hours < 72 => '1_to_3_days',
            $hours < 168 => '4_to_7_days',
            default => 'over_7_days',
        };
    }

    /** @return array<string, mixed> */
    private function planAdherence(RaidPrediction $prediction): array
    {
        $metadata = is_array($prediction->outcome_metadata) ? $prediction->outcome_metadata : [];
        $plan = $metadata['plan_adherence'] ?? null;

        if (! is_array($plan)) {
            return ['status' => 'unavailable', 'score' => null, 'matched_actions' => 0];
        }

        return [
            'status' => (string) ($plan['status'] ?? 'unavailable'),
            'score' => is_numeric($plan['score'] ?? null) ? (float) $plan['score'] : null,
            'matched_actions' => (int) ($plan['matched_actions'] ?? 0),
        ];
    }

    private function confidenceBucket(RaidPrediction $prediction): string
    {
        $confidence = $prediction->confidence;

        return is_string($confidence) && $confidence !== '' ? strtolower($confidence) : 'unknown';
    }

    private function competitionBucket(RaidPrediction $prediction): string
    {
        $context = is_array($prediction->context_snapshot) ? $prediction->context_snapshot : [];

        if (is_numeric($context['competing_attackers'] ?? null)) {
            return match ((int) $context['competing_attackers']) {
                0 => 'none',
                1 => 'low',
                2 => 'medium',
                default => 'high',
            };
        }

        $competition = $context['competition'] ?? $context['competition_level'] ?? null;

        if (is_string($competition) && $competition !== '') {
            return strtolower($competition);
        }

        if (is_numeric($competition)) {
            return match (true) {
                (float) $competition <= 0 => 'none',
                (float) $competition < 2 => 'low',
                (float) $competition < 4 => 'medium',
                default => 'high',
            };
        }

        return 'unknown';
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @param list<float|int> $values */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }
}
