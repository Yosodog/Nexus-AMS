<?php

namespace App\DataTransferObjects\WarSim;

final class WarSimActionData
{
    public function __construct(
        public string $type,
        public int $attackingSoldiers,
        public int $attackingTanks,
        public bool $armSoldiersWithMunitions,
        public int $attackingAircraft,
        public string $target,
        public int $attackingShips,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            type: (string) ($payload['type'] ?? 'ground'),
            attackingSoldiers: (int) ($payload['attacking_soldiers'] ?? 0),
            attackingTanks: (int) ($payload['attacking_tanks'] ?? 0),
            armSoldiersWithMunitions: (bool) ($payload['arm_soldiers_with_munitions'] ?? false),
            attackingAircraft: (int) ($payload['attacking_aircraft'] ?? 0),
            target: (string) ($payload['target'] ?? 'infra'),
            attackingShips: (int) ($payload['attacking_ships'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'attacking_soldiers' => $this->attackingSoldiers,
            'attacking_tanks' => $this->attackingTanks,
            'arm_soldiers_with_munitions' => $this->armSoldiersWithMunitions,
            'attacking_aircraft' => $this->attackingAircraft,
            'target' => $this->target,
            'attacking_ships' => $this->attackingShips,
        ];
    }
}
