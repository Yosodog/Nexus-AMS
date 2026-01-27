<?php

namespace App\DataTransferObjects\WarSim;

final class WarSimRequestData
{
    public function __construct(
        public int $iterations,
        public ?int $seed,
        public WarSimNationData $nationAttacker,
        public WarSimNationData $nationDefender,
        public WarSimContextData $context,
        public WarSimActionData $action,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            iterations: (int) ($payload['iterations'] ?? 5000),
            seed: isset($payload['seed']) ? (int) $payload['seed'] : null,
            nationAttacker: WarSimNationData::fromArray($payload['nation_attacker'] ?? []),
            nationDefender: WarSimNationData::fromArray($payload['nation_defender'] ?? []),
            context: WarSimContextData::fromArray($payload['context'] ?? []),
            action: WarSimActionData::fromArray($payload['action'] ?? []),
        );
    }
}
