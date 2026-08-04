<?php

namespace App\Domain\Milcom;

use App\Domain\Milcom\Allocation\CandidateEdge;
use DateTimeImmutable;

final class FixedDoctrineScorer
{
    public const VERSION = 'fixed-v1';

    /** @var array{air: float, ground: float, naval: float, readiness: float, tactical_fit: float, activity: float}|null */
    private ?array $doctrineWeights = null;

    public function assess(
        ReadinessProfile $friendly,
        ReadinessProfile $target,
        ?DateTimeImmutable $at = null,
    ): PairAssessment {
        $at ??= new DateTimeImmutable;

        $factors = [
            'air' => $this->matchup($friendly->aircraft, $target->aircraft),
            'ground' => $this->groundMatchup($friendly, $target),
            'naval' => $this->matchup($friendly->ships, $target->ships),
            'readiness' => $this->readiness($friendly),
            'tactical_fit' => $this->tacticalFit($friendly, $target),
            'activity' => $this->activity($friendly, $at),
        ];

        $score = $this->weightedScore(
            $factors['air'],
            $factors['ground'],
            $factors['naval'],
            $factors['readiness'],
            $factors['tactical_fit'],
            $factors['activity'],
        );

        $confidence = $this->confidence($friendly, $target, $at);
        $warnings = [];

        if ($score < 45) {
            $warnings[] = ['code' => 'low_tactical_score', 'message' => 'This matchup is weaker than usual.'];
        }

        return new PairAssessment(
            friendlyNationId: $friendly->nationId,
            targetNationId: $target->nationId,
            score: round(max(0, min(100, $score)), 2),
            confidence: round($confidence, 2),
            factors: array_map(static fn (float $factor): float => round($factor, 2), $factors),
            warnings: $warnings,
            explanation: [
                'doctrine_version' => self::VERSION,
                'missiles_and_nukes' => [
                    'friendly' => ['missiles' => $friendly->missiles, 'nukes' => $friendly->nukes],
                    'target' => ['missiles' => $target->missiles, 'nukes' => $target->nukes],
                    'scored' => false,
                ],
                'overmatch_policy' => 'Strong matchups are kept. Team building decides where rare attackers help most.',
            ],
        );
    }

    public function allocationEdge(
        int $objectiveId,
        ReadinessProfile $friendly,
        ReadinessProfile $target,
        DateTimeImmutable $at,
    ): CandidateEdge {
        $score = $this->weightedScore(
            $this->matchup($friendly->aircraft, $target->aircraft),
            $this->groundMatchup($friendly, $target),
            $this->matchup($friendly->ships, $target->ships),
            $this->readiness($friendly),
            $this->tacticalFit($friendly, $target),
            $this->activity($friendly, $at),
        );

        return new CandidateEdge(
            objectiveId: $objectiveId,
            nationId: $friendly->nationId,
            score: round(max(0, min(100, $score)), 2),
            confidence: round($this->confidence($friendly, $target, $at), 2),
        );
    }

    private function weightedScore(
        float $air,
        float $ground,
        float $naval,
        float $readiness,
        float $tacticalFit,
        float $activity,
    ): float {
        $weights = $this->doctrineWeights ??= [
            'air' => (float) config('milcom.doctrine.weights.air_matchup', 0.40),
            'ground' => (float) config('milcom.doctrine.weights.ground_matchup', 0.20),
            'naval' => (float) config('milcom.doctrine.weights.naval_matchup', 0.10),
            'readiness' => (float) config('milcom.doctrine.weights.readiness', 0.15),
            'tactical_fit' => (float) config('milcom.doctrine.weights.tactical_fit', 0.10),
            'activity' => (float) config('milcom.doctrine.weights.activity', 0.05),
        ];

        return ($air * $weights['air'])
            + ($ground * $weights['ground'])
            + ($naval * $weights['naval'])
            + ($readiness * $weights['readiness'])
            + ($tacticalFit * $weights['tactical_fit'])
            + ($activity * $weights['activity']);
    }

    private function matchup(?int $friendly, ?int $target): float
    {
        if ($friendly === null || $target === null) {
            return 0;
        }

        $ratio = ($friendly + 1) / ($target + 1);

        return max(0, min(100, 50 + (50 * log($ratio, 2))));
    }

    private function groundMatchup(ReadinessProfile $friendly, ReadinessProfile $target): float
    {
        $soldiers = $this->matchup($friendly->soldiers, $target->soldiers);
        $tanks = $this->matchup($friendly->tanks, $target->tanks);

        return ($soldiers * 0.35) + ($tanks * 0.65);
    }

    private function readiness(ReadinessProfile $friendly): float
    {
        if (! $friendly->hasCompleteMilitaryData() || $friendly->cities < 1) {
            return 0;
        }

        $ratios = [
            min(1, (int) $friendly->soldiers / ($friendly->cities * 15000)),
            min(1, (int) $friendly->tanks / ($friendly->cities * 1250)),
            min(1, (int) $friendly->aircraft / ($friendly->cities * 75)),
            min(1, (int) $friendly->ships / ($friendly->cities * 15)),
        ];

        return (array_sum($ratios) / count($ratios)) * 100;
    }

    private function tacticalFit(ReadinessProfile $friendly, ReadinessProfile $target): float
    {
        $cityFit = $friendly->cities >= $target->cities
            ? 100
            : max(0, 100 - (($target->cities - $friendly->cities) * 18));

        $scoreFit = $friendly->score >= $target->score
            ? 100
            : max(0, ($friendly->score / max(1, $target->score)) * 100);

        return ($cityFit * 0.65) + ($scoreFit * 0.35);
    }

    private function activity(ReadinessProfile $friendly, DateTimeImmutable $at): float
    {
        if ($friendly->lastActiveAt === null) {
            return 0;
        }

        $hours = max(0, ($at->getTimestamp() - $friendly->lastActiveAt->getTimestamp()) / 3600);

        return max(0, 100 - ($hours * 2));
    }

    private function confidence(
        ReadinessProfile $friendly,
        ReadinessProfile $target,
        DateTimeImmutable $at,
    ): float {
        $complete = ($friendly->hasCompleteMilitaryData() ? 50 : 0)
            + ($target->hasCompleteMilitaryData() ? 30 : 0);
        $newestAgeMinutes = max(
            0,
            ($at->getTimestamp() - min(
                $friendly->fetchedAt->getTimestamp(),
                $target->fetchedAt->getTimestamp()
            )) / 60
        );
        $freshness = max(0, 20 - min(20, $newestAgeMinutes / 3));

        return min(100, $complete + $freshness);
    }
}
