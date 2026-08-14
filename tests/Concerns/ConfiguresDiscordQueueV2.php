<?php

namespace Tests\Concerns;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\Enums\DiscordQueueAction;
use App\Models\DiscordConnection;
use Illuminate\Support\Facades\Schema;

trait ConfiguresDiscordQueueV2
{
    protected function configureDiscordQueueV2(string $guildId = '123456789012345678'): void
    {
        $connectionId = '11111111-2222-4333-8444-555555555555';
        $applicationId = '223456789012345678';
        $capabilities = [
            'capabilities' => ['relay.proof.v2', 'queue.connection-context.v1'],
            'supported_queue_actions' => array_column(DiscordQueueAction::cases(), 'value'),
        ];

        config([
            'services.discord.connection_id' => $connectionId,
            'services.discord.application_id' => $applicationId,
            'services.discord.guild_id' => $guildId,
            'services.discord.connection_generation' => 7,
            'services.discord.relay_protocol_version' => 2,
            'services.discord.relay_current_key_id' => 'relay-current',
            'services.discord.relay_current_public_key' => str_repeat('a', 43),
            'services.discord.capabilities' => $capabilities,
        ]);

        if (Schema::hasTable('discord_connections') && ! DiscordConnection::query()->active()->exists()) {
            DiscordConnection::query()->create([
                'id' => $connectionId,
                'mode' => DiscordConnectionMode::Dedicated,
                'state' => DiscordConnectionState::Active,
                'application_id' => $applicationId,
                'guild_id' => $guildId,
                'generation' => 7,
                'protocol_version' => 2,
                'relay_current_key_id' => 'relay-current',
                'relay_current_public_key' => str_repeat('a', 43),
                'capability_version' => 1,
                'capabilities' => $capabilities,
                'v1_reader_enabled' => true,
                'activated_at' => now(),
            ]);
        }
    }
}
