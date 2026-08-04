<?php

namespace App\Domain\Milcom;

final class CounterTeamSelector
{
    /**
     * @param  list<PairAssessment>  $assessments
     * @return array{recommended: array{nation_ids: list<int>, score: float, partial: bool}|null, alternatives: list<array{nation_ids: list<int>, score: float, partial: bool}>}
     */
    public function select(array $assessments): array
    {
        usort($assessments, static function (PairAssessment $left, PairAssessment $right): int {
            return [$right->score, $left->friendlyNationId] <=> [$left->score, $right->friendlyNationId];
        });

        $pool = array_slice(
            $assessments,
            0,
            (int) config('milcom.doctrine.counter_combination_pool', 20)
        );

        if ($pool === []) {
            return ['recommended' => null, 'alternatives' => []];
        }

        $teamSize = min(3, count($pool));
        $teams = [];

        foreach ($this->combinations($pool, $teamSize) as $team) {
            $pairScores = array_map(static fn (PairAssessment $pair): float => $pair->score, $team);
            $coverage = (
                max(array_column(array_map(static fn (PairAssessment $pair): array => $pair->factors, $team), 'air'))
                + max(array_column(array_map(static fn (PairAssessment $pair): array => $pair->factors, $team), 'ground'))
                + max(array_column(array_map(static fn (PairAssessment $pair): array => $pair->factors, $team), 'naval'))
            ) / 3;

            $nationIds = array_map(static fn (PairAssessment $pair): int => $pair->friendlyNationId, $team);
            sort($nationIds);

            $teams[] = [
                'nation_ids' => $nationIds,
                'score' => round(
                    (min($pairScores) * 0.50)
                        + ((array_sum($pairScores) / count($pairScores)) * 0.30)
                        + ($coverage * 0.20),
                    2
                ),
                'partial' => $teamSize < 3,
            ];
        }

        usort($teams, static function (array $left, array $right): int {
            return [$right['score'], $left['nation_ids']] <=> [$left['score'], $right['nation_ids']];
        });

        return [
            'recommended' => $teams[0],
            'alternatives' => array_slice(
                $teams,
                1,
                (int) config('milcom.doctrine.counter_alternative_count', 3)
            ),
        ];
    }

    /**
     * @param  list<PairAssessment>  $items
     * @return list<list<PairAssessment>>
     */
    private function combinations(array $items, int $size, int $offset = 0, array $prefix = []): array
    {
        if ($size === 0) {
            return [$prefix];
        }

        $result = [];

        for ($index = $offset; $index <= count($items) - $size; $index++) {
            $result = [
                ...$result,
                ...$this->combinations($items, $size - 1, $index + 1, [...$prefix, $items[$index]]),
            ];
        }

        return $result;
    }
}
