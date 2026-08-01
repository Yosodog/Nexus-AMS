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
            'services.discord.application_public_key' => bin2hex(sodium_crypto_sign_publickey($keypair)),
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
            'X-Signature-Ed25519' => bin2hex($signature),
            'X-Signature-Timestamp' => (string) $timestamp,
            'X-Discord-Interaction-Payload' => rtrim(strtr(base64_encode($payload), '+/', '-_'), '='),
        ];
    }
}
