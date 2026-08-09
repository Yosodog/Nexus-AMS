<?php

declare(strict_types=1);

namespace App\Services\TenantEvents;

use App\Exceptions\TenantEventConfigurationException;
use App\Exceptions\TenantEventConflictException;
use App\Exceptions\TenantEventRejectedException;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RedisException;
use Throwable;

class TenantEventStreamConsumer
{
    private const CONNECTION = 'tenant_events';

    private const GROUP = 'nexus-ams-v1';

    private const STREAM_PREFIX = 'nexus:tenant-events:v1:';

    public function __construct(
        private readonly RuntimeCapabilities $capabilities,
        private readonly RuntimeBuildMetadata $build,
        private readonly TenantEventAuthenticator $authenticator,
        private readonly TenantEventProcessor $processor,
    ) {}

    public function ensureConsumerGroup(): void
    {
        $this->assertEnabled();
        $this->authenticator->assertConfigured();

        try {
            $this->rawCommand('XGROUP', 'CREATE', $this->streamName(), self::GROUP, '0', 'MKSTREAM');
        } catch (RedisException $exception) {
            if (! str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw $exception;
            }
        }
    }

    public function consumeOnce(): int
    {
        $this->assertEnabled();
        $this->authenticator->assertConfigured();
        $messages = $this->claimStaleMessages();

        if ($messages === []) {
            $messages = $this->readNewMessages();
        }

        foreach ($messages as $message) {
            $this->processMessage($message['id'], $message['fields']);
        }

        return count($messages);
    }

    public function resetConnection(): void
    {
        Redis::purge(self::CONNECTION);
    }

