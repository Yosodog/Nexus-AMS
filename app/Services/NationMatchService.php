<?php

namespace App\Services;

use App\Models\Nation;
use Illuminate\Support\Collection;

class NationMatchService
{
    public function rankAgainstTarget(Nation $target, iterable $sourceNations): Collection
    {
        $minScore = $target->score * 0.75;
        $maxScore = $target->score * 2.5;

        return collect($sourceNations)
            ->filter(fn (Nation $n) => $n->score >= $minScore && $n->score <= $maxScore)
            ->map(function (Nation $n) use ($target) {
                $n->match_score = $this->score($n, $target);

                return $n;
            })
            ->sortByDesc('match_score')
            ->values();
    }

    public function score(Nation $source, Nation $target): int
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

    protected function militaryEffectiveness(Nation $source, Nation $target): float
    {
        $s = $source->military;
        $t = $target->military;

        $aircraftRatio = min($s->aircraft / max($t->aircraft, 1), 2.0);
        $tanksRatio = min($s->tanks / max($t->tanks, 1), 2.0);
        $soldiersRatio = min($s->soldiers / max($t->soldiers, 1), 2.0);
        $shipsRatio = min($s->ships / max($t->ships, 1), 2.0);

        return
            $aircraftRatio * 0.45 +
            $tanksRatio * 0.25 +
            $soldiersRatio * 0.20 +
            $shipsRatio * 0.10;
    }

    public function canAttack(Nation $source, Nation $target): bool
    {
        $min = $source->score * 0.75;
        $max = $source->score * 2.5;

        return $target->score >= $min && $target->score <= $max;
    }

    protected function militaryPower(Nation $nation): float
    {
        $military = $nation->military;

        return
            $military->aircraft * 10 +
            $military->tanks * 6 +
            $military->soldiers * 3 +
            $military->ships * 1;
    }
}
