<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SubscriptionEnvelopeAuthenticator
{
    /** @var list<string> */
    private const SIGNED_FIELDS = [
        'message_id',
        'schema_version',
        'model',
        'event',
        'source',
        'received_at',
        'payload',
    ];

    public function assertConfigured(): void
    {
        $this->secret();
    }

    /** @param  array<string, string>  $fields */
    public function verify(array $fields, bool $enforceFreshness = true): void
    {
        if (! isset($fields['signature']) || trim($fields['signature']) === '') {
            throw new InvalidArgumentException('Subscription stream message is missing [signature].');
        }

        $expected = hash_hmac('sha256', self::canonicalPayload($fields), $this->secret());

        if (! hash_equals($expected, strtolower($fields['signature']))) {
            throw new InvalidArgumentException('Subscription stream message signature is invalid.');
        }

        if ($enforceFreshness) {
            $this->assertFresh($fields['received_at']);
        }
    }

    public function isMessageIdReservedForStream(string $messageId, string $streamId): bool
    {
        $reservedStreamId = Redis::connection((string) config('subscriptions.redis.connection'))
            ->client()
            ->rawCommand('GET', $this->reservationKey($messageId));

        return is_string($reservedStreamId) && hash_equals($reservedStreamId, $streamId);
    }

    public function reserveMessageId(string $messageId, string $streamId): void
    {
        $key = $this->reservationKey($messageId);
        $script = <<<'LUA'
local existing_stream_id = redis.call('GET', KEYS[1])

if not existing_stream_id then
    redis.call('SET', KEYS[1], ARGV[1])
    return 1
end

if existing_stream_id == ARGV[1] then
    redis.call('PERSIST', KEYS[1])
    return 1
end

return 0
LUA;

        $reserved = Redis::connection((string) config('subscriptions.redis.connection'))
            ->client()
            ->rawCommand('EVAL', $script, 1, $key, $streamId);

        if ((int) $reserved !== 1) {
            throw new InvalidArgumentException("Subscription message ID [{$messageId}] has already been processed.");
        }
    }

    public function expireMessageIdReservation(string $messageId, string $streamId): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end

redis.call('EXPIRE', KEYS[1], ARGV[2])

return 1
LUA;

        Redis::connection((string) config('subscriptions.redis.connection'))
            ->client()
            ->rawCommand(
                'EVAL',
                $script,
                1,
                $this->reservationKey($messageId),
                $streamId,
                (string) max((int) config('subscriptions.redis.replay_ttl_seconds'), 1)
            );
    }

    /** @param  array<string, string>  $fields */
    public static function canonicalPayload(array $fields): string
    {
        $lines = [];

        foreach (self::SIGNED_FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                throw new InvalidArgumentException("Subscription stream message is missing [{$field}].");
            }

            $value = $fields[$field];
            $lines[] = $field.':'.strlen($value).':'.$value;
        }

        return implode("\n", $lines);
    }

    private function assertFresh(string $receivedAt): void
    {
        try {
            $received = CarbonImmutable::parse($receivedAt);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Subscription received_at must be a valid timestamp.', 0, $exception);
        }

        $now = CarbonImmutable::now();
        $maxAge = max((int) config('subscriptions.redis.max_age_seconds'), 1);
        $futureTolerance = max((int) config('subscriptions.redis.future_tolerance_seconds'), 0);

        if ($received->isBefore($now->subSeconds($maxAge))) {
            throw new InvalidArgumentException('Subscription stream message is stale.');
        }

        if ($received->isAfter($now->addSeconds($futureTolerance))) {
            throw new InvalidArgumentException('Subscription stream message timestamp is too far in the future.');
        }
    }

    private function secret(): string
    {
        $secret = (string) config('subscriptions.redis.hmac_secret');

        if (strlen($secret) < 32) {
            throw new RuntimeException('SUBS_REDIS_HMAC_SECRET must be configured with at least 32 characters.');
        }

        return $secret;
    }

    private function reservationKey(string $messageId): string
    {
        return (string) config('subscriptions.redis.replay_prefix').hash('sha256', $messageId);
    }
}
