<?php

namespace App\Services\Discord;

use App\Enums\DiscordConnectionMode;
use App\Models\DiscordConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DiscordConnectionResolver
{
    public function hasActiveV2Connection(): bool
    {
        return Schema::hasTable('discord_connections')
            && DiscordConnection::query()->active()->where('protocol_version', '>=', 2)->exists();
    }

    public function resolveForVerification(string $connectionId): DiscordConnectionContext
    {
        $hasPersistedConnections = Schema::hasTable('discord_connections')
            && DiscordConnection::query()->active()->exists();
        $matches = $hasPersistedConnections
            ? DiscordConnection::query()->active()->whereKey($connectionId)->limit(2)->get()
            : collect();

        if ($matches->count() === 1) {
            return $this->fromModel($matches->first());
        }

        $configured = $hasPersistedConnections ? null : $this->configuredContext();
        if ($configured !== null && hash_equals($configured->connectionId, $connectionId)) {
            return $configured;
        }

        throw new DiscordConnectionResolutionException(
            'discord_connection_not_active',
            'No active Discord connection matches this relay proof.',
            403,
        );
    }

    public function resolveForQueueProducer(?string $connectionId = null): DiscordConnectionContext
    {
        $connectionId = strtolower(trim((string) $connectionId));
        if ($connectionId !== '') {
            return $this->resolveForVerification($connectionId);
        }

        $hasPersistedConnections = Schema::hasTable('discord_connections')
            && DiscordConnection::query()->active()->exists();
        $matches = $hasPersistedConnections
            ? DiscordConnection::query()
                ->active()
                ->where('protocol_version', '>=', 2)
                ->limit(2)
                ->get()
            : collect();

        if ($matches->count() > 1) {
            throw new DiscordConnectionResolutionException(
                'ambiguous_discord_connection',
                'Multiple active Discord connections are available; an explicit connection is required.',
                409,
            );
        }

        if ($matches->count() === 1) {
            return $this->fromModel($matches->first());
        }

        $configured = $hasPersistedConnections ? null : $this->configuredContext();
        if ($configured !== null && $configured->protocolVersion >= 2) {
            return $configured;
        }

        throw new DiscordConnectionResolutionException(
            'discord_connection_not_active',
            'No active Discord connection is available for queue delivery.',
            503,
        );
    }

    public function resolveV2(
        string $connectionId,
        string $applicationId,
        string $guildId,
        int $generation,
    ): DiscordConnectionContext {
        $hasPersistedConnections = Schema::hasTable('discord_connections')
            && DiscordConnection::query()->active()->exists();
        $matches = $hasPersistedConnections
            ? DiscordConnection::query()
                ->active()
                ->whereKey($connectionId)
                ->limit(2)
                ->get()
            : collect();

        if ($matches->count() === 1) {
            $context = $this->fromModel($matches->first());

            if (! hash_equals($context->applicationId, $applicationId)
                || ! hash_equals($context->guildId, $guildId)) {
                throw new DiscordConnectionResolutionException(
                    'discord_connection_binding_mismatch',
                    'The Discord relay proof does not match the accepted application and guild.',
                    403,
                );
            }

            if ($context->generation !== $generation) {
                throw new DiscordConnectionResolutionException(
                    'stale_discord_connection_generation',
                    'The Discord connection generation is stale.',
                    409,
                );
            }

            return $context;
        }

        $configured = $hasPersistedConnections ? null : $this->configuredContext();
        if ($configured !== null
            && $configured->protocolVersion === 2
            && hash_equals($configured->connectionId, $connectionId)
            && hash_equals($configured->applicationId, $applicationId)
            && hash_equals($configured->guildId, $guildId)) {
            if ($configured->generation !== $generation) {
                throw new DiscordConnectionResolutionException(
                    'stale_discord_connection_generation',
                    'The Discord connection generation is stale.',
                    409,
                );
            }

            return $configured;
        }

        throw new DiscordConnectionResolutionException(
            'discord_connection_not_active',
            'No active Discord connection matches this relay proof.',
            403,
        );
    }

    public function resolveLegacy(string $guildId): DiscordConnectionContext
    {
        /** @var Collection<int, DiscordConnection> $matches */
        $hasPersistedConnections = Schema::hasTable('discord_connections')
            && DiscordConnection::query()->active()->exists();
        $matches = $hasPersistedConnections
            ? DiscordConnection::query()
                ->active()
                ->where('guild_id', $guildId)
                ->where('v1_reader_enabled', true)
                ->limit(2)
                ->get()
            : collect();

        if ($matches->count() > 1) {
            throw new DiscordConnectionResolutionException(
                'ambiguous_discord_connection',
                'Multiple active Discord connections match this guild.',
                409,
            );
        }

        if ($matches->count() === 1) {
            return $this->fromModel($matches->first());
        }

        $configured = $hasPersistedConnections ? null : $this->configuredContext();
        if ($configured !== null
            && $configured->v1ReaderEnabled
            && hash_equals($configured->guildId, $guildId)) {
            return $configured;
        }

        if ($configured !== null && ! hash_equals($configured->guildId, $guildId)) {
            throw new DiscordConnectionResolutionException(
                'invalid_discord_guild',
                'The Discord guild is not authorized.',
                403,
            );
        }

        throw new DiscordConnectionResolutionException(
            'discord_v1_reader_disabled',
            'The legacy Discord relay reader is not enabled for this guild.',
            401,
        );
    }

    public function configuredContext(): ?DiscordConnectionContext
    {
        $connectionId = strtolower(trim((string) config('services.discord.connection_id')));
        $applicationId = trim((string) config('services.discord.application_id'));
        $guildId = trim((string) config('services.discord.guild_id'));
        $protocolVersion = (int) config('services.discord.relay_protocol_version', 1);
        $legacyPublicKey = trim((string) config('services.discord.relay_public_key'));
        $currentPublicKey = trim((string) config('services.discord.relay_current_public_key'));

        if ($guildId === '') {
            return null;
        }

        if ($connectionId === '') {
            $connectionId = $this->legacyConnectionId($applicationId, $guildId);
        }
        if ($applicationId === '') {
            $applicationId = $guildId;
        }
        if ($currentPublicKey === '') {
            $currentPublicKey = $legacyPublicKey;
        }

        return new DiscordConnectionContext(
            connectionId: $connectionId,
            mode: DiscordConnectionMode::tryFrom((string) config('services.discord.connection_mode'))
                ?? DiscordConnectionMode::Dedicated,
            applicationId: $applicationId,
            guildId: $guildId,
            generation: max(1, (int) config('services.discord.connection_generation', 1)),
            protocolVersion: in_array($protocolVersion, [1, 2], true) ? $protocolVersion : 1,
            relayCurrentKeyId: trim((string) config('services.discord.relay_current_key_id')) ?: 'legacy-v1',
            relayCurrentPublicKey: $currentPublicKey,
            relayNextKeyId: $this->nullable(config('services.discord.relay_next_key_id')),
            relayNextPublicKey: $this->nullable(config('services.discord.relay_next_public_key')),
            relayNextActivatesAt: $this->nullable(config('services.discord.relay_next_activates_at')),
            nexusCurrentKeyId: $this->nullable(config('services.discord.nexus_current_key_id')),
            nexusCurrentPublicKey: $this->nullable(config('services.discord.nexus_current_public_key')),
            nexusNextKeyId: $this->nullable(config('services.discord.nexus_next_key_id')),
            nexusNextPublicKey: $this->nullable(config('services.discord.nexus_next_public_key')),
            nexusNextActivatesAt: $this->nullable(config('services.discord.nexus_next_activates_at')),
            capabilityVersion: max(1, (int) config('services.discord.capability_version', 1)),
            capabilities: (array) config('services.discord.capabilities', []),
            v1ReaderEnabled: (bool) config('services.discord.v1_reader_enabled', true),
            persisted: false,
        );
    }

    private function fromModel(DiscordConnection $connection): DiscordConnectionContext
    {
        return new DiscordConnectionContext(
            connectionId: $connection->id,
            mode: $connection->mode,
            applicationId: $connection->application_id,
            guildId: $connection->guild_id,
            generation: $connection->generation,
            protocolVersion: $connection->protocol_version,
            relayCurrentKeyId: $connection->relay_current_key_id,
            relayCurrentPublicKey: $connection->relay_current_public_key,
            relayNextKeyId: $connection->relay_next_key_id,
            relayNextPublicKey: $connection->relay_next_public_key,
            relayNextActivatesAt: $connection->relay_next_activates_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            nexusCurrentKeyId: $connection->nexus_current_key_id,
            nexusCurrentPublicKey: $connection->nexus_current_public_key,
            nexusNextKeyId: $connection->nexus_next_key_id,
            nexusNextPublicKey: $connection->nexus_next_public_key,
            nexusNextActivatesAt: $connection->nexus_next_activates_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            capabilityVersion: $connection->capability_version,
            capabilities: $connection->capabilities ?? [],
            v1ReaderEnabled: $connection->v1_reader_enabled,
        );
    }

    private function legacyConnectionId(string $applicationId, string $guildId): string
    {
        $hex = hash('sha256', $applicationId."\0".$guildId."\0dedicated");

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
