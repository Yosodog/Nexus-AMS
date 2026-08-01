<?php

namespace Tests\Feature\Ingestion;

use App\Events\WarDeclared;
use App\Listeners\CreateCounterOnWarDeclared;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use App\Services\SubscriptionEventProcessor;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WarDeclarationRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_war_side_effects_roll_back_the_receipt_and_retry_once(): void
    {
        cache()->forever('alliances:membership:ids', [321]);
        $payload = $this->warPayload();
        $attempts = 0;
        $listener = Mockery::mock(CreateCounterOnWarDeclared::class);
        $listener->shouldReceive('handle')
            ->andReturnUsing(function () use (&$attempts): void {
                $attempts++;

                if ($attempts === 1) {
                    throw new RuntimeException('Counter side effect failed.');
                }
            });
        $this->app->instance(CreateCounterOnWarDeclared::class, $listener);
        $processor = app(SubscriptionEventProcessor::class);

        try {
            $processor->process('war', 'create', $payload);
            $this->fail('A failed war-declaration side effect was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Counter side effect failed.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('war_declaration_receipts', ['war_id' => 901]);

        $processor->process('war', 'create', $payload);
        $attemptsAfterSuccess = $attempts;
        $processor->process('war', 'create', $payload);

        $this->assertDatabaseHas('war_declaration_receipts', ['war_id' => 901]);
        $this->assertSame($attemptsAfterSuccess, $attempts);
    }

    public function test_counter_lock_timeout_propagates_to_the_receipt_transaction(): void
    {
        $membershipService = Mockery::mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('contains')->once()->with(999)->andReturnTrue();
        $orchestrator = Mockery::mock(PlanOrchestratorService::class);
        $orchestrator->shouldReceive('getActiveEnemyAllianceIds')->once()->andReturn([]);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        $lock->shouldReceive('release')->once();
        $cacheStore = Mockery::mock();
        $cacheStore->shouldReceive('lock')->once()->andReturn($lock);
        $cacheFactory = Mockery::mock(CacheFactory::class);
        $cacheFactory->shouldReceive('store')->once()->andReturn($cacheStore);
        $listener = new CreateCounterOnWarDeclared($membershipService, $orchestrator, $cacheFactory);

        $this->expectException(LockTimeoutException::class);

        $listener->handle($this->warDeclaredEvent());
    }

    public function test_notification_queue_failure_propagates_to_the_receipt_transaction(): void
    {
        SettingService::setDiscordWarAlertEnabled(true);
        SettingService::setDiscordWarAlertChannelId('war-alerts');
        Nation::factory()->create(['id' => 1001]);
        Nation::factory()->create(['id' => 2002]);
        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Discord queue is unavailable.'));
        $this->app->instance(NotificationDispatcher::class, $notificationDispatcher);
        $listener = new class(Mockery::mock(AllianceMembershipService::class), Mockery::mock(PlanOrchestratorService::class), app(CacheFactory::class)) extends CreateCounterOnWarDeclared
        {
            public function queueDiscordAlert(WarDeclared $event): void
            {
                $this->queueDiscordWarAlert($event, null);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discord queue is unavailable.');

        $listener->queueDiscordAlert($this->warDeclaredEvent());
    }

    /** @return array<string, int|string> */
    private function warPayload(): array
    {
        return [
            'id' => 901,
            'date' => '2026-03-01 12:00:00',
            'reason' => 'Counter',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => 1001,
            'att_alliance_id' => 321,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 2002,
            'def_alliance_id' => 999,
            'def_alliance_position' => 'MEMBER',
        ];
    }

    private function warDeclaredEvent(): WarDeclared
    {
        return new WarDeclared(
            warId: 901,
            attackerNationId: 1001,
            attackerAllianceId: 321,
            attackerAlliancePosition: 'MEMBER',
            defenderNationId: 2002,
            defenderAllianceId: 999,
            defenderAlliancePosition: 'MEMBER',
        );
    }
}
