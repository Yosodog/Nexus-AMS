<?php

namespace App\Services\Raids;

/**
 * Closed-form ground battle odds matching the ground attack simulator's rolls.
 *
 * Each side rolls U(0.4·s, s) + U(0.4·t, t) for its soldier and tank strength. The
 * difference of the two sums is approximated as normal to get the roll win chance.
 */
final class GroundBattleOdds
{
    /**
     * Probability the attacker wins a single roll.
     */
    public function rollWinProbability(
        float $attSoldierValue,
        float $attTankValue,
        float $defSoldierValue,
        float $defTankValue,
    ): float {
        $attackerMean = 0.7 * ($attSoldierValue + $attTankValue);
        $defenderMean = 0.7 * ($defSoldierValue + $defTankValue);
        $variance = $this->rollVariance($attSoldierValue, $attTankValue) + $this->rollVariance($defSoldierValue, $defTankValue);

        if (0.4 * ($attSoldierValue + $attTankValue) > $defSoldierValue + $defTankValue) {
            return 1.0;
        }

        if ($attSoldierValue + $attTankValue <= 0.4 * ($defSoldierValue + $defTankValue)) {
            return 0.0;
        }

        if ($variance <= 0.0) {
            return $attackerMean > $defenderMean ? 1.0 : 0.0;
        }

        return self::normalCdf(($attackerMean - $defenderMean) / sqrt($variance));
    }

    /**
     * Distribution of rolls won out of three.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function outcomeDistribution(float $p): array
    {
        $p = max(0.0, min(1.0, $p));
        $q = 1.0 - $p;

        return [
            $q ** 3,
            3 * $p * $q ** 2,
            3 * $p ** 2 * $q,
            $p ** 3,
        ];
    }

    public static function normalCdf(float $x): float
    {
        return 0.5 * (1.0 + self::erf($x / M_SQRT2));
    }

    /**
     * Abramowitz–Stegun 7.1.26 approximation of the error function.
     */
    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $polynomial = ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t;

        return $sign * (1.0 - $polynomial * exp(-$x * $x));
    }

    private function rollVariance(float $soldierValue, float $tankValue): float
    {
        return ((0.6 * $soldierValue) ** 2 + (0.6 * $tankValue) ** 2) / 12;
    }
}
