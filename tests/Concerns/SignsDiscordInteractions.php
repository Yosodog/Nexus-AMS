<?php

namespace Tests\Concerns;

trait SignsDiscordInteractions
{
    private string $discordInteractionSecretKey;

    protected function configureDiscordInteractionSigning(): void
    {
        $seed = hash('sha256', static::class, true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $this->discordInteractionSecretKey = sodium_crypto_sign_secretkey($keypair);

        config([
            'services.discord.relay_public_key' => bin2hex(sodium_crypto_sign_publickey($keypair)),
            'services.discord.interaction_max_age_seconds' => 300,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function signedDiscordInteractionHeaders(
        string $botToken,
        string $guildId,
        string $discordUserId,
        string $interactionId,
        string $command = 'ping',
        ?int $timestamp = null,
    ): array {
        $timestamp ??= now()->timestamp;
        $commandParts = explode('.', $command);
        $commandName = array_shift($commandParts) ?: 'ping';
        $options = [];

        foreach (array_reverse($commandParts) as $part) {
            $options = [[
                'type' => 1,
                'name' => $part,
                ...($options === [] ? [] : ['options' => $options]),
            ]];
        }

        $payload = json_encode([
            'relay_version' => 1,
            'proof_type' => 'interaction',
            'id' => $interactionId,
            'application_id' => '123456789012345679',
            'type' => 2,
            'guild_id' => $guildId,
            'member' => ['user' => ['id' => $discordUserId]],
            'data' => [
                'id' => '123456789012345680',
                'name' => $commandName,
                'type' => 1,
                ...($options === [] ? [] : ['options' => $options]),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = sodium_crypto_sign_detached((string) $timestamp.$payload, $this->discordInteractionSecretKey);

        return [
            'Authorization' => 'Bearer '.$botToken,
            'Accept' => 'application/json',
            'X-Discord-Guild-ID' => $guildId,
            'X-Discord-User-ID' => $discordUserId,
            'X-Discord-Interaction-ID' => $interactionId,
            'X-Nexus-Discord-Relay-Signature' => bin2hex($signature),
            'X-Nexus-Discord-Relay-Timestamp' => (string) $timestamp,
            'X-Nexus-Discord-Relay-Payload' => rtrim(strtr(base64_encode($payload), '+/', '-_'), '='),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function signedDiscordServiceHeaders(
        string $botToken,
        string $guildId,
        string $action,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= now()->timestamp;
        $payload = json_encode([
            'relay_version' => 1,
            'proof_type' => 'service',
            'nonce' => '11111111-2222-4333-8444-555555555555',
            'guild_id' => $guildId,
            'action' => $action,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached((string) $timestamp.$payload, $this->discordInteractionSecretKey);

        return [
            'Authorization' => 'Bearer '.$botToken,
            'Accept' => 'application/json',
            'X-Nexus-Discord-Relay-Signature' => bin2hex($signature),
            'X-Nexus-Discord-Relay-Timestamp' => (string) $timestamp,
            'X-Nexus-Discord-Relay-Payload' => rtrim(strtr(base64_encode($payload), '+/', '-_'), '='),
        ];
    }
}
