<?php

namespace Tests\Feature\API;

use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\OffshoreTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Discord\DiscordOffshoreSweepIntentService;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use App\Services\OffshoreTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordOffshoreSweepIntentApiTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    private Offshore $offshore;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();
        config([
            'services.discord_bot_key' => 'discord-test-token',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
        Event::fake();
        $this->offshore = Offshore::query()->create([
            'name' => 'Primary Offshore',
            'alliance_id' => Alliance::factory()->create()->id,
            'enabled' => true,
            'priority' => 1,
        ]);
        $nation = Nation::factory()->create();
        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
            'is_admin' => true,
        ]);
        $this->moderator = $this->grantPermissions($user, ['manage-offshores']);
        DiscordAccount::factory()->create([
            'user_id' => $this->moderator->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);
    }

    public function test_preview_confirm_and_intent_replay_dispatch_one_exact_sweep(): void
    {
        $balances = ['money' => 1250.5, 'food' => 25.0];
        $mainBank = $this->createMock(MainBankService::class);
        $mainBank->expects($this->exactly(3))->method('refreshBalances')->willReturnOnConsecutiveCalls(
            $balances,
            $balances,
            [],
        );
        $transfers = $this->createMock(OffshoreTransferService::class);
        $transfers->expects($this->once())->method('transfer')->willReturnCallback(
            fn (...$arguments): OffshoreTransfer => OffshoreTransfer::query()->create([
                'idempotency_key' => $arguments[7],
                'user_id' => $this->moderator->id,
                'source_type' => OffshoreTransfer::TYPE_MAIN,
                'destination_type' => OffshoreTransfer::TYPE_OFFSHORE,
                'destination_offshore_id' => $this->offshore->id,
                'payload' => $arguments[4],
                'status' => OffshoreTransfer::STATUS_COMPLETED,
                'message' => 'Transfer completed successfully.',
                'completed_at' => now(),
            ]),
        );
        $this->bindSweepService($mainBank, $transfers, expectRefresh: true);

        $previewPayload = ['note' => 'Move current balances'];
        $preview = $this->withHeaders($this->headers('345678901234567890'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/preview', $previewPayload)
            ->assertCreated()
            ->assertJsonPath('data.sweep_required', true)
            ->assertJsonPath('data.summary.offshore.id', $this->offshore->id)
            ->assertJsonPath('data.summary.resources.money', 1250.5)
            ->assertJsonPath('data.intent.action', DiscordOffshoreSweepIntentService::INTENT_ACTION);

        $intentId = (string) $preview->json('data.intent.id');
        $this->assertSame(64, strlen($intentId));
        $this->assertNotSame($intentId, DiscordActionIntent::query()->firstOrFail()->token_hash);
        $confirmPayload = ['intent_id' => $intentId];
        $confirm = $this->withHeaders($this->headers('456789012345678901'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/confirm', $confirmPayload)
            ->assertCreated()
            ->assertJsonPath('data.swept', true)
            ->assertJsonPath('data.reconciliation_required', false)
            ->assertJsonPath('data.transfer.payload.money', 1250.5)
            ->assertJsonPath('meta.idempotent_replay', false);

        $this->withHeaders($this->headers('567890123456789012'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/confirm', $confirmPayload)
            ->assertCreated()
            ->assertJsonPath('data.transfer.id', $confirm->json('data.transfer.id'))
            ->assertJsonPath('meta.idempotent_replay', true);
        $this->assertDatabaseCount('offshore_transfers', 1);
    }

    public function test_confirmation_rejects_changed_balances_before_bank_mutation(): void
    {
        $mainBank = $this->createMock(MainBankService::class);
        $mainBank->expects($this->exactly(2))->method('refreshBalances')->willReturnOnConsecutiveCalls(
            ['money' => 100.0],
            ['money' => 101.0],
        );
        $transfers = $this->createMock(OffshoreTransferService::class);
        $transfers->expects($this->never())->method('transfer');
        $this->bindSweepService($mainBank, $transfers);

        $preview = $this->withHeaders($this->headers('678901234567890123'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/preview', [])
            ->assertCreated();
        $this->withHeaders($this->headers('789012345678901234'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/confirm', [
                'intent_id' => $preview->json('data.intent.id'),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'offshore_sweep_stale');
        $this->assertDatabaseCount('offshore_transfers', 0);
    }

    public function test_empty_preview_creates_no_mutation_intent(): void
    {
        $mainBank = $this->createMock(MainBankService::class);
        $mainBank->expects($this->once())->method('refreshBalances')->willReturn([]);
        $transfers = $this->createMock(OffshoreTransferService::class);
        $transfers->expects($this->never())->method('transfer');
        $this->bindSweepService($mainBank, $transfers);

        $this->withHeaders($this->headers('890123456789012345'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/preview', [])
            ->assertCreated()
            ->assertJsonPath('data.sweep_required', false)
            ->assertJsonPath('data.intent', null);
        $this->assertDatabaseCount('discord_action_intents', 0);
    }

    public function test_confirmation_rechecks_permission_removed_after_preview(): void
    {
        $mainBank = $this->createMock(MainBankService::class);
        $mainBank->expects($this->once())->method('refreshBalances')->willReturn(['money' => 100.0]);
        $transfers = $this->createMock(OffshoreTransferService::class);
        $transfers->expects($this->never())->method('transfer');
        $this->bindSweepService($mainBank, $transfers);

        $preview = $this->withHeaders($this->headers('901234567890123456'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/preview', [])
            ->assertCreated();

        DB::table('role_permissions')->where('permission', 'manage-offshores')->delete();

        $this->withHeaders($this->headers('912345678901234567'))
            ->postJson('/api/v1/discord/offshores/sweep-primary/confirm', [
                'intent_id' => $preview->json('data.intent.id'),
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->assertDatabaseCount('offshore_transfers', 0);
    }

    private function bindSweepService(
        MainBankService $mainBank,
        OffshoreTransferService $transfers,
        bool $expectRefresh = false,
    ): void {
        $offshores = $this->createMock(OffshoreService::class);
        $offshores->method('primary')->willReturn($this->offshore);
        if ($expectRefresh) {
            $offshores->expects($this->once())->method('refreshBalances')->with($this->offshore, true);
        }
        $this->app->instance(DiscordOffshoreSweepIntentService::class, new DiscordOffshoreSweepIntentService(
            $offshores,
            $transfers,
            $mainBank,
            app(AuditLogger::class),
        ));
    }

    /** @return array<string, string> */
    private function headers(string $interactionId): array
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
