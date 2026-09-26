<?php

namespace App\Services;

use App\Models\RaidModelParameter;
use App\Models\RaidTargetProfile;
use App\Services\Raids\RaidActivity;
use App\Services\Raids\RaidModelParameters;
use App\Services\Raids\RaidResources;
use Carbon\CarbonImmutable;

/**
 * Projects a target's stockpile forward from its baseline using daily net production,
 * measured retention, and calibrated bias and interval parameters.
 */
class RaidStockpileEstimator
{
    private const BIAS_MIN = 0.25;

    private const BIAS_MAX = 4.0;

    public function __construct(private RaidModelParameters $parameters) {}

    /**
     * @return array{resources: array<string, float>, retention: float, days: float, manufacturing_stopped_after_days: float|null}
     */
    public function project(
        RaidTargetProfile $profile,
        CarbonImmutable $at,
        ?float $retentionOverride = null,
        bool $applyBias = true,
    ): array {
        $projection = $this->projectFrom(
            $profile->baselineResources(),
            $profile->dailyNet(),
            CarbonImmutable::instance($profile->baseline_at ?? $at),
            $at,
            $retentionOverride ?? $this->effectiveRetention($profile, $at),
        );

        if ($applyBias) {
            $bias = $this->biasMultiplier($profile, $at);
            $projection['resources'] = RaidResources::rounded(array_map(
                fn (float $amount): float => $amount * $bias,
                $projection['resources'],
            ));
        }

        return $projection;
    }

    /**
     * Step a stockpile forward one day at a time. Positive production is scaled by retention;
     * consumption is not. Manufacturing stops once a consumed raw input runs out.
     *
     * @param  array<string, float>  $baseline
     * @param  array<string, float>  $dailyNet
     * @return array{resources: array<string, float>, retention: float, days: float, manufacturing_stopped_after_days: float|null}
     */
    public function projectFrom(
        array $baseline,
        array $dailyNet,
        CarbonImmutable $from,
        CarbonImmutable $to,
        float $retention,
    ): array {
        $maximumDays = (float) config('raids.estimator.max_projection_days');
        $days = max(0.0, min($maximumDays, ($to->getTimestamp() - $from->getTimestamp()) / 86400));
        $stock = array_replace(RaidResources::empty(), array_map('floatval', $baseline));
        $manufacturingStopped = false;
        $stoppedAfter = null;
        $remaining = $days;
        $dayIndex = 0.0;

        while ($remaining > 0) {
            $step = min(1.0, $remaining);

            foreach ($stock as $resource => $amount) {
                $net = (float) ($dailyNet[$resource] ?? 0.0);

                if ($net > 0) {
                    if ($manufacturingStopped && in_array($resource, RaidResources::MANUFACTURED, true)) {
                        continue;
                    }

                    $stock[$resource] = $amount + $net * $retention * $step;
                } else {
                    $stock[$resource] = max(0.0, $amount + $net * $step);
                }
            }

            if (! $manufacturingStopped) {
                foreach (RaidResources::RAW as $resource) {
                    if ((float) ($dailyNet[$resource] ?? 0.0) < 0 && $stock[$resource] <= 0) {
                        $manufacturingStopped = true;
                        $stoppedAfter = $dayIndex + $step;
                        break;
                    }
                }
            }

            $remaining -= $step;
            $dayIndex += $step;
        }

        return [
            'resources' => RaidResources::rounded(array_map(fn (float $amount): float => max(0.0, $amount), $stock)),
            'retention' => $retention,
            'days' => round($days, 4),
            'manufacturing_stopped_after_days' => $stoppedAfter === null ? null : round($stoppedAfter, 4),
        ];
    }

    /**
     * @return array{low: float, high: float}
     */
    public function intervalFactors(RaidTargetProfile $profile, CarbonImmutable $at): array
    {
        return $this->intervalFactorsFor((string) $profile->baseline_kind, $this->ageHours($profile, $at));
    }

    /**
     * Interval factors for an evidence kind and baseline age.
     *
     * @return array{low: float, high: float}
     */
    public function intervalFactorsFor(string $evidenceKind, float $ageHours): array
    {
        $key = $evidenceKind.':'.self::ageBucket($ageHours);
        $factors = $this->parameters->get(RaidModelParameter::INTERVAL_FACTORS)[$key]
            ?? config('raids.estimator.default_interval_factors')[$key]
            ?? ['low' => 1.0, 'high' => 1.0];

        return [
            'low' => (float) ($factors['low'] ?? 1.0),
            'high' => (float) ($factors['high'] ?? 1.0),
        ];
    }

    public function biasMultiplier(RaidTargetProfile $profile, CarbonImmutable $at): float
    {
        $key = $profile->baseline_kind.':'.RaidActivity::bucket($profile->last_active, $at);
        $multiplier = $this->parameters->get(RaidModelParameter::BIAS_MULTIPLIERS)[$key] ?? null;

        return is_numeric($multiplier)
            ? max(self::BIAS_MIN, min(self::BIAS_MAX, (float) $multiplier))
            : 1.0;
    }

    /**
     * Blend the measured retention with the activity prior, weighted by sample count.
     */
    public function effectiveRetention(RaidTargetProfile $profile, CarbonImmutable $at): float
    {
        $prior = (float) config('raids.estimator.retention_priors')[RaidActivity::bucket($profile->last_active, $at)];
        $samples = (int) $profile->retention_samples;
        $weight = (float) config('raids.estimator.retention_prior_weight');

        if ($samples === 0 || $profile->retention_observed === null) {
            return $prior;
        }

        return ($samples * (float) $profile->retention_observed + $weight * $prior) / ($samples + $weight);
    }

    public static function ageBucket(float $hours): string
    {
        return match (true) {
            $hours < 168 => '0_7d',
            $hours < 720 => '7_30d',
            default => '30d_plus',
        };
    }

    private function ageHours(RaidTargetProfile $profile, CarbonImmutable $at): float
    {
        if ($profile->baseline_at === null) {
            return 0.0;
        }

        return max(0.0, ($at->getTimestamp() - $profile->baseline_at->getTimestamp()) / 3600);
    }
}
