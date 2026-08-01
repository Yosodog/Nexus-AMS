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
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordOffshoreIdempotencyTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config()->set('services.pw.alliance_id', 877);
        config()->set('services.discord_bot_key', 'discord-test-token');
        config()->set('services.discord.guild_id', self::GUILD_ID);
        Event::fake();
    }

    public function test_api_replays_completed_request_and_blocks_pending_request(): void
    {
        [$offshore, $user] = $this->createTransferParties();
        $nation = Nation::factory()->create();
        $user->forceFill(['nation_id' => $nation->id, 'is_admin' => true])->save();
        $moderator = $this->grantPermissions($user->fresh(), [
            'manage-offshores',
        ]);
        DiscordAccount::factory()->create([
            'user_id' => $moderator->id,
            'discord_id' => self::DISCORD_ID,
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

        $this->withHeaders($this->discordHeaders('345678901234567890'))
            ->postJson('/api/v1/discord/offshores/sweep-primary', [
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

        $this->withHeaders($this->discordHeaders('456789012345678901'))
            ->postJson('/api/v1/discord/offshores/sweep-primary', [
                'request_id' => 'interaction-api-pending',
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'sweep_reconciliation_required')
            ->assertJsonPath('transfer.id', $pending->id);
    }

    public function test_api_does_not_allow_a_non_admin_to_impersonate_an_admin_from_the_payload(): void
    {
        [, $user] = $this->createTransferParties();
        $nation = Nation::factory()->create();
        $user->forceFill(['nation_id' => $nation->id, 'is_admin' => false])->save();
        $actor = $this->grantPermissions($user->fresh(), ['manage-offshores']);
        DiscordAccount::factory()->create([
            'user_id' => $actor->id,
            'discord_id' => self::DISCORD_ID,
        ]);

        $admin = $this->grantPermissions(
            User::factory()->admin()->verified()->create(),
            ['manage-offshores'],
        );
        $adminDiscord = DiscordAccount::factory()->create(['user_id' => $admin->id]);

        $this->withHeaders($this->discordHeaders('567890123456789012'))
            ->postJson('/api/v1/discord/offshores/sweep-primary', [
                'moderator_discord_id' => $adminDiscord->discord_id,
                'request_id' => 'impersonation-attempt',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden');

        $this->assertDatabaseMissing('offshore_transfers', [
            'idempotency_key' => 'impersonation-attempt',
        ]);
    }

    public function test_bot_token_and_asserted_admin_headers_do_not_authenticate_an_interaction(): void
    {
        [, $user] = $this->createTransferParties();
        $nation = Nation::factory()->create();
        $user->forceFill(['nation_id' => $nation->id, 'is_admin' => true])->save();
        $moderator = $this->grantPermissions($user->fresh(), ['manage-offshores']);
        DiscordAccount::factory()->create([
            'user_id' => $moderator->id,
            'discord_id' => self::DISCORD_ID,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer discord-test-token',
            'X-Discord-Guild-ID' => self::GUILD_ID,
            'X-Discord-User-ID' => self::DISCORD_ID,
            'X-Discord-Interaction-ID' => '678901234567890123',
        ])->postJson('/api/v1/discord/offshores/sweep-primary', [
            'request_id' => 'forged-actor-headers',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_discord_relay_proof');

        $this->assertDatabaseMissing('offshore_transfers', [
            'idempotency_key' => 'forged-actor-headers',
        ]);
    }

    public function test_signed_interaction_for_another_command_cannot_trigger_a_sweep(): void
    {
        [, $user] = $this->createTransferParties();
        $nation = Nation::factory()->create();
        $user->forceFill(['nation_id' => $nation->id, 'is_admin' => true])->save();
        $moderator = $this->grantPermissions($user->fresh(), ['manage-offshores']);
        DiscordAccount::factory()->create([
            'user_id' => $moderator->id,
            'discord_id' => self::DISCORD_ID,
        ]);

        $headers = $this->signedDiscordInteractionHeaders(
            'discord-test-token',
            self::GUILD_ID,
            self::DISCORD_ID,
            '789012345678901234',
            'ping',
        );

        $this->withHeaders($headers)
            ->postJson('/api/v1/discord/offshores/sweep-primary', [
                'request_id' => 'wrong-signed-action',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_interaction_action_mismatch');

        $this->assertDatabaseMissing('offshore_transfers', [
            'idempotency_key' => 'wrong-signed-action',
        ]);
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
    private function discordHeaders(string $interactionId): array
    {
        return $this->signedDiscordInteractionHeaders(
            'discord-test-token',
            self::GUILD_ID,
            self::DISCORD_ID,
            $interactionId,
            'sweepbank',
        );
    }
}
