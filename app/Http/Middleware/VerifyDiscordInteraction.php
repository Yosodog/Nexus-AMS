<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VerifyDiscordInteraction
{
    public const COMMAND_ATTRIBUTE = 'discord_interaction_command';

    public const GUILD_ATTRIBUTE = 'verified_discord_guild_id';

    public const INTERACTION_ATTRIBUTE = 'verified_discord_interaction_id';

    public const PAYLOAD_HEADER = 'X-Nexus-Discord-Relay-Payload';

    public const SERVICE_ATTRIBUTE = 'verified_discord_service_proof';

    public const SIGNATURE_HEADER = 'X-Nexus-Discord-Relay-Signature';

    public const TIMESTAMP_HEADER = 'X-Nexus-Discord-Relay-Timestamp';

    public const USER_ATTRIBUTE = 'verified_discord_user_id';

    public function __construct(private readonly Repository $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $publicKeyHex = trim((string) config('services.discord.relay_public_key'));

        if (! function_exists('sodium_crypto_sign_verify_detached') || ! $this->isHexKey($publicKeyHex)) {
            return $this->error(
                'discord_relay_verification_unavailable',
                'Discord relay verification is not configured.',
                503,
            );
        }

        $signatureHex = trim((string) $request->header(self::SIGNATURE_HEADER));
        $timestamp = trim((string) $request->header(self::TIMESTAMP_HEADER));
        $encodedPayload = trim((string) $request->header(self::PAYLOAD_HEADER));
        $payload = $this->decodePayload($encodedPayload);

        if (! $this->isHexSignature($signatureHex) || ! ctype_digit($timestamp) || $payload === null) {
            return $this->error('invalid_discord_relay_proof', 'A valid Discord relay proof is required.', 401);
        }

        $maxAge = max(30, (int) config('services.discord.interaction_max_age_seconds', 300));
        if (abs(now()->timestamp - (int) $timestamp) > $maxAge) {
            return $this->error('stale_discord_relay_proof', 'The Discord relay proof has expired.', 401);
        }

        $signature = hex2bin($signatureHex);
        $publicKey = hex2bin($publicKeyHex);

        if ($signature === false || $publicKey === false || ! sodium_crypto_sign_verify_detached(
            $signature,
            $timestamp.$payload,
            $publicKey,
        )) {
            return $this->error('invalid_discord_relay_proof', 'Discord relay verification failed.', 401);
        }

        try {
            $interaction = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay payload is invalid.', 401);
        }

        if (! is_array($interaction)) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay payload is invalid.', 401);
        }

        if (($interaction['relay_version'] ?? null) !== 1) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay proof version is unsupported.', 401);
        }

        if (($interaction['proof_type'] ?? null) === 'service') {
            return $this->handleServiceProof($request, $next, $interaction, (int) $timestamp, $maxAge);
        }

        if (($interaction['proof_type'] ?? null) !== 'interaction') {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay proof type is invalid.', 401);
        }

        $guildId = trim((string) ($interaction['guild_id'] ?? ''));
        $interactionId = trim((string) ($interaction['id'] ?? ''));
        $userId = trim((string) data_get($interaction, 'member.user.id', data_get($interaction, 'user.id', '')));

        if (! $this->isSnowflake($guildId) || ! $this->isSnowflake($interactionId) || ! $this->isSnowflake($userId)) {
            return $this->error('invalid_discord_relay_proof', 'The Discord interaction identity is invalid.', 401);
        }

        $request->attributes->set(self::GUILD_ATTRIBUTE, $guildId);
        $request->attributes->set(self::INTERACTION_ATTRIBUTE, $interactionId);
        $request->attributes->set(self::USER_ATTRIBUTE, $userId);
        $request->attributes->set(self::COMMAND_ATTRIBUTE, $this->commandPath($interaction));

        $request->headers->set(ResolveDiscordActor::GUILD_HEADER, $guildId);
        $request->headers->set(ResolveDiscordActor::USER_HEADER, $userId);
        $request->headers->set('X-Discord-Interaction-ID', $interactionId);

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $proof
     */
    private function handleServiceProof(
        Request $request,
        Closure $next,
        array $proof,
        int $timestamp,
        int $maxAge,
    ): Response {
        $guildId = trim((string) ($proof['guild_id'] ?? ''));
        $configuredGuildId = trim((string) config('services.discord.guild_id'));
        $nonce = trim((string) ($proof['nonce'] ?? ''));
        $action = trim((string) ($proof['action'] ?? ''));

        if (! $this->isSnowflake($guildId)
            || $configuredGuildId === ''
            || ! hash_equals($configuredGuildId, $guildId)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $nonce) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $action) !== 1) {
            return $this->error('invalid_discord_relay_proof', 'The Discord service relay proof is invalid.', 401);
        }

        $fingerprint = hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getRequestUri(),
            hash('sha256', $request->getContent()),
        ]));
        $cacheKey = 'discord:service-proof:'.hash('sha256', $guildId."\0".$nonce);
        $remainingLifetime = max(1, ($timestamp + $maxAge) - now()->timestamp + 1);

        if (! $this->cache->add($cacheKey, $fingerprint, $remainingLifetime)) {
            $claimedFingerprint = $this->cache->get($cacheKey);

            if (! is_string($claimedFingerprint) || ! hash_equals($claimedFingerprint, $fingerprint)) {
                return $this->error(
                    'replayed_discord_service_proof',
                    'The Discord service relay proof was already used for a different request.',
                    409,
                );
            }
        }

        $request->attributes->set(self::GUILD_ATTRIBUTE, $guildId);
        $request->attributes->set(self::COMMAND_ATTRIBUTE, $action);
        $request->attributes->set(self::SERVICE_ATTRIBUTE, true);

        return $next($request);
    }

    /** @param array<string, mixed> $interaction */
    private function commandPath(array $interaction): string
    {
        $data = is_array($interaction['data'] ?? null) ? $interaction['data'] : [];
        $customId = trim((string) ($data['custom_id'] ?? ''));

        if ($customId !== '') {
            return $customId;
        }

        $parts = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $parts[] = $name;
        }

        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        while (isset($options[0]) && is_array($options[0]) && in_array((int) ($options[0]['type'] ?? 0), [1, 2], true)) {
            $optionName = trim((string) ($options[0]['name'] ?? ''));
            if ($optionName === '') {
                break;
            }

            $parts[] = $optionName;
            $options = is_array($options[0]['options'] ?? null) ? $options[0]['options'] : [];
        }

        return implode('.', $parts);
    }

    private function decodePayload(string $encodedPayload): ?string
    {
        if ($encodedPayload === '' || strlen($encodedPayload) > 32_768) {
            return null;
        }

        $base64 = strtr($encodedPayload, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $payload = base64_decode($base64, true);

        return is_string($payload) && $payload !== '' && strlen($payload) <= 24_576 ? $payload : null;
    }

    private function isHexKey(string $value): bool
    {
        return strlen($value) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2 && ctype_xdigit($value);
    }

    private function isHexSignature(string $value): bool
    {
        return strlen($value) === SODIUM_CRYPTO_SIGN_BYTES * 2 && ctype_xdigit($value);
    }

    private function isSnowflake(string $value): bool
    {
        return preg_match('/^\d{1,20}$/', $value) === 1;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
            'meta' => ['contract_version' => 1],
        ], $status);
    }
}
