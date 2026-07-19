<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use JsonException;
use RedisException;
use Throwable;
use UnexpectedValueException;

class SubscriptionStreamConsumer
{
    public function __construct(private readonly SubscriptionEventProcessor $processor) {}

    public function ensureConsumerGroup(): void
    {
        try {
            $this->rawCommand('XGROUP', 'CREATE', $this->stream(), $this->group(), '0', 'MKSTREAM');
        } catch (RedisException $exception) {
            if (! str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw $exception;
            }
        }
    }

    public function consumeOnce(): int
    {
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
        Redis::purge($this->connectionName());
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    private function readNewMessages(): array
    {
        $response = $this->rawCommand(
            'XREADGROUP',
            'GROUP',
            $this->group(),
            $this->consumerName(),
            'COUNT',
            max((int) config('subscriptions.redis.read_count'), 1),
            'BLOCK',
            max((int) config('subscriptions.redis.block_ms'), 1),
            'STREAMS',
            $this->stream(),
            '>'
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
            $this->stream(),
            $this->group(),
            $this->consumerName(),
            max((int) config('subscriptions.redis.claim_idle_ms'), 1),
            '0-0',
            'COUNT',
            max((int) config('subscriptions.redis.read_count'), 1)
        );

        if (! is_array($response) || ! isset($response[1]) || ! is_array($response[1])) {
            return [];
        }

        return $this->normalizeMessages($response[1]);
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return list<array{id: string, fields: array<string, string>}>
     */
    private function normalizeMessages(array $messages): array
    {
        return collect($messages)
            ->filter(fn (mixed $message): bool => is_array($message)
                && isset($message[0], $message[1])
                && is_string($message[0])
                && is_array($message[1]))
            ->map(fn (array $message): array => [
                'id' => $message[0],
                'fields' => $this->fieldsToAssociativeArray($message[1]),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<string, string>
     */
    private function fieldsToAssociativeArray(array $fields): array
    {
        $result = [];

        for ($index = 0; $index < count($fields); $index += 2) {
            if (! isset($fields[$index], $fields[$index + 1]) || ! is_string($fields[$index])) {
                throw new UnexpectedValueException('Redis stream entry contains malformed fields.');
            }

            $result[$fields[$index]] = (string) $fields[$index + 1];
        }

        return $result;
    }

    /** @param  array<string, string>  $fields */
    private function processMessage(string $streamId, array $fields): void
    {
        $startedAt = microtime(true);

        try {
            $message = $this->decodeMessage($fields);
            $this->processor->process($message['model'], $message['event'], $message['payload']);

            $this->acknowledgeAndDelete($streamId);

            Log::info('Processed subscription stream message.', [
                'stream_id' => $streamId,
                'message_id' => $message['message_id'],
                'model' => $message['model'],
                'event' => $message['event'],
                'source' => $message['source'],
                'record_count' => $this->recordCount($message['payload']),
                'representative_ids' => $this->representativeIds($message['payload']),
                'received_to_dispatch_ms' => $this->receivedToDispatchMilliseconds($message['received_at']),
                'consumer_processing_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (InvalidArgumentException|JsonException|UnexpectedValueException $exception) {
            $this->deadLetter($streamId, $fields, $exception, $this->deliveryCount($streamId), 'invalid_message');
            $this->acknowledgeAndDelete($streamId);
        } catch (Throwable $exception) {
            $deliveries = $this->deliveryCount($streamId);

            Log::warning('Subscription stream message processing failed.', [
                'stream_id' => $streamId,
                'message_id' => $fields['message_id'] ?? null,
                'model' => $fields['model'] ?? null,
                'event' => $fields['event'] ?? null,
                'deliveries' => $deliveries,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            if ($deliveries >= max((int) config('subscriptions.redis.max_deliveries'), 1)) {
                $this->deadLetter($streamId, $fields, $exception, $deliveries, 'max_deliveries');
                $this->acknowledgeAndDelete($streamId);
            }
        }
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{message_id: string, model: string, event: string, source: string, received_at: string, payload: array<int|string, mixed>}
     *
     * @throws JsonException
     */
    private function decodeMessage(array $fields): array
    {
        foreach (['message_id', 'schema_version', 'model', 'event', 'source', 'received_at', 'payload'] as $required) {
            if (! isset($fields[$required]) || trim($fields[$required]) === '') {
                throw new InvalidArgumentException("Subscription stream message is missing [{$required}].");
            }
        }

        if ($fields['schema_version'] !== '1') {
            throw new InvalidArgumentException("Unsupported subscription schema version [{$fields['schema_version']}].");
        }

        if (! in_array($fields['source'], ['single', 'bulk'], true)) {
            throw new InvalidArgumentException("Unsupported subscription source [{$fields['source']}].");
        }

        $payload = json_decode($fields['payload'], true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Subscription payload must decode to an object or array.');
        }

        return [
            'message_id' => $fields['message_id'],
            'model' => strtolower($fields['model']),
            'event' => strtolower($fields['event']),
            'source' => $fields['source'],
            'received_at' => $fields['received_at'],
            'payload' => $payload,
        ];
    }

    private function acknowledgeAndDelete(string $streamId): void
    {
        $client = $this->connection()->client();
        $client->multi(\Redis::MULTI);
        $client->rawCommand('XACK', $this->stream(), $this->group(), $streamId);
        $client->rawCommand('XDEL', $this->stream(), $streamId);
        $client->exec();
    }

    private function deliveryCount(string $streamId): int
    {
        $response = $this->rawCommand('XPENDING', $this->stream(), $this->group(), $streamId, $streamId, 1);

        return is_array($response) && isset($response[0][3]) ? (int) $response[0][3] : 1;
    }

    /** @param  array<string, string>  $fields */
    private function deadLetter(
        string $streamId,
        array $fields,
        Throwable $exception,
        int $deliveries,
        string $reason
    ): void {
        $file = (string) config('subscriptions.redis.dead_letter_file');
        File::ensureDirectoryExists(dirname($file));
        File::append($file, json_encode([
            'recorded_at' => now()->toIso8601String(),
            'reason' => $reason,
            'stream_id' => $streamId,
            'deliveries' => $deliveries,
            'exception_class' => $exception::class,
            'error' => $exception->getMessage(),
            'message' => $fields,
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        Log::error('Dead-lettered subscription stream message.', [
            'stream_id' => $streamId,
            'message_id' => $fields['message_id'] ?? null,
            'model' => $fields['model'] ?? null,
            'event' => $fields['event'] ?? null,
            'deliveries' => $deliveries,
            'reason' => $reason,
            'exception_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    /** @param  array<int|string, mixed>  $payload */
    private function recordCount(array $payload): int
    {
        return array_is_list($payload) ? count($payload) : 1;
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return list<int|string>
     */
    private function representativeIds(array $payload): array
    {
        $records = array_is_list($payload) ? $payload : [$payload];

        return collect($records)
            ->filter(fn (mixed $record): bool => is_array($record) && isset($record['id']))
            ->pluck('id')
            ->take(5)
            ->values()
            ->all();
    }

    private function receivedToDispatchMilliseconds(string $receivedAt): ?int
    {
        try {
            return max(CarbonImmutable::parse($receivedAt)->diffInMilliseconds(now()), 0);
        } catch (Throwable) {
            return null;
        }
    }

    private function rawCommand(string ...$arguments): mixed
    {
        return $this->connection()->client()->rawCommand(...$arguments);
    }

    private function connection(): Connection
    {
        return Redis::connection($this->connectionName());
    }

    private function connectionName(): string
    {
        return (string) config('subscriptions.redis.connection');
    }

    private function stream(): string
    {
        return (string) config('subscriptions.redis.stream');
    }

    private function group(): string
    {
        return (string) config('subscriptions.redis.group');
    }

    private function consumerName(): string
    {
        return (string) (config('subscriptions.redis.consumer')
            ?: sprintf('%s-%d', gethostname() ?: 'nexus', getmypid()));
    }
}
