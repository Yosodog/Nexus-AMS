<?php

namespace Tests\Unit\Raids;

use App\Services\Raids\GroundBattleOdds;
use PHPUnit\Framework\TestCase;

class GroundBattleOddsTest extends TestCase
{
    public function test_symmetric_strength_is_a_coin_flip(): void
    {
        $this->assertEqualsWithDelta(0.5, (new GroundBattleOdds)->rollWinProbability(10_000, 4_000, 10_000, 4_000), 1e-6);
    }

    public function test_an_undefended_target_always_loses_and_an_unarmed_attacker_never_wins(): void
    {
        $odds = new GroundBattleOdds;

        $this->assertSame(1.0, $odds->rollWinProbability(1_000, 0, 0, 0));
        $this->assertSame(0.0, $odds->rollWinProbability(0, 0, 1_000, 0));
        $this->assertSame(0.0, $odds->rollWinProbability(0, 0, 0, 0));
    }

    public function test_analytic_odds_match_simulated_rolls_across_strength_ratios(): void
    {
        mt_srand(20260920);
        $odds = new GroundBattleOdds;

        foreach ([0.3, 0.5, 0.8, 0.9, 1.0, 1.1, 1.25, 1.5, 2.0, 3.0] as $ratio) {
            $defSoldiers = 20_000.0;
            $defTanks = 8_000.0;
            $attSoldiers = $defSoldiers * $ratio;
            $attTanks = $defTanks * $ratio * 0.5;
            $wins = 0;
            $trials = 20_000;

            for ($trial = 0; $trial < $trials; $trial++) {
                $attacker = $this->uniform(0.4 * $attSoldiers, $attSoldiers) + $this->uniform(0.4 * $attTanks, $attTanks);
                $defender = $this->uniform(0.4 * $defSoldiers, $defSoldiers) + $this->uniform(0.4 * $defTanks, $defTanks);
                $wins += $attacker > $defender ? 1 : 0;
            }

            $this->assertEqualsWithDelta(
                $wins / $trials,
                $odds->rollWinProbability($attSoldiers, $attTanks, $defSoldiers, $defTanks),
                0.03,
                "Strength ratio {$ratio}",
            );
        }
    }

    public function test_outcome_distribution_is_binomial_and_sums_to_one(): void
    {
        $distribution = (new GroundBattleOdds)->outcomeDistribution(0.7);

        $this->assertEqualsWithDelta(1.0, array_sum($distribution), 1e-12);
        $this->assertEqualsWithDelta(0.027, $distribution[0], 1e-12);
        $this->assertEqualsWithDelta(0.189, $distribution[1], 1e-12);
        $this->assertEqualsWithDelta(0.441, $distribution[2], 1e-12);
        $this->assertEqualsWithDelta(0.343, $distribution[3], 1e-12);
    }

    private function uniform(float $min, float $max): float
    {
        return $min + ($max - $min) * (mt_rand() / mt_getrandmax());
    }
}
