<?php

namespace App\Support\Raids;

use Throwable;

/**
 * Reshapes a stored raid prediction row onto the valuation columns.
 *
 * The input is a raw `raid_predictions` row (JSON columns may be strings or arrays).
 * The output is the column payload to write back, with arrays JSON-encoded.
 */
final class RaidPredictionRowMapper
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function map(array $row): array
    {
        $expectedNet = self::number($row['expected_net'] ?? null);
        $conservativeNet = self::number($row['conservative_net'] ?? null);
        $scenarios = self::decode($row['scenarios'] ?? null) ?? [];
        $simulation = self::decode($row['simulation_payload'] ?? null) ?? [];
        $provenance = self::decode($row['provenance'] ?? null) ?? [];
        $stockpile = self::decode($row['stockpile_snapshot'] ?? null);
        $target = self::decode($row['target_snapshot'] ?? null);
        $context = self::decode($row['context_snapshot'] ?? null) ?? [];

        $highCandidates = collect($scenarios)
            ->map(fn (mixed $scenario): ?float => is_array($scenario) ? self::number($scenario['expected_net'] ?? null) : null)
            ->push($expectedNet)
            ->filter(fn (?float $value): bool => $value !== null);

        if ($stockpile !== null) {
            $target = array_replace($target ?? [], ['stockpile' => $stockpile]);
        }

        $context['plan'] = collect(data_get($simulation, 'approach.actions', []))
            ->map(fn (mixed $action): ?string => is_array($action) && is_string($action['type'] ?? null)
                ? strtolower($action['type'])
                : null)
            ->filter()
            ->values()
            ->all();

        $payload = [
            'expected_net_low' => $conservativeNet ?? $expectedNet,
            'expected_net_high' => $highCandidates->isEmpty() ? null : $highCandidates->max(),
            'win_probability' => self::number($simulation['win_probability'] ?? null),
            'confidence' => self::confidence($provenance, $stockpile ?? []),
            'target_snapshot' => $target === null ? null : json_encode($target, JSON_THROW_ON_ERROR),
            'context_snapshot' => json_encode($context, JSON_THROW_ON_ERROR),
        ];

        if (($row['evaluation_status'] ?? null) === 'failed' && ($row['capture_status'] ?? null) === 'ready') {
            $payload['capture_status'] = 'incomplete';
            $payload['capture_reason'] = 'Prediction evaluation failed.';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $stockpile
     */
    private static function confidence(array $provenance, array $stockpile): ?string
    {
        foreach ([$provenance['confidence'] ?? null, $stockpile['confidence'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && ! is_bool($value) ? (float) $value : null;
    }
}
