<?php

namespace App\Services\Discord\Relay;

use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordConnectionResolver;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DiscordRelayProofVerifier
{
    private const COMMON_KEYS = [
        'app_id',
        'audience',
        'body_sha256',
        'connection_id',
        'contract',
        'contract_version',
        'expires_at',
        'generation',
        'guild_id',
        'idempotency_key',
        'issued_at',
        'issuer',
        'key_id',
        'key_scope',
        'method',
        'normalized_path_query',
        'proof',
        'signature',
    ];

    public function __construct(private readonly DiscordConnectionResolver $connections) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function verify(
        Request $request,
        array $document,
        string $signatureHeader,
        string $timestampHeader,
    ): VerifiedDiscordRelayProof {
        $this->assertExactKeys($document, self::COMMON_KEYS);
        $this->assertCommonShape($document);

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new DiscordRelayProofException(
                'discord_relay_verification_unavailable',
                'Discord relay signature verification is unavailable.',
                503,
            );
        }

        try {
            $connection = $this->connections->resolveForVerification($document['connection_id']);
        } catch (DiscordConnectionResolutionException $exception) {
            throw new DiscordRelayProofException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        }

        if ($connection->protocolVersion !== 2) {
            throw new DiscordRelayProofException(
                'discord_relay_version_not_accepted',
                'This Discord connection does not accept relay proof v2.',
                409,
            );
        }

        $issuedAt = $this->date($document['issued_at']);
        $expiresAt = $this->date($document['expires_at']);
        $now = now()->timestamp;
        $maximumLifetime = min(300, max(30, (int) config('services.discord.interaction_max_age_seconds', 300)));
        $clockSkew = 60;

        if ($expiresAt->getTimestamp() <= $issuedAt->getTimestamp()
            || $expiresAt->getTimestamp() - $issuedAt->getTimestamp() > $maximumLifetime) {
            throw new DiscordRelayProofException(
                'invalid_discord_relay_lifetime',
                'The Discord relay proof lifetime is invalid.',
            );
        }
        if ($issuedAt->getTimestamp() > $now + $clockSkew
            || $expiresAt->getTimestamp() < $now - $clockSkew) {
            throw new DiscordRelayProofException(
                'stale_discord_relay_proof',
                'The Discord relay proof has expired or is not yet valid.',
            );
        }
        if (! ctype_digit($timestampHeader)
            || (int) $timestampHeader !== $issuedAt->getTimestamp()) {
            throw new DiscordRelayProofException(
                'discord_relay_header_mismatch',
                'The Discord relay timestamp header does not match the signed document.',
            );
        }

        $signature = $document['signature'];
        if (! hash_equals($signature['value'], $signatureHeader)) {
            throw new DiscordRelayProofException(
                'discord_relay_header_mismatch',
                'The Discord relay signature header does not match the signed document.',
            );
        }

        $actualMethod = strtoupper($request->getMethod());
        $actualTarget = $request->getRequestUri();
        $actualBodyHash = hash('sha256', $request->getContent());
        if (! hash_equals($document['method'], $actualMethod)
            || ! hash_equals($document['normalized_path_query'], $actualTarget)
            || ! hash_equals($document['body_sha256'], $actualBodyHash)) {
            throw new DiscordRelayProofException(
                'discord_relay_request_binding_mismatch',
                'The Discord relay proof does not match the HTTP request.',
            );
        }
        $this->assertNormalizedTarget($document['normalized_path_query']);

        $publicKey = $this->publicKey($connection, $document['key_id'], $now);
        $detachedSignature = hex2bin($signature['value']);
        $unsigned = $document;
        unset($unsigned['signature']);

        try {
            $canonical = CanonicalJson::encode($unsigned);
        } catch (InvalidArgumentException) {
            throw new DiscordRelayProofException(
                'invalid_discord_relay_proof',
                'The Discord relay proof is not canonically representable.',
            );
        }

        if ($detachedSignature === false || ! sodium_crypto_sign_verify_detached(
            $detachedSignature,
            "NEXUS-DISCORD-RELAY-PROOF-V2\n".$canonical,
            $publicKey,
        )) {
            throw new DiscordRelayProofException(
                'invalid_discord_relay_signature',
                'Discord relay signature verification failed.',
            );
        }

        if (! hash_equals($connection->applicationId, $document['app_id'])
            || ! hash_equals($connection->guildId, $document['guild_id'])) {
            throw new DiscordRelayProofException(
                'discord_connection_binding_mismatch',
                'The Discord relay proof does not match the accepted application and guild.',
                403,
            );
        }
        if ($connection->generation !== $document['generation']) {
            throw new DiscordRelayProofException(
                'stale_discord_connection_generation',
                'The Discord connection generation is stale.',
                409,
            );
        }

        /** @var array<string, mixed> $proof */
        $proof = $document['proof'];

        return $proof['type'] === 'interaction'
            ? new VerifiedDiscordRelayProof(
                connection: $connection,
                type: 'interaction',
                action: $proof['action'],
                idempotencyKey: $document['idempotency_key'],
                keyId: $document['key_id'],
                issuedAt: $document['issued_at'],
                expiresAt: $document['expires_at'],
                interactionId: $proof['interaction_id'],
                userId: $proof['user_id'],
                command: $proof['command'],
            )
            : new VerifiedDiscordRelayProof(
                connection: $connection,
                type: 'service',
                action: $proof['action'],
                idempotencyKey: $document['idempotency_key'],
                keyId: $document['key_id'],
                issuedAt: $document['issued_at'],
                expiresAt: $document['expires_at'],
                nonce: $proof['nonce'],
            );
    }

    /** @param array<string, mixed> $document */
    private function assertCommonShape(array $document): void
    {
        if ($document['contract'] !== 'relay-proof'
            || $document['contract_version'] !== 2
            || $document['issuer'] !== 'discord-relay'
            || $document['audience'] !== 'nexus'
            || $document['key_scope'] !== 'discord-relay->nexus') {
            throw new DiscordRelayProofException(
                'invalid_discord_relay_direction',
                'The Discord relay proof contract or trust direction is invalid.',
            );
        }

        foreach (['app_id', 'guild_id'] as $snowflake) {
            if (! is_string($document[$snowflake])
                || preg_match('/^[0-9]{17,20}$/', $document[$snowflake]) !== 1) {
                throw new DiscordRelayProofException('invalid_discord_relay_proof', 'A Discord binding is invalid.');
            }
        }
        foreach (['connection_id', 'idempotency_key'] as $uuid) {
            if (! is_string($document[$uuid]) || ! $this->isUuid($document[$uuid])) {
                throw new DiscordRelayProofException('invalid_discord_relay_proof', 'A relay identifier is invalid.');
            }
        }
        if (! is_int($document['generation'])
            || $document['generation'] < 1
            || $document['generation'] > 2_147_483_647) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay generation is invalid.');
        }
        if (! is_string($document['key_id'])
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/', $document['key_id']) !== 1) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay key ID is invalid.');
        }
        if (! is_string($document['method'])
            || ! in_array($document['method'], ['DELETE', 'GET', 'PATCH', 'POST', 'PUT'], true)
            || ! is_string($document['normalized_path_query'])
            || strlen($document['normalized_path_query']) > 2048
            || ! is_string($document['body_sha256'])
            || preg_match('/^[a-f0-9]{64}$/', $document['body_sha256']) !== 1) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay request binding is invalid.');
        }

        if (! is_array($document['signature'])) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay signature is invalid.');
        }
        $this->assertExactKeys($document['signature'], ['algorithm', 'value']);
        if (($document['signature']['algorithm'] ?? null) !== 'ed25519'
            || ! is_string($document['signature']['value'] ?? null)
            || preg_match('/^[a-f0-9]{128}$/', $document['signature']['value']) !== 1) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay signature is invalid.');
        }

        if (! is_array($document['proof'])) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay principal is invalid.');
        }
        $this->assertProofShape($document['proof']);
    }

    /** @param array<string, mixed> $proof */
    private function assertProofShape(array $proof): void
    {
        if (($proof['type'] ?? null) === 'interaction') {
            $this->assertExactKeys($proof, ['action', 'command', 'interaction_id', 'type', 'user_id']);
            if (! $this->isSnowflake($proof['interaction_id'] ?? null)
                || ! $this->isSnowflake($proof['user_id'] ?? null)
                || ! is_string($proof['command'] ?? null)
                || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $proof['command']) !== 1
                || ! $this->isAction($proof['action'] ?? null, 128)) {
                throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The interaction principal is invalid.');
            }

            return;
        }

        if (($proof['type'] ?? null) === 'service') {
            $this->assertExactKeys($proof, ['action', 'nonce', 'type']);
            if (! $this->isAction($proof['action'] ?? null, 100)
                || ! is_string($proof['nonce'] ?? null)
                || ! $this->isUuid($proof['nonce'])) {
                throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The service principal is invalid.');
            }

            return;
        }

        throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay principal type is invalid.');
    }

    private function publicKey(DiscordConnectionContext $connection, string $keyId, int $now): string
    {
        $encoded = null;
        if (hash_equals($connection->relayCurrentKeyId, $keyId)) {
            $encoded = $connection->relayCurrentPublicKey;
        } elseif ($connection->relayNextKeyId !== null
            && hash_equals($connection->relayNextKeyId, $keyId)
            && $connection->relayNextPublicKey !== null
            && $connection->relayNextActivatesAt !== null
            && $this->date($connection->relayNextActivatesAt)->getTimestamp() <= $now) {
            $encoded = $connection->relayNextPublicKey;
        }

        if ($encoded === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $encoded) !== 1) {
            throw new DiscordRelayProofException(
                'unknown_discord_relay_key',
                'The Discord relay key is not accepted for this connection and direction.',
            );
        }

        $base64 = strtr($encoded, '-_', '+/').'=';
        $decoded = base64_decode($base64, true);
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DiscordRelayProofException('invalid_discord_relay_key', 'The Discord relay public key is invalid.', 503);
        }

        return $decoded;
    }

    private function assertNormalizedTarget(string $target): void
    {
        if (preg_match(
            "/^\/(?:[A-Za-z0-9._~!$'()*+,;=:@\/-]|%[0-9A-F]{2})*(?:\?(?:[A-Za-z0-9._~!$'()*+,;:@\/-]|%[0-9A-F]{2})+(?:=(?:[A-Za-z0-9._~!$'()*+,;:@\/-]|%[0-9A-F]{2})*)?(?:&(?:[A-Za-z0-9._~!$'()*+,;:@\/-]|%[0-9A-F]{2})+(?:=(?:[A-Za-z0-9._~!$'()*+,;:@\/-]|%[0-9A-F]{2})*)?)*)?$/",
            $target,
        ) !== 1) {
            throw new DiscordRelayProofException('invalid_discord_relay_target', 'The relay request target is invalid.');
        }
        if (preg_match('/%(?:2D|2E|30|31|32|33|34|35|36|37|38|39|41|42|43|44|45|46|47|48|49|4A|4B|4C|4D|4E|4F|50|51|52|53|54|55|56|57|58|59|5A|5F|61|62|63|64|65|66|67|68|69|6A|6B|6C|6D|6E|6F|70|71|72|73|74|75|76|77|78|79|7A|7E)/', $target) === 1) {
            throw new DiscordRelayProofException(
                'invalid_discord_relay_target',
                'Unreserved request-target characters must not be percent encoded.',
            );
        }

        [$path, $query] = array_pad(explode('?', $target, 2), 2, null);
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new DiscordRelayProofException('invalid_discord_relay_target', 'Dot path segments are not accepted.');
            }
        }
        if ($query === null) {
            return;
        }

        $pairs = [];
        foreach (explode('&', $query) as $position => $pair) {
            if (substr_count($pair, '=') > 1) {
                throw new DiscordRelayProofException('invalid_discord_relay_target', 'A query pair is ambiguous.');
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === '') {
                throw new DiscordRelayProofException('invalid_discord_relay_target', 'A query key is empty.');
            }
            $pairs[] = [rawurldecode($key), rawurldecode($value), $position];
        }
        $sorted = $pairs;
        usort($sorted, static fn (array $left, array $right): int => (
            strcmp($left[0], $right[0])
            ?: strcmp($left[1], $right[1])
            ?: ($left[2] <=> $right[2])
        ));
        if ($pairs !== $sorted) {
            throw new DiscordRelayProofException('invalid_discord_relay_target', 'Query pairs are not canonically sorted.');
        }
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new DiscordRelayProofException('invalid_discord_relay_proof', 'The relay proof has missing or unknown fields.');
        }
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?Z$/', $value) !== 1) {
            throw new DiscordRelayProofException('invalid_discord_relay_time', 'A relay timestamp is invalid.');
        }
        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z';
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DiscordRelayProofException('invalid_discord_relay_time', 'A relay timestamp is invalid.');
        }

        return $date;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }

    private function isSnowflake(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9]{17,20}$/', $value) === 1;
    }

    private function isAction(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && strlen($value) <= $maximum
            && preg_match('/^[a-z][a-z0-9._:-]*$/', $value) === 1;
    }
}
