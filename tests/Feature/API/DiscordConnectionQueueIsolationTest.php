<?php

namespace Tests\Feature\API;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Models\DiscordQueue;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordQueueLeaseService;
use App\Services\Discord\DiscordQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'TEST_DELIVERY',
            ['value' => 'alpha'],
            dedupeKey: 'same-domain-key',
            guildId: $alpha->guildId,
            connection: $alpha,
        );
        $betaItem = $queue->enqueue(
            'TEST_DELIVERY',
            ['value' => 'beta'],
            dedupeKey: 'same-domain-key',
            guildId: $beta->guildId,
            connection: $beta,
        );

        $this->assertNotSame($alphaItem->id, $betaItem->id);
        $this->assertDatabaseCount('discord_queue', 2);

        $leases = app(DiscordQueueLeaseService::class);
        $claimedByBeta = $leases->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::Legacy],
            $beta->guildId,
            $beta,
        );
        $this->assertSame($betaItem->id, $claimedByBeta?->id);

        $claimedByAlpha = $leases->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::Legacy],
            $alpha->guildId,
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
                connection: $staleAlpha,
            );
            $this->fail('A stale connection generation acknowledged a queue item.');
        } catch (DiscordQueueLeaseException $exception) {
            $this->assertSame('stale_connection_generation', $exception->error);
        }

        $this->assertSame(DiscordQueueStatus::Processing, $alphaItem->fresh()->status);
    }

    public function test_unbound_legacy_item_is_atomically_bound_when_v2_claims_it(): void
    {
        $connection = $this->context(
            '11111111-2222-4333-8444-555555555555',
            '123456789012345678',
            '223456789012345678',
            7,
        );
        $item = DiscordQueue::query()->create([
            'action' => 'LEGACY_PENDING',
            'payload' => ['value' => 'safe'],
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'guild_id' => $connection->guildId,
        ]);

        $claimed = app(DiscordQueueLeaseService::class)->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::Legacy],
            $connection->guildId,
            $connection,
        );

        $this->assertSame($item->id, $claimed?->id);
        $this->assertSame($connection->connectionId, $claimed?->connection_id);
        $this->assertSame($connection->applicationId, $claimed?->application_id);
        $this->assertSame($connection->generation, $claimed?->connection_generation);
        $this->assertSame($connection->dedupeScope(), $claimed?->dedupe_scope);
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
            capabilities: ['queue.connection-context.v1' => true],
        );
    }
}
