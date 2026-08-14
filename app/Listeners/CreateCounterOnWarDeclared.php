<?php

namespace App\Listeners;

use App\Enums\AlliancePositionEnum;
use App\Events\WarDeclared;
use App\Jobs\AutoPickCounterAssignmentsJob;
use App\Models\WarCounter;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
        if (! (bool) config('milcom.v1_enabled', false)) {
            return;
        }

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
                $counter = null;

                if (SettingService::isWarCounterAutoCreationEnabled()) {
                    $mergeWindowDays = max(1, (int) config('war.counters.merge_window_days', 3));
                    $mergeThreshold = now()->subDays($mergeWindowDays);

                    $counter = WarCounter::query()
                        ->where('aggressor_nation_id', $event->attackerNationId)
                        ->whereIn('status', ['draft', 'active'])
                        ->where(function ($query) use ($mergeThreshold): void {
                            $query->where('last_war_declared_at', '>=', $mergeThreshold)
                                ->orWhere(function ($fallbackQuery) use ($mergeThreshold): void {
                                    $fallbackQuery->whereNull('last_war_declared_at')
                                        ->where('created_at', '>=', $mergeThreshold);
                                });
                        })
                        ->latest('last_war_declared_at')
                        ->latest('created_at')
                        ->first();

                    if (! $counter) {
                        try {
                            $counter = WarCounter::query()->create([
                                'aggressor_nation_id' => $event->attackerNationId,
                                'team_size' => config('war.counters.default_team_size', 3),
                                'war_declaration_type' => config('war.plan_defaults.plan_type', 'ordinary'),
                                'status' => 'draft',
                            ]);
                        } catch (UniqueConstraintViolationException) {
                            $counter = WarCounter::query()
                                ->where('aggressor_nation_id', $event->attackerNationId)
                                ->where('active_key', WarCounter::ACTIVE_KEY_VALUE)
                                ->latest('last_war_declared_at')
                                ->latest('updated_at')
                                ->latest('id')
                                ->first();
                        }
                    }

                    if (! $counter) {
                        Log::warning('War counter auto-creation failed to resolve an open counter after duplicate guard.', [
                            'attacker_nation_id' => $event->attackerNationId,
                            'war_id' => $event->warId,
                        ]);

                        throw new RuntimeException('War counter auto-creation did not resolve an open counter.');
                    } else {
                        $counter->update([
                            'last_war_declared_at' => now(),
                        ]);

                        AutoPickCounterAssignmentsJob::dispatch($counter->id)->afterCommit();
                    }
                }
            });
        } catch (LockTimeoutException $exception) {
            Log::warning('Failed to acquire counter lock', [
                'attacker_nation_id' => $event->attackerNationId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Already released.
            }
        }
    }
}