    public function streamName(): string
    {
        $tenantId = $this->build->tenantId();

        if ($tenantId === null) {
            throw new TenantEventConfigurationException;
        }

        return self::STREAM_PREFIX.$tenantId;
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    private function readNewMessages(): array
    {
        $response = $this->rawCommand(
            'XREADGROUP',
            'GROUP',
            self::GROUP,
            $this->consumerName(),
            'COUNT',
            (string) $this->boundedConfig('read_count', 1, 100, 10),
            'BLOCK',
            (string) $this->boundedConfig('block_ms', 1, 60_000, 5_000),
            'STREAMS',
            $this->streamName(),
            '>',
        );

        if (! is_array($response) || ! isset($response[0][1]) || ! is_array($response[0][1])) {
            return [];
        }

        return $this->normalizeMessages($response[0][1]);
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    private function claimStaleMessages(): array
    {
        $response = $this->rawCommand(
            'XAUTOCLAIM',
            $this->streamName(),
            self::GROUP,
            $this->consumerName(),
            (string) $this->boundedConfig('claim_idle_ms', 1, 3_600_000, 60_000),
            '0-0',
            'COUNT',
            (string) $this->boundedConfig('read_count', 1, 100, 10),
        );

        if (! is_array($response) || ! isset($response[1]) || ! is_array($response[1])) {
            return [];
        }

        return $this->normalizeMessages($response[1]);
    }

    /**
     * @param  array<int|string, mixed>  $messages
     * @return list<array{id: string, fields: array<string, string>}>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if (! is_array($message) || ! isset($message[0], $message[1]) || ! is_string($message[0])) {
                continue;
            }

            $fields = $this->fieldsToAssociativeArray($message[1]);

            if ($fields !== null) {
                $normalized[] = ['id' => $message[0], 'fields' => $fields];
            }
        }

        return $normalized;
    }

    /** @return array<string, string>|null */
    private function fieldsToAssociativeArray(mixed $rawFields): ?array
    {
        if (! is_array($rawFields)) {
            return null;
        }

        if (! array_is_list($rawFields)) {
            foreach ($rawFields as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    return null;
                }
            }

            /** @var array<string, string> $rawFields */
            return $rawFields;
        }

        if (count($rawFields) % 2 !== 0) {
            return null;
        }

        $fields = [];

        for ($index = 0, $count = count($rawFields); $index < $count; $index += 2) {
            if (! is_string($rawFields[$index]) || ! is_string($rawFields[$index + 1])) {
                return null;
            }

            $fields[$rawFields[$index]] = $rawFields[$index + 1];
        }

        return $fields;
    }

    /** @param array<string, string> $fields */
    private function processMessage(string $streamId, array $fields): void
    {
        $event = null;
        $startedAt = microtime(true);

        try {
            $event = $this->authenticator->verify($fields);
            $result = $this->processor->process($event);
            $this->acknowledge($streamId);

            Log::info('Processed tenant event.', [
                'stream_id' => $streamId,
                'delivery_id' => $event->deliveryId,
                'event_id' => $event->eventId,
                'event_type' => $event->type->value,
                'result' => $result->value,
                'consumer_processing_ms' => (int) round((microtime(true) - $startedAt) * 1_000),
            ]);
        } catch (TenantEventRejectedException $exception) {
            $this->acknowledge($streamId);
            Log::warning('Rejected tenant event.', [
                'stream_id' => $streamId,
                'failure_code' => $exception->reason->value,
            ]);
        } catch (TenantEventConflictException $exception) {
            $this->acknowledge($streamId);
            Log::error('Rejected conflicting tenant event receipt.', [
                'stream_id' => $streamId,
                'delivery_id' => $event?->deliveryId,
                'event_id' => $event?->eventId,
                'failure_code' => 'receipt_conflict',
                'exception_class' => $exception::class,
            ]);
        } catch (TenantEventConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $deliveries = $this->deliveryCount($streamId);

            Log::warning('Tenant event processing failed.', [
                'stream_id' => $streamId,
                'delivery_id' => $event?->deliveryId,
                'event_id' => $event?->eventId,
                'event_type' => $event?->type->value,
                'deliveries' => $deliveries,
                'failure_code' => 'processing_failed',
                'exception_class' => $exception::class,
            ]);

            if ($deliveries >= $this->boundedConfig('max_deliveries', 1, 100, 5)) {
                $this->acknowledge($streamId);
                Log::error('Exhausted tenant event retries.', [
                    'stream_id' => $streamId,
                    'delivery_id' => $event?->deliveryId,
                    'event_id' => $event?->eventId,
                    'failure_code' => 'retry_exhausted',
                ]);
            }
        }
    }

    private function acknowledge(string $streamId): void
    {
        $this->rawCommand('XACK', $this->streamName(), self::GROUP, $streamId);
    }

    private function deliveryCount(string $streamId): int
    {
        $response = $this->rawCommand(
            'XPENDING',
            $this->streamName(),
            self::GROUP,
            $streamId,
            $streamId,
            '1',
        );

        return is_array($response) && isset($response[0][3]) ? (int) $response[0][3] : 1;
    }

    private function rawCommand(string ...$arguments): mixed
    {
        return $this->connection()->client()->rawCommand(...$arguments);
    }

    private function connection(): Connection
    {
        return Redis::connection(self::CONNECTION);
    }

    private function consumerName(): string
    {
        $configured = config('nexus.tenant_events.consumer');

        if (is_string($configured) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $configured) === 1) {
            return $configured;
        }

        if ($configured !== null && $configured !== '') {
            throw new TenantEventConfigurationException;
        }

        $identity = sprintf('%s-%d', gethostname() ?: 'nexus', getmypid());

        return 'nexus-'.substr(hash('sha256', $identity), 0, 24);
    }

    private function assertEnabled(): void
    {
        if (! $this->capabilities->consumesTenantEvents()) {
            throw new TenantEventConfigurationException;
        }
    }

    private function boundedConfig(string $key, int $minimum, int $maximum, int $default): int
    {
        $value = config('nexus.tenant_events.'.$key);

        return is_int($value) && $value >= $minimum && $value <= $maximum
            ? $value
            : $default;
    }
}
