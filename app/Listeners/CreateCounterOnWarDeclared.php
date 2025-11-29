<?php

namespace App\Listeners;

use App\Enums\AlliancePositionEnum;
use App\Events\WarDeclared;
use App\Jobs\AutoPickCounterAssignmentsJob;
use App\Models\Nation;
use App\Models\WarCounter;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Notifications\WarDeclaredDiscordNotification;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Listener that ensures a draft counter exists when our member is attacked.
 */
class CreateCounterOnWarDeclared
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly PlanOrchestratorService $orchestrator,
        private readonly CacheFactory $cacheFactory
    ) {}

    public function handle(WarDeclared $event): void
    {
        if (! $this->membershipService->contains($event->defenderAllianceId)
            || $event->defenderAlliancePosition === AlliancePositionEnum::APPLICANT->value) {
            return;
        }

        $activeEnemies = $this->orchestrator->getActiveEnemyAllianceIds();

        if ($event->attackerAllianceId && in_array($event->attackerAllianceId, $activeEnemies, true)) {
            Log::info('War counter suppressed by active plan', [
                'war_id' => $event->warId,
                'attacker_alliance_id' => $event->attackerAllianceId,
            ]);

            return;
        }

        $lock = $this->cacheFactory->store()->lock(
            "counter:aggressor:{$event->attackerNationId}",
            (int) config('war.counters.lock_ttl', 30)
        );

        try {
            $lock->block((int) config('war.cache.lock_timeout', 10), function () use ($event) {
                $counter = WarCounter::query()
                    ->firstOrCreate(
                        [
                            'aggressor_nation_id' => $event->attackerNationId,
                        ],
                        [
                            'team_size' => config('war.counters.default_team_size', 3),
                            'war_declaration_type' => config('war.plan_defaults.plan_type', 'ordinary'),
                            'status' => 'draft',
                        ]
                    );

                $counter->update([
                    'status' => $counter->status === 'archived' ? 'draft' : $counter->status,
                    'last_war_declared_at' => now(),
                ]);

                AutoPickCounterAssignmentsJob::dispatch($counter->id);

                $this->queueDiscordWarAlert($event, $counter);
            });
        } catch (LockTimeoutException $exception) {
            Log::warning('Failed to acquire counter lock', [
                'attacker_nation_id' => $event->attackerNationId,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Already released.
            }
        }
    }

    protected function queueDiscordWarAlert(WarDeclared $event, WarCounter $counter): void
    {
        $channelId = SettingService::getDiscordWarAlertChannelId();

        if (! SettingService::isDiscordWarAlertEnabled() || ! is_string($channelId) || $channelId === '') {
            Log::notice('Discord war alert skipped: channel not configured', [
                'war_id' => $event->warId,
            ]);

            return;
        }

        $attacker = Nation::query()->with(['alliance', 'military'])->find($event->attackerNationId);
        $defender = Nation::query()->with(['alliance', 'military'])->find($event->defenderNationId);

        if (! $attacker || ! $defender) {
            Log::warning('Discord war alert skipped: missing nation data', [
                'war_id' => $event->warId,
                'attacker_nation_id' => $event->attackerNationId,
                'defender_nation_id' => $event->defenderNationId,
            ]);

            return;
        }

        try {
            Notification::route(DiscordQueueChannel::class, 'discord-bot')
                ->notify(new WarDeclaredDiscordNotification(
                    $event->warId,
                    $attacker,
                    $defender,
                    $counter,
                    $channelId,
                    Carbon::now()
                ));
        } catch (Throwable $exception) {
            Log::error('Failed to queue Discord war alert', [
                'war_id' => $event->warId,
                'attacker_nation_id' => $event->attackerNationId,
                'defender_nation_id' => $event->defenderNationId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
