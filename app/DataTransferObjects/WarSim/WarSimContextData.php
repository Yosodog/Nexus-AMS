<?php

namespace App\DataTransferObjects\WarSim;

final readonly class WarSimContextData
{
    public function __construct(
        public string $warType,
        public string $attackerPolicy,
        public string $defenderPolicy,
        public string $airSuperiorityOwner,
        public string $groundControlOwner,
        public string $blockadeOwner,
        public bool $blitzActiveAttacker,
        public bool $blitzActiveDefender,
        public bool $attackerPirateEconomy = false,
        public bool $attackerAdvancedPirateEconomy = false,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            warType: (string) ($payload['war_type'] ?? 'ORDINARY'),
            attackerPolicy: (string) ($payload['attacker_policy'] ?? 'NONE'),
            defenderPolicy: (string) ($payload['defender_policy'] ?? 'NONE'),
            airSuperiorityOwner: (string) ($payload['air_superiority_owner'] ?? 'none'),
            groundControlOwner: (string) ($payload['ground_control_owner'] ?? 'none'),
            blockadeOwner: (string) ($payload['blockade_owner'] ?? 'none'),
            blitzActiveAttacker: (bool) ($payload['blitz_active_attacker'] ?? false),
            blitzActiveDefender: (bool) ($payload['blitz_active_defender'] ?? false),
            attackerPirateEconomy: (bool) ($payload['attacker_pirate_economy'] ?? false),
            attackerAdvancedPirateEconomy: (bool) ($payload['attacker_advanced_pirate_economy'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'war_type' => $this->warType,
            'attacker_policy' => $this->attackerPolicy,
            'defender_policy' => $this->defenderPolicy,
            'air_superiority_owner' => $this->airSuperiorityOwner,
            'ground_control_owner' => $this->groundControlOwner,
            'blockade_owner' => $this->blockadeOwner,
            'blitz_active_attacker' => $this->blitzActiveAttacker,
            'blitz_active_defender' => $this->blitzActiveDefender,
        ];
        if ($this->attackerPirateEconomy) {
            $payload['attacker_pirate_economy'] = true;
        }
        if ($this->attackerAdvancedPirateEconomy) {
            $payload['attacker_advanced_pirate_economy'] = true;
        }

        return $payload;
    }
}
