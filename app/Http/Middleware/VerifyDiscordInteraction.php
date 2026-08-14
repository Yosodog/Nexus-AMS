<?php

namespace App\Http\Middleware;

use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Discord\Relay\DiscordRelayProofException;
use App\Services\Discord\Relay\DiscordRelayProofVerifier;
use App\Services\Discord\Relay\StrictJson;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VerifyDiscordInteraction
{
    public const ACTION_ATTRIBUTE = 'discord_relay_action';

    public const APPLICATION_ATTRIBUTE = 'verified_discord_application_id';

    public const COMMAND_ATTRIBUTE = 'discord_interaction_command';

    public const CONNECTION_ATTRIBUTE = 'verified_discord_connection';

    public const GENERATION_ATTRIBUTE = 'verified_discord_connection_generation';

    public const GUILD_ATTRIBUTE = 'verified_discord_guild_id';

    public const IDEMPOTENCY_ATTRIBUTE = 'verified_discord_idempotency_key';

    public const INTERACTION_ATTRIBUTE = 'verified_discord_interaction_id';

    public const KEY_ID_ATTRIBUTE = 'verified_discord_relay_key_id';

    public const PAYLOAD_HEADER = 'X-Nexus-Discord-Relay-Payload';

    public const PROTOCOL_ATTRIBUTE = 'verified_discord_relay_protocol';

    public const SERVICE_ATTRIBUTE = 'verified_discord_service_proof';

    public const SIGNATURE_HEADER = 'X-Nexus-Discord-Relay-Signature';

    public const TIMESTAMP_HEADER = 'X-Nexus-Discord-Relay-Timestamp';

    public const USER_ATTRIBUTE = 'verified_discord_user_id';

    public function __construct(
        private readonly Repository $cache,
        private readonly DiscordRelayProofVerifier $v2Verifier,
        private readonly DiscordConnectionResolver $connections,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        ?string $expectedServiceAction = null,
    ): Response {
        $encodedPayload = trim((string) $request->header(self::PAYLOAD_HEADER));
        $payload = $this->decodePayload($encodedPayload);
        if ($payload === null) {
            return $this->error('invalid_discord_relay_proof', 'A valid Discord relay proof is required.', 401);
        }

        try {
            $document = StrictJson::decode($payload);
        } catch (JsonException) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay payload is invalid.', 401);
        }
        if (! is_array($document)) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay payload is invalid.', 401);
        }

        if (($document['contract'] ?? null) === 'relay-proof' || ($document['contract_version'] ?? null) === 2) {
            return $this->handleV2($request, $next, $document, $expectedServiceAction);
        }

        return $this->handleV1($request, $next, $document, $payload, $expectedServiceAction);
    }

    /** @param array<string, mixed> $document */
    private function handleV2(
        Request $request,
        Closure $next,
        array $document,
        ?string $expectedServiceAction,
    ): Response {
        try {
            $verified = $this->v2Verifier->verify(
                $request,
                $document,
                trim((string) $request->header(self::SIGNATURE_HEADER)),
                trim((string) $request->header(self::TIMESTAMP_HEADER)),
            );
        } catch (DiscordRelayProofException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->status, 2);
        }

        if ($expectedServiceAction !== null
            && ($verified->type !== 'service' || ! hash_equals($expectedServiceAction, $verified->action))) {
            return $this->error(
                'discord_relay_action_mismatch',
                'The signed Discord service action does not match this endpoint.',
                403,
                2,
            );
        }

        if ($verified->type === 'service') {
            $fingerprint = hash('sha256', implode("\0", [
                $verified->connection->connectionId,
                (string) $verified->connection->generation,
                $verified->keyId,
                (string) ($document['signature']['value'] ?? ''),
            ]));
            $cacheKey = 'discord:v2-service-proof:'.hash('sha256', implode("\0", [
                $verified->connection->connectionId,
                (string) $verified->connection->generation,
                (string) $verified->nonce,
            ]));
            $expiresAt = strtotime($verified->expiresAt) ?: now()->timestamp;
            $ttl = max(1, $expiresAt - now()->timestamp + 61);
            if (! $this->cache->add($cacheKey, $fingerprint, $ttl)) {
                $claimed = $this->cache->get($cacheKey);
                if (! is_string($claimed) || ! hash_equals($claimed, $fingerprint)) {
                    return $this->error(
                        'replayed_discord_service_proof',
                        'The Discord service proof was already used for a different request.',
                        409,
                        2,
                    );
                }
            }
        }

        $this->setConnectionAttributes($request, $verified->connection, 2);
        $request->attributes->set(self::ACTION_ATTRIBUTE, $verified->action);
        $request->attributes->set(self::COMMAND_ATTRIBUTE, $verified->command ?? $verified->action);
        $request->attributes->set(self::IDEMPOTENCY_ATTRIBUTE, $verified->idempotencyKey);
        $request->attributes->set(self::KEY_ID_ATTRIBUTE, $verified->keyId);
        $request->attributes->set(self::SERVICE_ATTRIBUTE, $verified->type === 'service');

        if ($verified->type === 'interaction') {
            $request->attributes->set(self::INTERACTION_ATTRIBUTE, $verified->interactionId);
            $request->attributes->set(self::USER_ATTRIBUTE, $verified->userId);
            $request->headers->set(ResolveDiscordActor::USER_HEADER, (string) $verified->userId);
            $request->headers->set('X-Discord-Interaction-ID', (string) $verified->interactionId);
        }

        return $next($request);
    }

    /** @param array<string, mixed> $interaction */
    private function handleV1(
        Request $request,
        Closure $next,
        array $interaction,
        string $payload,
        ?string $expectedServiceAction,
    ): Response {
        $publicKeyHex = trim((string) config('services.discord.relay_public_key'));
        $signatureHex = trim((string) $request->header(self::SIGNATURE_HEADER));
        $timestamp = trim((string) $request->header(self::TIMESTAMP_HEADER));

        if (! (bool) config('services.discord.v1_reader_enabled', false)
            || ! function_exists('sodium_crypto_sign_verify_detached')
            || ! $this->isHexKey($publicKeyHex)) {
            return $this->error(
                'discord_relay_verification_unavailable',
                'The legacy Discord relay reader is not configured.',
                503,
            );
        }
        if (! $this->isHexSignature($signatureHex) || ! ctype_digit($timestamp)) {
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
        if (($interaction['relay_version'] ?? null) !== 1) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay proof version is unsupported.', 401);
        }

        $guildId = trim((string) ($interaction['guild_id'] ?? ''));
        try {
            $connection = $this->connections->resolveLegacy($guildId);
        } catch (DiscordConnectionResolutionException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        if (($interaction['proof_type'] ?? null) === 'service') {
            $nonce = trim((string) ($interaction['nonce'] ?? ''));
            $action = trim((string) ($interaction['action'] ?? ''));
            if (! $this->isUuid($nonce)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $action) !== 1
                || ($expectedServiceAction !== null && ! hash_equals($expectedServiceAction, $action))) {
                return $this->error('invalid_discord_relay_proof', 'The Discord service relay proof is invalid.', 401);
            }

            $fingerprint = hash('sha256', implode("\n", [
                strtoupper($request->getMethod()),
                $request->getRequestUri(),
                hash('sha256', $request->getContent()),
            ]));
            $cacheKey = 'discord:service-proof:'.hash('sha256', $guildId."\0".$nonce);
            $remainingLifetime = max(1, ((int) $timestamp + $maxAge) - now()->timestamp + 1);
            if (! $this->cache->add($cacheKey, $fingerprint, $remainingLifetime)) {
                $claimed = $this->cache->get($cacheKey);
                if (! is_string($claimed) || ! hash_equals($claimed, $fingerprint)) {
                    return $this->error(
                        'replayed_discord_service_proof',
                        'The Discord service relay proof was already used for a different request.',
                        409,
                    );
                }
            }

            $this->setConnectionAttributes($request, $connection, 1);
            $request->attributes->set(self::ACTION_ATTRIBUTE, $action);
            $request->attributes->set(self::COMMAND_ATTRIBUTE, $action);
            $request->attributes->set(self::SERVICE_ATTRIBUTE, true);

            return $next($request);
        }

        if (($interaction['proof_type'] ?? null) !== 'interaction' || $expectedServiceAction !== null) {
            return $this->error('invalid_discord_relay_proof', 'The Discord relay proof type is invalid.', 401);
        }

        $interactionId = trim((string) ($interaction['id'] ?? ''));
        $userId = trim((string) data_get($interaction, 'member.user.id', data_get($interaction, 'user.id', '')));
        if (! $this->isSnowflake($guildId) || ! $this->isSnowflake($interactionId) || ! $this->isSnowflake($userId)) {
            return $this->error('invalid_discord_relay_proof', 'The Discord interaction identity is invalid.', 401);
        }

        $command = $this->commandPath($interaction);
        $this->setConnectionAttributes($request, $connection, 1);
        $request->attributes->set(self::ACTION_ATTRIBUTE, $command);
        $request->attributes->set(self::INTERACTION_ATTRIBUTE, $interactionId);
        $request->attributes->set(self::USER_ATTRIBUTE, $userId);
        $request->attributes->set(self::COMMAND_ATTRIBUTE, $command);
        $request->attributes->set(self::SERVICE_ATTRIBUTE, false);
        $request->headers->set(ResolveDiscordActor::USER_HEADER, $userId);
        $request->headers->set('X-Discord-Interaction-ID', $interactionId);

        return $next($request);
    }

    private function setConnectionAttributes(
        Request $request,
        DiscordConnectionContext $connection,
        int $protocol,
    ): void {
        $request->attributes->set(self::CONNECTION_ATTRIBUTE, $connection);
        $request->attributes->set(self::APPLICATION_ATTRIBUTE, $connection->applicationId);
        $request->attributes->set(self::GUILD_ATTRIBUTE, $connection->guildId);
        $request->attributes->set(self::GENERATION_ATTRIBUTE, $connection->generation);
        $request->attributes->set(self::PROTOCOL_ATTRIBUTE, $protocol);
        $request->headers->set(ResolveDiscordActor::GUILD_HEADER, $connection->guildId);
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
        if ($encodedPayload === '' || strlen($encodedPayload) > 90_000) {
            return null;
        }
        $base64 = strtr($encodedPayload, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $payload = base64_decode($base64, true);

        return is_string($payload) && $payload !== '' && strlen($payload) <= 65_536 ? $payload : null;
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

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function error(
        string $code,
        string $message,
        int $status,
        int $contractVersion = 1,
    ): JsonResponse {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
            'meta' => ['contract_version' => $contractVersion],
        ], $status);
    }
}
