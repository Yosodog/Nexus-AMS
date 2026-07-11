<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class DiscordQueueService
{
    /**
     * Enqueue a Discord bot command.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $action,
        array $payload,
        ?CarbonInterface $availableAt = null,
        ?string $dedupeKey = null,
    ): DiscordQueue {
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $existing = DiscordQueue::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            $attributes = [
                'action' => $action,
                'payload' => $payload,
                'status' => DiscordQueueStatus::Pending,
                'attempts' => 0,
                'available_at' => $availableAt ?? Carbon::now(),
            ];
            if ($dedupeKey !== null && $dedupeKey !== '') {
                $attributes['dedupe_key'] = $dedupeKey;
            }

            return DiscordQueue::query()->create($attributes);
        } catch (QueryException $exception) {
            if ($dedupeKey === null || (string) ($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }

            return DiscordQueue::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }
}
