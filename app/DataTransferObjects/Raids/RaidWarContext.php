<?php

namespace App\DataTransferObjects\Raids;

/**
 * War circumstances around one attacker/target pair.
 */
final readonly class RaidWarContext
{
    public function __construct(
        public int $otherAttackers,
        public float $counterRate,
        public ?int $excludedWarId = null,
    ) {}

    /**
     * @return array{other_attackers: int, counter_rate: float, excluded_war_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'other_attackers' => $this->otherAttackers,
            'counter_rate' => $this->counterRate,
            'excluded_war_id' => $this->excludedWarId,
        ];
    }
}
