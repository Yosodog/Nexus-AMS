<?php

namespace App\Services\Discord;

use App\Enums\DiscordConnectionMode;

final readonly class DiscordConnectionContext
{
    /**
     * @param  array<string, mixed>  $capabilities
     */
    public function __construct(
        public string $connectionId,
        public DiscordConnectionMode $mode,
        public string $applicationId,
        public string $guildId,
        public int $generation,
        public int $protocolVersion,
        public string $relayCurrentKeyId,
        public string $relayCurrentPublicKey,
        public ?string $relayNextKeyId = null,
        public ?string $relayNextPublicKey = null,
        public ?string $relayNextActivatesAt = null,
        public ?string $nexusCurrentKeyId = null,
        public ?string $nexusCurrentPublicKey = null,
        public ?string $nexusNextKeyId = null,
        public ?string $nexusNextPublicKey = null,
        public ?string $nexusNextActivatesAt = null,
        public int $capabilityVersion = 1,
        public array $capabilities = [],
        public bool $v1ReaderEnabled = false,
        public bool $persisted = true,
    ) {}

    public function dedupeScope(): string
    {
        return $this->connectionId.':'.$this->generation;
    }

    public function isDedicated(): bool
    {
        return $this->mode === DiscordConnectionMode::Dedicated;
    }

    public function supports(string $capability): bool
    {
        $values = $this->capabilities['capabilities']
            ?? $this->capabilities['keys']
            ?? $this->capabilities['features']
            ?? $this->capabilities;

        if (is_array($values) && array_is_list($values)) {
            return in_array($capability, $values, true);
        }

        return is_array($values) && ($values[$capability] ?? false) === true;
    }

    public function supportsQueueAction(string $action): bool
    {
        $actions = $this->capabilities['supported_queue_actions'] ?? [];

        return is_array($actions)
            && array_is_list($actions)
            && in_array($action, $actions, true);
    }

    /** @return list<string> */
    public function capabilityKeys(): array
    {
        $values = $this->capabilities['capabilities']
            ?? $this->capabilities['keys']
            ?? $this->capabilities['features']
            ?? $this->capabilities;
        $keys = is_array($values) && array_is_list($values)
            ? $values
            : array_keys(array_filter(
                is_array($values) ? $values : [],
                static fn (mixed $enabled): bool => $enabled === true || $enabled === 1,
            ));
        $keys = array_values(array_filter(
            $keys,
            static fn (mixed $key): bool => is_string($key)
                && preg_match('/^[a-z][a-z0-9._:-]{0,127}$/', $key) === 1,
        ));
        sort($keys, SORT_STRING);

        return array_values(array_unique($keys));
    }
}
