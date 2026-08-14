<?php

namespace App\Listeners;

use App\Enums\AlliancePositionEnum;
use App\Events\WarDeclared;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\WarCounter;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Notifications\WarDeclaredDiscordNotification;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendWarDeclaredDiscordNotification
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly PlanOrchestratorService $orchestrator,
        private readonly CacheFactory $cacheFactory,
    ) {}

    public function handle(WarDeclared $event): void
    {
        if (! $this->membershipService->contains($event->defenderAllianceId)
            || $event->defenderAlliancePosition === AlliancePositionEnum::APPLICANT->value) {
            return;
        }

        $activeEnemies = $this->orchestrator->getActiveEnemyAllianceIds();

        if ($event->attackerAllianceId && in_array($event->attackerAllianceId, $activeEnemies, true)) {
            Log::info('Discord war alert suppressed by active plan', [
                'war_id' => $event->warId,
                'attacker_alliance_id' => $event->attackerAllianceId,
            ]);

            return;
        }

        try {
            $this->queueDiscordWarAlert($event, $this->resolveLegacyCounter($event));
        } catch (Throwable $exception) {
            Log::error('Failed to queue Discord war alert without aborting war declaration processing', [
                'war_id' => $event->warId,
                'attacker_nation_id' => $event->attackerNationId,
                'defender_nation_id' => $event->defenderNationId,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveLegacyCounter(WarDeclared $event): ?WarCounter
    {
        if (! (bool) config('milcom.v1_enabled', false)
            || ! SettingService::isWarCounterAutoCreationEnabled()) {
            return null;
        }

        return WarCounter::query()
            ->where('aggressor_nation_id', $event->attackerNationId)
            ->whereIn('status', ['draft', 'active'])
            ->latest('last_war_declared_at')
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    private function queueDiscordWarAlert(WarDeclared $event, ?WarCounter $counter): void
    {
        $channelId = SettingService::getDiscordWarAlertChannelId();

        if (! SettingService::isDiscordWarAlertEnabled() || $channelId === '') {
            Log::notice('Discord war alert skipped: channel not configured', [
                'war_id' => $event->warId,
            ]);

            return;
        }

        $lockAcquired = $this->cacheFactory->store()->lock("discord:war-alert:{$event->warId}", 15)->get(function () use (
            $event,
            $counter,
            $channelId
        ): bool {
            if ($this->hasRecentQueuedWarAlert($event->warId)) {
                Log::info('Discord war alert skipped due to recent queued job', [
                    'war_id' => $event->warId,
                ]);

                return true;
            }

            $attacker = Nation::query()->with(['alliance', 'military'])->find($event->attackerNationId);
            $defender = Nation::query()->with(['alliance', 'military'])->find($event->defenderNationId);

            if (! $attacker || ! $defender) {
                Log::warning('Discord war alert skipped: missing nation data', [
                    'war_id' => $event->warId,
                    'attacker_nation_id' => $event->attackerNationId,
                    'defender_nation_id' => $event->defenderNationId,
                ]);

                return true;
            }

            Notification::route(DiscordQueueChannel::class, 'discord-bot')
                ->notify(new WarDeclaredDiscordNotification(
                    $event->warId,
                    $attacker,
                    $defender,
                    $counter,
                    $channelId,
                    Carbon::now(),
                ));

            return true;
        });

        if (! $lockAcquired) {
            Log::info('Discord war alert skipped due to overlapping lock', [
                'war_id' => $event->warId,
            ]);
        }
    }

    private function hasRecentQueuedWarAlert(int $warId): bool
    {
        return DiscordQueue::query()
            ->where('action', 'WAR_ALERT')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereJsonContains('payload->war_id', $warId)
            ->exists();
    }
}
