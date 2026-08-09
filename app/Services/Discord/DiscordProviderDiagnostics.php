<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;

class DiscordProviderDiagnostics
{
    /** @return array<string, mixed> */
    public function forConnection(DiscordConnectionContext $connection): array
    {
        $queue = DiscordQueue::query()->where(function ($query) use ($connection): void {
            $query->where(function ($current) use ($connection): void {
                $current
                    ->where('connection_id', $connection->connectionId)
                    ->where('connection_generation', $connection->generation);
            });

            if ($connection->isDedicated()) {
                $query->orWhereNull('connection_id');
            }
        });

        $counts = (clone $queue)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'provider' => [
                'name' => 'nexus',
                'status' => 'available',
                'authorization_authority' => 'nexus',
            ],
            'connection' => [
                'mode' => $connection->mode->value,
                'connection_id' => $connection->connectionId,
                'application_id' => $connection->applicationId,
                'guild_id' => $connection->guildId,
                'generation' => $connection->generation,
                'relay_protocol' => $connection->protocolVersion,
                'relay_key_id' => $connection->relayCurrentKeyId,
                'nexus_key_id' => $connection->nexusCurrentKeyId,
                'capability_version' => $connection->capabilityVersion,
            ],
            'capabilities' => [
                'version' => $connection->capabilityVersion,
                'keys' => $connection->capabilityKeys(),
            ],
            'queue' => [
                'pending' => (int) ($counts[DiscordQueueStatus::Pending->value] ?? 0),
                'processing' => (int) ($counts[DiscordQueueStatus::Processing->value] ?? 0),
                'failed' => (int) ($counts[DiscordQueueStatus::Failed->value] ?? 0),
                'complete' => (int) ($counts[DiscordQueueStatus::Complete->value] ?? 0),
            ],
        ];
    }
}
