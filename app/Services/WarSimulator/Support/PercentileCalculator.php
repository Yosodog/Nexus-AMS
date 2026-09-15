<?php

namespace App\Services\WarSimulator\Support;

final class PercentileCalculator
{
    /**
     * @param  array<int, float|int>  $values
     */
    public static function summarize(array $values): array
    {
        $count = count($values);

        if ($count === 0) {
            return [
                'mean' => 0.0,
                'p10' => 0.0,
                'p50' => 0.0,
                'p90' => 0.0,
            ];
        }

        $sum = array_sum($values);
        sort($values, SORT_NUMERIC);

        return [
            'mean' => $sum / $count,
            'p10' => self::percentile($values, 0.1),
            'p50' => self::percentile($values, 0.5),
            'p90' => self::percentile($values, 0.9),
        ];
    }

    /**
     * @param  array<int, float|int>  $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $percentile = max(0.0, min(1.0, $percentile));
        $count = count($values);

        if ($count === 1) {
            return (float) $values[0];
        }

        $index = ($count - 1) * $percentile;
        $lowerIndex = (int) floor($index);
        $upperIndex = (int) ceil($index);
        $lowerValue = (float) $values[$lowerIndex];
        $upperValue = (float) $values[$upperIndex];

        if ($lowerIndex === $upperIndex) {
            return $lowerValue;
        }

        $weight = $index - $lowerIndex;

        return $lowerValue + (($upperValue - $lowerValue) * $weight);
    }
}
