<?php

namespace Tests\Feature\API;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordQueueLeaseService;
use App\Services\Discord\DiscordQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscordConnectionQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-08T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_claim_acknowledgement_and_dedupe_are_connection_generation_scoped(): void
    {
        $alpha = $this->context(
            '11111111-2222-4333-8444-555555555555',
            '123456789012345678',
            '223456789012345678',
            7,
        );
        $beta = $this->context(
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            '323456789012345678',
            '423456789012345678',
            4,
        );
        $queue = app(DiscordQueueService::class);
        $alphaItem = $queue->enqueue(
            DiscordQueueAction::PrivateNotification,
            ['value' => 'alpha'],
            DiscordQueueLane::Alerts,
            connection: $alpha,
            dedupeKey: 'same-domain-key',
        );
        $betaItem = $queue->enqueue(
            DiscordQueueAction::PrivateNotification,
            ['value' => 'beta'],
            DiscordQueueLane::Alerts,
            connection: $beta,
            dedupeKey: 'same-domain-key',
        );

        $this->assertNotSame($alphaItem->id, $betaItem->id);
        $this->assertDatabaseCount('discord_queue', 2);

        $leases = app(DiscordQueueLeaseService::class);
        $claimedByBeta = $leases->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::Alerts],
            $beta,
        );
        $this->assertSame($betaItem->id, $claimedByBeta?->id);

        $claimedByAlpha = $leases->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::Alerts],
            $alpha,
        );
        $this->assertSame($alphaItem->id, $claimedByAlpha?->id);

        $staleAlpha = $this->context(
            $alpha->connectionId,
            $alpha->applicationId,
            $alpha->guildId,
            8,
        );

        try {
            $leases->acknowledge(
                $claimedByAlpha,
                DiscordQueueStatus::Complete,
                $claimedByAlpha->lease_token,
                null,
                null,
                $staleAlpha,
            );
            $this->fail('A stale connection generation acknowledged a queue item.');
        } catch (DiscordQueueLeaseException $exception) {
            $this->assertSame('stale_connection_generation', $exception->error);
        }

        $this->assertSame(DiscordQueueStatus::Processing, $alphaItem->fresh()->status);
    }

    public function test_unbound_item_is_never_adopted_by_a_v2_claim(): void
    {
        $connection = $this->context(
            '11111111-2222-4333-8444-555555555555',
            '123456789012345678',
            '223456789012345678',
            7,
        );
        $itemId = (string) Str::uuid();
        DB::table('discord_queue')->insert([
            'id' => $itemId,
            'action' => DiscordQueueAction::CityTierSync->value,
            'payload' => json_encode(['value' => 'safe'], JSON_THROW_ON_ERROR),
            'status' => DiscordQueueStatus::Pending->value,
            'attempts' => 0,
            'available_at' => now(),
            'guild_id' => $connection->guildId,
            'lane' => DiscordQueueLane::SideEffects->value,
            'priority' => 50,
            'dedupe_scope' => 'historical:'.$itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimed = app(DiscordQueueLeaseService::class)->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::SideEffects],
            $connection,
        );

        $this->assertNull($claimed);
        $this->assertDatabaseHas('discord_queue', [
            'id' => $itemId,
            'status' => DiscordQueueStatus::Pending->value,
            'connection_id' => null,
        ]);
    }

    private function context(
        string $connectionId,
        string $applicationId,
        string $guildId,
        int $generation,
    ): DiscordConnectionContext {
        return new DiscordConnectionContext(
            connectionId: $connectionId,
            mode: DiscordConnectionMode::OfficialShared,
            applicationId: $applicationId,
            guildId: $guildId,
            generation: $generation,
            protocolVersion: 2,
            relayCurrentKeyId: 'relay-current',
            relayCurrentPublicKey: str_repeat('A', 43),
            capabilities: [
                'capabilities' => ['relay.proof.v2', 'queue.connection-context.v1'],
                'supported_queue_actions' => [DiscordQueueAction::PrivateNotification->value],
            ],
        );
    }
}
