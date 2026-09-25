<?php

namespace App\Services\Raids;

use App\Models\RaidLootEvent;
use App\Models\RaidModelParameter;
use App\Services\RaidStockpileEstimator;
use Illuminate\Support\Collection;

/**
 * Feeds victory backtests back into the stockpile estimator.
 *
 * Interval factors are the P10/P90 of revealed ÷ predicted value relative to the
 * bucket median; bias multipliers scale predictions toward the median outcome.
 */
final class RaidCalibrationService
{
    private const BIAS_MIN = 0.25;

    private const BIAS_MAX = 4.0;

    public function __construct(private RaidModelParameters $parameters) {}

    /**
     * @return array{sample_count: int, interval_factors: array<string, array{low: float, high: float}>, bias_multipliers: array<string, float>}
     */
    public function calibrate(): array
    {
        $minimumSamples = (int) config('raids.calibration.min_samples');
        $now = now();
        $samples = RaidLootEvent::query()
            ->where('kind', RaidLootEvent::KIND_VICTORY)
            ->where('predicted_value', '>', 0)
            ->where('revealed_value', '>', 0)
            ->where('occurred_at', '>=', $now->copy()->subDays((int) config('raids.calibration.window_days')))
            ->get(['predicted_value', 'revealed_value', 'prediction_evidence_kind', 'prediction_age_hours', 'prediction_activity_bucket'])
            ->map(fn (RaidLootEvent $event): array => [
                'ratio' => (float) $event->revealed_value / (float) $event->predicted_value,
                'interval_key' => $event->prediction_evidence_kind.':'.RaidStockpileEstimator::ageBucket((float) $event->prediction_age_hours),
                'bias_key' => $event->prediction_evidence_kind.':'.$event->prediction_activity_bucket,
            ]);

        $intervals = $this->eligibleGroups($samples, 'interval_key', $minimumSamples)
            ->map(function (array $ratios): array {
                $median = $this->percentile($ratios, 50);

                return [
                    'low' => round($this->percentile($ratios, 10) / $median, 4),
                    'high' => round($this->percentile($ratios, 90) / $median, 4),
                ];
            })
            ->all();

        $storedBias = $this->parameters->get(RaidModelParameter::BIAS_MULTIPLIERS);
        $bias = $this->eligibleGroups($samples, 'bias_key', $minimumSamples)
            ->map(fn (array $ratios, string $key): float => round(max(
                self::BIAS_MIN,
                min(self::BIAS_MAX, $this->percentile($ratios, 50) * (float) ($storedBias[$key] ?? 1.0)),
            ), 4))
            ->all();

        $this->store(RaidModelParameter::INTERVAL_FACTORS, $intervals, $samples->count());
        $this->store(RaidModelParameter::BIAS_MULTIPLIERS, $bias, $samples->count());
        $this->parameters->forget();

        return [
            'sample_count' => $samples->count(),
            'interval_factors' => $intervals,
            'bias_multipliers' => $bias,
        ];
    }

    /**
     * @param  Collection<int, array{ratio: float, interval_key: string, bias_key: string}>  $samples
     * @return Collection<string, list<float>>
     */
    private function eligibleGroups(Collection $samples, string $key, int $minimumSamples): Collection
    {
        return $samples
            ->groupBy($key)
            ->filter(fn (Collection $group): bool => $group->count() >= $minimumSamples)
            ->map(fn (Collection $group): array => $group->pluck('ratio')->sort()->values()->all());
    }

    /**
     * Merge newly calibrated buckets over the stored ones; buckets without enough
     * recent samples keep their previous value.
     *
     * @param  array<string, mixed>  $values
     */
    private function store(string $key, array $values, int $sampleCount): void
    {
        if ($values === []) {
            return;
        }

        $existing = RaidModelParameter::query()->find($key);

        RaidModelParameter::query()->updateOrCreate(['key' => $key], [
            'value' => array_replace(is_array($existing?->value) ? $existing->value : [], $values),
            'sample_count' => $sampleCount,
            'computed_at' => now(),
        ]);
    }

    /**
     * Nearest-rank percentile of an ascending list.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $percent): float
    {
        $rank = max(1, (int) ceil($percent / 100 * count($sorted)));

        return (float) $sorted[min($rank, count($sorted)) - 1];
    }
}
