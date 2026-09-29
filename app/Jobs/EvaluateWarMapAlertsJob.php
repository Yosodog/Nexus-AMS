<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\War;
use App\Services\RuntimeCapabilities;
use App\Services\War\WarMapAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateWarMapAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('war-map-alerts'))->releaseAfter(60)->expireAfter(180)];
    }

    public function handle(WarMapAlertService $alerts, RuntimeCapabilities $capabilities): void
    {
        if (! $capabilities->runsTenantSchedules()) {
            return;
        }

        User::query()
            ->where('disabled', false)
            ->whereNotNull('verified_at')
            ->whereNotNull('nation_id')
            ->with(['nation', 'discordAccounts', 'discordNotificationPreferences'])
            ->chunkById(100, function (Collection $users) use ($alerts): void {
                $nationIds = $users->pluck('nation_id');
                $wars = War::query()->active()
                    ->where(fn (Builder $query): Builder => $query->whereIn('att_id', $nationIds)->orWhereIn('def_id', $nationIds))
                    ->where('updated_at', '>=', now()->subMinutes(WarMapAlertService::MAX_AGE_MINUTES))
                    ->with(['attacker', 'defender'])
                    ->get();
                $warsByNation = $wars->groupBy(fn (War $war): array => [(int) $war->att_id, (int) $war->def_id]);

                foreach ($users as $user) {
                    foreach ($warsByNation->get((int) $user->nation_id, []) as $war) {
                        try {
                            $alerts->evaluate($user, $war);
                        } catch (Throwable $exception) {
                            Log::warning('Maxed war action point alert evaluation failed.', [
                                'user_id' => $user->id,
                                'war_id' => $war->id,
                                'exception' => $exception::class,
                                'message' => $exception->getMessage(),
                            ]);
                        }
                    }
                }
            });
    }
}
