<?php

namespace App\Services\WarSimulator\Support;

/**
 * Shared constants and bounded loot formulas used by raid planning.
 *
 * The evaluator samples the bank formula with the same seeded random stream
 * as the battle simulator. The expectation helper is provided for callers
 * that need a stable estimate without replacing the clipped distribution by
 * evaluating it at the mean random value.
 *
 * The action costs and war duration used alongside these formulas are
 * observable in the public war screen. The national ten-percent fraction and
 * score-scaled bank fraction remain explicitly modelled estimates until
 * outcome evidence verifies them for the current ruleset.
 *
 * @see https://forum.politicsandwar.com/index.php?%2Ftopic%2F36121-vikings-of-anarch-dowdoe%2F=
 * @see https://forum.politicsandwar.com/index.php?%2Ftopic%2F22747-close-war-mechanics%2F=&comment=363930&do=findComment
 */
final class RaidLootFormula
{
    /** Estimated default national victory-loot fraction. */
    public const DEFAULT_VICTORY_LOOT_FRACTION = 0.10;

    /** Observed upper bound for a bank-loot fraction. */
    public const BANK_LOOT_CAP = 0.33;

    /** Estimated score-ratio divisor for the bank-loot distribution. */
    public const BANK_LOOT_SCALE = 3.0;

    public static function victoryLootFraction(float $lootMultiplier = 1.0): float
    {
        return self::clamp(self::DEFAULT_VICTORY_LOOT_FRACTION * max(0.0, $lootMultiplier), 0.0, 1.0);
    }

    /**
     * Return one bank-loot fraction draw for a supplied U(0, 1) value.
     */
    public static function bankLootFractionForRoll(
        float $attackerScore,
        float $allianceScore,
        float $uniform,
        float $lootMultiplier = 1.0,
    ): ?float {
        if ($attackerScore < 0.0 || $allianceScore <= 0.0) {
            return null;
        }

        $uniform = self::clamp($uniform, 0.0, 1.0);
        $rawFraction = ($attackerScore / $allianceScore) * ($uniform / self::BANK_LOOT_SCALE);
        $baseFraction = min(self::BANK_LOOT_CAP, max(0.0, $rawFraction));

        return min(1.0, max(0.0, $baseFraction * max(0.0, $lootMultiplier)));
    }

    /**
     * Integrate the clipped uniform distribution analytically.
     */
    public static function expectedBankLootFraction(
        float $attackerScore,
        float $allianceScore,
        float $lootMultiplier = 1.0,
    ): ?float {
        if ($attackerScore < 0.0 || $allianceScore <= 0.0) {
            return null;
        }

        $maximumBeforeCap = ($attackerScore / $allianceScore) / self::BANK_LOOT_SCALE;
        if ($maximumBeforeCap <= 0.0) {
            return 0.0;
        }

        if ($maximumBeforeCap <= self::BANK_LOOT_CAP) {
            return min(1.0, ($maximumBeforeCap / 2.0) * max(0.0, $lootMultiplier));
        }

        $uncappedProbability = self::BANK_LOOT_CAP / $maximumBeforeCap;
        $uncappedExpectation = $maximumBeforeCap * ($uncappedProbability ** 2) / 2.0;
        $cappedExpectation = self::BANK_LOOT_CAP * (1.0 - $uncappedProbability);

        return max(0.0, min(1.0, ($uncappedExpectation + $cappedExpectation) * max(0.0, $lootMultiplier)));
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
