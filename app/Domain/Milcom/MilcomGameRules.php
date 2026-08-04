<?php

namespace App\Domain\Milcom;

use App\Domain\Milcom\Enums\OperationType;
use DateTimeImmutable;

final class MilcomGameRules
{
    public function baseOffensiveCapacity(ReadinessProfile $nation): int
    {
        $capacity = (int) config('milcom.game_rules.base_offensive_slots', 5);

        foreach ((array) config('milcom.game_rules.offensive_slot_projects', []) as $project => $modifier) {
            if ($nation->ownsProject((string) $project)) {
                $capacity += (int) $modifier;
            }
        }

        return $capacity;
    }

    public function availableOffensiveSlots(ReadinessProfile $nation): int
    {
        return max(
            0,
            $this->baseOffensiveCapacity($nation)
                - $nation->activeOffensiveWars
                - $nation->reservedOffensiveSlots
        );
    }

    /**
     * @return array{base: int, project_modifiers: int, active_offensive_wars: int, reservations: int, available: int}
     */
    public function offensiveSlotMath(ReadinessProfile $nation): array
    {
        $base = (int) config('milcom.game_rules.base_offensive_slots', 5);
        $capacity = $this->baseOffensiveCapacity($nation);

        return [
            'base' => $base,
            'project_modifiers' => $capacity - $base,
            'active_offensive_wars' => $nation->activeOffensiveWars,
            'reservations' => $nation->reservedOffensiveSlots,
            'available' => max(0, $capacity - $nation->activeOffensiveWars - $nation->reservedOffensiveSlots),
        ];
    }

    public function isInDeclarationRange(ReadinessProfile $attacker, ReadinessProfile $target): bool
    {
        $minimum = (float) config('milcom.game_rules.declaration_score_minimum_multiplier', 0.75);
        $maximum = (float) config('milcom.game_rules.declaration_score_maximum_multiplier', 2.50);

        return $target->score >= ($attacker->score * $minimum)
            && $target->score <= ($attacker->score * $maximum);
    }

    /**
     * @return array{minimum: float, maximum: float}
     */
    public function declarationRange(ReadinessProfile $attacker): array
    {
        return [
            'minimum' => round(
                $attacker->score * (float) config('milcom.game_rules.declaration_score_minimum_multiplier', 0.75),
                2
            ),
            'maximum' => round(
                $attacker->score * (float) config('milcom.game_rules.declaration_score_maximum_multiplier', 2.50),
                2
            ),
        ];
    }

    public function snapshotMaximumAgeMinutes(OperationType $operationType): int
    {
        return $operationType === OperationType::Counter
            ? (int) config('milcom.game_rules.counter_snapshot_max_age_minutes', 15)
            : (int) config('milcom.game_rules.plan_snapshot_max_age_minutes', 60);
    }

    public function isSnapshotStale(
        ReadinessProfile $profile,
        OperationType $operationType,
        ?DateTimeImmutable $at = null,
    ): bool {
        $at ??= new DateTimeImmutable;

        return ($at->getTimestamp() - $profile->fetchedAt->getTimestamp())
            > ($this->snapshotMaximumAgeMinutes($operationType) * 60);
    }
}
