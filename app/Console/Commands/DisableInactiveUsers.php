<?php

namespace App\Console\Commands;

use App\Enums\MemberInactivityAutomation;
use App\Models\User;
use App\Services\MemberInactivityExceptionEvaluator;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DisableInactiveUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:disable-inactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disables user accounts that have been inactive beyond the configured threshold.';

    /**
     * Execute the console command.
     */
    public function handle(MemberInactivityExceptionEvaluator $exceptionEvaluator): int
    {
        if (! SettingService::isUserInactivityAutoDisableEnabled()) {
            $this->info('Inactive user auto-disable is disabled.');

            return self::SUCCESS;
        }

        $thresholdDays = SettingService::getUserInactivityAutoDisableDays();
        $cutoff = now()->subDays($thresholdDays);
        $protectedNationIds = $exceptionEvaluator->nationIdsSuppressing(
            MemberInactivityAutomation::DisableAccount,
            now(),
        );

        $disabledCount = User::query()
            ->where('disabled', false)
            ->when($protectedNationIds !== [], function ($query) use ($protectedNationIds): void {
                $query->where(function ($query) use ($protectedNationIds): void {
                    $query->whereNull('nation_id')->orWhereNotIn('nation_id', $protectedNationIds);
                });
            })
            ->whereDoesntHave('roles', function ($query): void {
                $query->where('protected', true);
            })
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_active_at', '<=', $cutoff)
                    ->orWhere(function ($innerQuery) use ($cutoff): void {
                        $innerQuery->whereNull('last_active_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->update([
                'disabled' => true,
                'updated_at' => now(),
            ]);

        Log::info('Inactive user auto-disable completed.', [
            'disabled_count' => $disabledCount,
            'threshold_days' => $thresholdDays,
            'cutoff' => $cutoff->toIso8601String(),
            'exception_protected_nation_count' => count($protectedNationIds),
        ]);

        $this->info("Disabled {$disabledCount} inactive user(s).");

        return self::SUCCESS;
    }
}
