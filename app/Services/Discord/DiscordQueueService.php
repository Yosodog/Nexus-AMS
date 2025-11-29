<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class DiscordQueueService
{
    /**
     * Enqueue a Discord bot command.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(string $action, array $payload, ?CarbonInterface $availableAt = null): DiscordQueue
    {
        return DiscordQueue::query()->create([
            'action' => $action,
            'payload' => $payload,
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => $availableAt ?? Carbon::now(),
        ]);
    }
}
