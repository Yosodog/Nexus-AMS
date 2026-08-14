<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class DiscordQueueService
{
    public function __construct(private readonly DiscordConnectionResolver $connections) {}

    /**
     * Enqueue a Discord bot command.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        DiscordQueueAction $action,
        array $payload,
        DiscordQueueLane $lane,
        ?DiscordConnectionContext $connection = null,
        ?CarbonInterface $availableAt = null,
        ?string $dedupeKey = null,
        int $priority = 50,
        ?int $alertDeliveryBatchId = null,
    ): DiscordQueue {
        $connection ??= $this->connections->resolveForQueueProducer();
        if ($connection->protocolVersion !== 2) {
            throw new DiscordConnectionResolutionException(
                'discord_connection_protocol_unsupported',
                'Discord queue delivery requires an active relay-v2 connection.',
                409,
            );
        }
        if (! $connection->supportsQueueAction($action->value)) {
            throw new DiscordConnectionResolutionException(
                'discord_queue_action_unsupported',
                'The connected Discord bot does not support '.$action->value.'.',
                409,
            );
        }
        if (! $action->supportsLane($lane)) {
            throw new \InvalidArgumentException(
                $action->value.' cannot be queued on the '.$lane->value.' lane.',
            );
        }

        $dedupeScope = $connection->dedupeScope();
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $existing = DiscordQueue::query()
                ->where('dedupe_scope', $dedupeScope)
                ->where('dedupe_key', $dedupeKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            $attributes = [
                'action' => $action->value,
                'payload' => $payload,
                'status' => DiscordQueueStatus::Pending,
                'attempts' => 0,
                'available_at' => $availableAt ?? Carbon::now(),
                'lane' => $lane,
                'priority' => max(0, min(100, $priority)),
                'guild_id' => $connection->guildId,
                'alert_delivery_batch_id' => $alertDeliveryBatchId,
                'connection_id' => $connection->connectionId,
                'application_id' => $connection->applicationId,
                'connection_generation' => $connection->generation,
                'dedupe_scope' => $dedupeScope,
            ];
            if ($dedupeKey !== null && $dedupeKey !== '') {
                $attributes['dedupe_key'] = $dedupeKey;
            }

            return DiscordQueue::query()->create($attributes);
        } catch (QueryException $exception) {
            if ($dedupeKey === null || (string) ($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }

            return DiscordQueue::query()
                ->where('dedupe_scope', $dedupeScope)
                ->where('dedupe_key', $dedupeKey)
                ->firstOrFail();
        }
    }
}
