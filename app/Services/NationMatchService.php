<?php

namespace App\Services;

use App\Models\Nations;
use Illuminate\Support\Collection;

class NationMatchService
{
    /**
     * @param Nations $source
     * @param Nations $target
     * @return int
     */
    public function score(Nations $source, Nations $target): int
    {
        if (
            $source->defensive_wars_count >= 3 ||
            $source->offensive_wars_count >= 6
        ) {
            return 0;
        }

        // Rebalanced military effectiveness: 0.0 - 1.0
        $militaryScore = min($this->militaryEffectiveness($source, $target) / 2.0, 1.0);

        // City advantage: reward having more cities than the target (max 100% boost)
        $cityAdvantage = $source->num_cities > $target->num_cities
            ? min(($source->num_cities - $target->num_cities) / max($target->num_cities, 1), 1)
            : 0;

        $score = ($militaryScore * 0.7 + $cityAdvantage * 0.3) * 100;

        if (strtolower($source->color) === 'beige') {
            $score *= 0.8; // penalize beige
        }

        return round($score);
    }

    /**
     * @param Nations $target
     * @param iterable $sourceNations
     * @return Collection
     */
    public function rankAgainstTarget(Nations $target, iterable $sourceNations): Collection
    {
        $minScore = $target->score * 0.75;
        $maxScore = $target->score * 2.5;

        return collect($sourceNations)
            ->filter(fn (Nations $n) => $n->score >= $minScore && $n->score <= $maxScore)
            ->map(function (Nations $n) use ($target) {
                $n->match_score = $this->score($n, $target);
                return $n;
            })
            ->sortByDesc('match_score')
            ->values();
    }

    /**
     * @param Nations $nation
     * @return float
     */
    protected function militaryPower(Nations $nation): float
    {
        $military = $nation->military;

        return (
            $military->aircraft * 10 +
            $military->tanks * 6 +
            $military->soldiers * 3 +
            $military->ships * 1
        );
    }

    protected function militaryEffectiveness(Nations $source, Nations $target): float
    {
        $s = $source->military;
        $t = $target->military;

        $aircraftRatio = min($s->aircraft / max($t->aircraft, 1), 2.0);
        $tanksRatio    = min($s->tanks / max($t->tanks, 1), 2.0);
        $soldiersRatio = min($s->soldiers / max($t->soldiers, 1), 2.0);
        $shipsRatio    = min($s->ships / max($t->ships, 1), 2.0);

        return (
            $aircraftRatio * 0.45 +
            $tanksRatio    * 0.25 +
            $soldiersRatio * 0.20 +
            $shipsRatio    * 0.10
        );
    }

    /**
     * @param Nations $source
     * @param Nations $target
     * @return bool
     */
    public function canAttack(Nations $source, Nations $target): bool
    {
        $min = $source->score * 0.75;
        $max = $source->score * 2.5;

        return $target->score >= $min && $target->score <= $max;
    }
}