<?php

namespace Tests\Feature\Discord;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Models\DiscordConnection;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DiscordQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICATION_ID = '123456789012345678';

    private const CONNECTION_ID = '11111111-2222-4333-8444-555555555555';

    private const GUILD_ID = '223456789012345678';

    public function test_enqueue_resolves_and_binds_the_active_v2_connection(): void
    {
        $this->connection([DiscordQueueAction::PrivateNotification->value]);

        $command = app(DiscordQueueService::class)->enqueue(
            DiscordQueueAction::PrivateNotification,
            ['contract_version' => 1],
            DiscordQueueLane::Alerts,
            dedupeKey: 'private-notification:test',
        );

        $this->assertSame(DiscordQueueAction::PrivateNotification->value, $command->action);
        $this->assertSame(DiscordQueueLane::Alerts, $command->lane);
        $this->assertSame(self::CONNECTION_ID, $command->connection_id);
        $this->assertSame(self::APPLICATION_ID, $command->application_id);
        $this->assertSame(self::GUILD_ID, $command->guild_id);
        $this->assertSame(7, $command->connection_generation);
        $this->assertSame(self::CONNECTION_ID.':7', $command->dedupe_scope);
    }

    public function test_enqueue_rejects_an_action_on_the_wrong_lane(): void
    {
        $this->connection([DiscordQueueAction::CityTierSync->value]);

        $this->expectException(InvalidArgumentException::class);
        app(DiscordQueueService::class)->enqueue(
            DiscordQueueAction::CityTierSync,
            ['contract_version' => 1],
            DiscordQueueLane::Alerts,
        );
    }

    public function test_enqueue_rejects_an_action_the_connection_did_not_advertise(): void
    {
        $this->connection([]);

        try {
            app(DiscordQueueService::class)->enqueue(
                DiscordQueueAction::CityTierSync,
                ['contract_version' => 1],
                DiscordQueueLane::SideEffects,
            );
            $this->fail('An unsupported Discord queue action was accepted.');
        } catch (DiscordConnectionResolutionException $exception) {
            $this->assertSame('discord_queue_action_unsupported', $exception->errorCode);
        }

        $this->assertDatabaseCount('discord_queue', 0);
    }

    /** @param list<string> $actions */
    private function connection(array $actions): DiscordConnection
    {
        return DiscordConnection::query()->create([
            'id' => self::CONNECTION_ID,
            'mode' => DiscordConnectionMode::Dedicated,
            'state' => DiscordConnectionState::Active,
            'application_id' => self::APPLICATION_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
            'protocol_version' => 2,
            'relay_current_key_id' => 'relay-current',
            'relay_current_public_key' => str_repeat('a', 43),
            'capability_version' => 1,
            'capabilities' => [
                'capabilities' => ['relay.proof.v2', 'queue.connection-context.v1'],
                'supported_queue_actions' => $actions,
            ],
            'v1_reader_enabled' => false,
            'activated_at' => now(),
        ]);
    }
}
