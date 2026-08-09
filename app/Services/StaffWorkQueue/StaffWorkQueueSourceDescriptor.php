<?php

namespace App\Services\StaffWorkQueue;

use App\Enums\OperationsSensitivity;
use InvalidArgumentException;

final readonly class StaffWorkQueueSourceDescriptor
{
    /**
     * @param  list<string>  $viewAbilities
     * @param  list<string>  $interestedTeams
     * @param  list<string>  $actionKeys
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $teamKey,
        public array $viewAbilities,
        public int $freshSeconds,
        public int $staleSeconds,
        public OperationsSensitivity $sensitivity,
        public array $interestedTeams = [],
        public array $actionKeys = [],
        public string $cacheScope = 'shared',
    ) {
        if ($this->viewAbilities === []) {
            throw new InvalidArgumentException("Operations source [{$this->type}] requires at least one view ability.");
        }

        if ($this->freshSeconds < 1 || $this->staleSeconds <= $this->freshSeconds) {
            throw new InvalidArgumentException("Operations source [{$this->type}] has an invalid freshness window.");
        }

        if (! in_array($this->cacheScope, ['shared', 'user'], true)) {
            throw new InvalidArgumentException("Operations source [{$this->type}] has an invalid cache scope.");
        }
    }

    public static function fromConfig(string $type, string $label, string $legacyAbility): self
    {
        $policy = (array) config("operations.sources.{$type}", []);
        $viewAbilities = array_values(array_filter(array_map(
            'strval',
            (array) ($policy['view_abilities'] ?? [$legacyAbility]),
        )));

        return new self(
            type: $type,
            label: $label,
            teamKey: (string) ($policy['team'] ?? 'systems'),
            viewAbilities: $viewAbilities,
            freshSeconds: max(1, (int) ($policy['fresh_seconds'] ?? 300)),
            staleSeconds: max(2, (int) ($policy['stale_seconds'] ?? 900)),
            sensitivity: OperationsSensitivity::tryFrom((string) ($policy['sensitivity'] ?? 'restricted'))
                ?? OperationsSensitivity::Restricted,
            interestedTeams: array_values(array_map('strval', (array) ($policy['interested_teams'] ?? []))),
            actionKeys: array_values(array_map('strval', (array) ($policy['actions'] ?? []))),
            cacheScope: (string) ($policy['cache_scope'] ?? 'shared'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'team_key' => $this->teamKey,
            'view_abilities' => $this->viewAbilities,
            'fresh_seconds' => $this->freshSeconds,
            'stale_seconds' => $this->staleSeconds,
            'sensitivity' => $this->sensitivity->value,
            'interested_teams' => $this->interestedTeams,
            'action_keys' => $this->actionKeys,
            'cache_scope' => $this->cacheScope,
        ];
    }
}
