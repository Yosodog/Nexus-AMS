<?php

namespace Tests\Feature\API;

use App\Exceptions\OffshoreTransferException;
use App\Exceptions\OffshoreTransferReconciliationException;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\OffshoreTransfer;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\OffshoreService;
use App\Services\OffshoreTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class DiscordOffshoreIdempotencyTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', 877);
        config()->set('services.discord_bot_key', 'discord-test-token');
        Event::fake();
    }

    public function test_api_replays_completed_request_and_blocks_pending_request(): void
    {
        [$offshore, $user] = $this->createTransferParties();
        $nation = Nation::factory()->create();
        $user->forceFill(['nation_id' => $nation->id])->save();
        $moderator = $this->grantPermissions($user->fresh(), [
            'manage-offshores',
        ]);
        DiscordAccount::factory()->create([
            'user_id' => $moderator->id,
            'discord_id' => 'moderator-sweep-idempotency',
        ]);

        $completed = OffshoreTransfer::query()->create([
            'idempotency_key' => 'interaction-api-completed',
            'user_id' => $moderator->id,
            'source_type' => OffshoreTransfer::TYPE_MAIN,
            'destination_type' => OffshoreTransfer::TYPE_OFFSHORE,
            'destination_offshore_id' => $offshore->id,
            'payload' => ['money' => 100.0],
            'status' => OffshoreTransfer::STATUS_COMPLETED,
            'message' => 'Transfer completed successfully.',
            'completed_at' => now(),
        ]);

        $this->withHeaders($this->discordHeaders())
            ->postJson('/api/v1/discord/offshores/sweep-primary', [
                'moderator_discord_id' => 'moderator-sweep-idempotency',
                'request_id' => 'interaction-api-completed',
            ])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('transfer.id', $completed->id);

        $pending = OffshoreTransfer::query()->create([
            'idempotency_key' => 'interaction-api-pending',
            'user_id' => $moderator->id,
            'source_type' => OffshoreTransfer::TYPE_MAIN,
            'destination_type' => OffshoreTransfer::TYPE_OFFSHORE,
            'destination_offshore_id' => $offshore->id,
            'payload' => ['money' => 100.0],
            'status' => OffshoreTransfer::STATUS_PENDING,
        ]);

        $this->withHeaders($this->discordHeaders())
            ->postJson('/api/v1/discord/offshores/sweep-primary', [
                'moderator_discord_id' => 'moderator-sweep-idempotency',
                'request_id' => 'interaction-api-pending',
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'sweep_reconciliation_required')
            ->assertJsonPath('transfer.id', $pending->id);
    }

    public function test_completed_transfer_is_replayed_without_dispatching_a_second_bank_mutation(): void
    {
        [$offshore, $user] = $this->createTransferParties();
        $offshoreService = $this->createMock(OffshoreService::class);
        $offshoreService->expects($this->once())->method('refreshBalances');

        $service = new class($offshoreService, app(AllianceMembershipService::class)) extends OffshoreTransferService
        {
            public int $dispatchCount = 0;

            protected function sendFromMainToOffshore(Offshore $offshore, array $payload, string $note): void
            {
                $this->dispatchCount++;
            }
        };

        $first = $service->transfer(
            OffshoreTransfer::TYPE_MAIN,
            null,
            OffshoreTransfer::TYPE_OFFSHORE,
            $offshore,
            ['money' => 100.0],
            $user,
            'Idempotent sweep',
            'interaction-sweep-completed',
        );
        $second = $service->transfer(
            OffshoreTransfer::TYPE_MAIN,
            null,
            OffshoreTransfer::TYPE_OFFSHORE,
            $offshore,
            ['money' => 100.0],
            $user,
            'Idempotent sweep',
            'interaction-sweep-completed',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(OffshoreTransfer::STATUS_COMPLETED, $second->status);
        $this->assertSame(1, $service->dispatchCount);
        $this->assertDatabaseCount('offshore_transfers', 1);
    }

    public function test_ambiguous_committed_response_loss_requires_reconciliation_and_is_never_dispatched_again(): void
    {
        [$offshore, $user] = $this->createTransferParties();
        $offshoreService = $this->createMock(OffshoreService::class);

        $service = new class($offshoreService, app(AllianceMembershipService::class)) extends OffshoreTransferService
        {
            public int $dispatchCount = 0;

            protected function sendFromMainToOffshore(Offshore $offshore, array $payload, string $note): void
            {
                $this->dispatchCount++;

                throw new OffshoreTransferException(
                    'Connection error while executing the transfer.',
                    previous: new ConnectionException('Response was lost after commit.'),
                );
            }
        };

        try {
            $service->transfer(
                OffshoreTransfer::TYPE_MAIN,
                null,
                OffshoreTransfer::TYPE_OFFSHORE,
                $offshore,
                ['money' => 100.0],
                $user,
                'Ambiguous sweep',
                'interaction-sweep-ambiguous',
            );
            $this->fail('The ambiguous transfer should throw.');
        } catch (OffshoreTransferException) {
            $this->assertTrue(true);
        }

        $transfer = OffshoreTransfer::query()
            ->where('idempotency_key', 'interaction-sweep-ambiguous')
            ->firstOrFail();

        $this->assertSame(OffshoreTransfer::STATUS_RECONCILIATION_REQUIRED, $transfer->status);

        try {
            $service->transfer(
                OffshoreTransfer::TYPE_MAIN,
                null,
                OffshoreTransfer::TYPE_OFFSHORE,
                $offshore,
                ['money' => 100.0],
                $user,
                'Ambiguous sweep',
                'interaction-sweep-ambiguous',
            );
            $this->fail('The repeated transfer should require reconciliation.');
        } catch (OffshoreTransferReconciliationException $exception) {
            $this->assertSame($transfer->id, $exception->transfer->id);
        }

        $this->assertSame(1, $service->dispatchCount);
        $this->assertDatabaseCount('offshore_transfers', 1);
    }

    /**
     * @return array{Offshore, User}
     */
    private function createTransferParties(): array
    {
        $alliance = Alliance::factory()->create();
        $offshore = Offshore::query()->create([
            'name' => 'Test Offshore',
            'alliance_id' => $alliance->id,
            'enabled' => true,
            'priority' => 1,
        ]);

        return [$offshore, User::factory()->create()];
    }

    /**
     * @return array<string, string>
     */
    private function discordHeaders(): array
    {
        return [
            'Authorization' => 'Bearer discord-test-token',
            'Accept' => 'application/json',
        ];
    }
}
