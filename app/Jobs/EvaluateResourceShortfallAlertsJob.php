<?php

namespace App\Jobs;

use App\Models\AlertUserSetting;
use App\Services\Alerts\ResourceShortfallAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateResourceShortfallAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('resource-shortfall-alerts'))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(ResourceShortfallAlertService $alerts): void
    {
        if (! $alerts->relaySupportsAlerts()) {
            Log::debug('Resource shortfall alert evaluation skipped.', [
                'reason' => 'discord_relay_capability_unavailable',
            ]);

            return;
        }

        AlertUserSetting::query()
            ->where('resource_shortfall_alerts_enabled', true)
            ->where('discord_enabled', true)
            ->with(['user.discordAccounts', 'user.nation.resources'])
            ->chunkById(100, function ($settings) use ($alerts): void {
                foreach ($settings as $setting) {
                    try {
                        $alerts->evaluate($setting);
                    } catch (Throwable $exception) {
                        Log::warning('Resource shortfall alert evaluation failed.', [
                            'alert_user_setting_id' => $setting->id,
                            'user_id' => $setting->user_id,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }
}
