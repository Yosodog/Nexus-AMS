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
     * @param  array<int, float|int>  $sortedValues
     */
    private static function percentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);

        if ($count === 1) {
            return (float) $sortedValues[0];
        }

        $index = ($count - 1) * $percentile;
        $lowerIndex = (int) floor($index);
        $upperIndex = (int) ceil($index);
        $lowerValue = (float) $sortedValues[$lowerIndex];
        $upperValue = (float) $sortedValues[$upperIndex];

        if ($lowerIndex === $upperIndex) {
            return $lowerValue;
        }

        $weight = $index - $lowerIndex;

        return $lowerValue + (($upperValue - $lowerValue) * $weight);
    }
}
