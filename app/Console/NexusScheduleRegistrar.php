<?php

namespace App\Console;

use App\Console\Commands\DrawWeeklyLottery;
use App\Console\Commands\ProcessDeposits;
use App\Enums\ProcessHeartbeatRole;
use App\Jobs\DispatchBeigeTurnAlertsJob;
use App\Jobs\DispatchScheduledAlertBatchesJob;
use App\Jobs\EvaluateAlertSubscriptionsJob;
use App\Jobs\ExpireFederationResourcesJob;
use App\Jobs\PruneAlertHistoryJob;
use App\Jobs\PruneFederationMessagesJob;
use App\Jobs\ReconcileBlockadeReliefRequests;
use App\Jobs\ReconcileFederationLinksJob;
use App\Jobs\ReconcileMilcomLifecycleJob;
use App\Jobs\RecordQueueHeartbeat;
use App\Jobs\RefreshBankBalanceSnapshots;
use App\Jobs\RollupAlertMetricsJob;
use App\Jobs\SendAuditRemindersJob;
use App\Jobs\SweepFederationOutboxJob;
use App\Services\ProcessHeartbeatRecorder;
use App\Services\PWHealthService;
use App\Services\RuntimeCapabilities;
use App\Services\SettingService;
use Closure;
use Illuminate\Console\Scheduling\Schedule;

final readonly class NexusScheduleRegistrar
{
    public function __construct(
        private RuntimeCapabilities $capabilities,
        private PWHealthService $pwHealthService,
    ) {}

    public function register(Schedule $schedule): void
    {
        $whenPWUp = fn (): bool => $this->pwHealthService->isUp();

        if ($this->capabilities->runsTenantSchedules()) {
            $this->registerProcessHeartbeatSchedule($schedule);
        }

        if ($this->capabilities->sendsTenantCallbacks()) {
            $this->registerTenantCallbackSchedule($schedule);
        }

        if ($this->capabilities->runsPublicWorldSchedules()) {
            $this->registerPublicDependencySchedule($schedule);
        }

        if ($this->runsMixedSchedules()) {
            $this->registerMixedSynchronizationSchedules($schedule, $whenPWUp);
        }

        if ($this->capabilities->runsTenantSchedules()) {
            $this->registerTenantFinancialSchedules($schedule, $whenPWUp);
            $this->registerTenantOperationalSchedules($schedule);
        }

        if ($this->capabilities->runsPlatformBackups()) {
            $this->registerBackupSchedules($schedule);
        }

        if ($this->capabilities->runsTenantSchedules()) {
            $this->registerTenantProcessingSchedules($schedule, $whenPWUp);
        }

        if ($this->capabilities->runsPublicWorldSchedules()) {
            $this->registerPublicWorldSchedules($schedule, $whenPWUp);
        }

        if ($this->runsMixedSchedules()) {
            $this->registerMixedEconomySchedule($schedule, $whenPWUp);
        }

        if ($this->capabilities->runsPublicWorldSchedules()) {
            $this->registerRadiationSchedule($schedule, $whenPWUp);
        }

        if ($this->capabilities->runsTenantSchedules()) {
            $this->registerTenantDerivedSchedules($schedule, $whenPWUp);
        }
    }

    private function runsMixedSchedules(): bool
    {
        return $this->capabilities->runsPublicWorldSchedules()
            && $this->capabilities->runsTenantSchedules();
    }

    private function registerProcessHeartbeatSchedule(Schedule $schedule): void
    {
        $queue = config('nexus.health.queue');
        $queue = is_string($queue) && preg_match('/\A[a-zA-Z0-9:_-]{1,64}\z/D', $queue) === 1
            ? $queue
            : 'default';

        $schedule->call(function () use ($queue): void {
            app(ProcessHeartbeatRecorder::class)->record(ProcessHeartbeatRole::Scheduler);
            RecordQueueHeartbeat::dispatch()->onQueue($queue);
        })
            ->name('health:record-process-heartbeats')
            ->everyMinute()
            ->withoutOverlapping(2)
            ->onOneServer();
    }

    private function registerTenantCallbackSchedule(Schedule $schedule): void
    {
        $schedule->command('nexus:dispatch-tenant-callbacks --limit=100')
            ->name('platform:dispatch-tenant-callbacks')
            ->everyMinute()
            ->withoutOverlapping(2)
            ->onOneServer();
    }

    private function registerPublicDependencySchedule(Schedule $schedule): void
    {
        $schedule->command('pw:health-check')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping(2)
            ->onOneServer();
    }

    private function registerMixedSynchronizationSchedules(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command('sync:nations:rolling --scope=highscore')
            ->dailyAt('00:15')
            ->runInBackground()
            ->withoutOverlapping(5)
            ->when(function () use ($whenPWUp): bool {
                return $whenPWUp() && ! now()->isMonday();
            });

        $schedule->command('sync:nations:rolling --scope=all')
            ->weeklyOn(1, '00:30')
            ->runInBackground()
            ->withoutOverlapping(5)
            ->when($whenPWUp);

        $schedule->command('sync:alliances')
            ->twiceDailyAt(0, 12, 15)
            ->runInBackground()
            ->withoutOverlapping(10)
            ->when($whenPWUp);

        $schedule->command('sync:wars')
            ->hourlyAt(10)
            ->runInBackground()
            ->withoutOverlapping(10)
            ->when($whenPWUp);
    }

    private function registerTenantFinancialSchedules(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command(ProcessDeposits::class)
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->when($whenPWUp);

        $schedule->job(new RefreshBankBalanceSnapshots, 'default')
            ->hourlyAt(5)
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);

        $schedule->command('loans:process-payments')
            ->dailyAt('00:15')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command('payroll:run-daily')
            ->dailyAt('00:30')
            ->timezone('America/Chicago')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command(DrawWeeklyLottery::class)
            ->everyFiveMinutes()
            ->timezone('UTC')
            ->withoutOverlapping(10)
            ->onOneServer();

        $schedule->command('growth-circles:distribute')
            ->dailyAt('03:00')
            ->timezone('UTC')
            ->withoutOverlapping(120)
            ->when($whenPWUp);
    }

    private function registerTenantOperationalSchedules(Schedule $schedule): void
    {
        $schedule->command('telescope:prune --hours=72')->dailyAt('23:45');
        $schedule->command('sanctum:prune-expired --hours=24')->dailyAt('23:30');
        $schedule->command('security:check-rapid-transactions')->everyMinute()->withoutOverlapping(1);
        $schedule->command('users:disable-inactive')->dailyAt('01:05')->withoutOverlapping(120);
        $schedule->command('members:reconcile-inactivity-exceptions')
            ->name('member-inactivity-exceptions:reconcile-expiry')
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->onOneServer();
        $schedule->command('audit:prune')->dailyAt('01:15');
        $schedule->command('account-statements:prune')
            ->dailyAt('01:25')
            ->withoutOverlapping(60)
            ->onOneServer();
        $schedule->command('scheduler-lifecycle:prune')
            ->dailyAt('01:35')
            ->withoutOverlapping(60)
            ->onOneServer();
        $schedule->command('war-counters:archive-stale')
            ->hourly()
            ->withoutOverlapping(55)
            ->when(fn (): bool => ! (bool) config('milcom.v2_enabled', false));
        $schedule->job(new ReconcileMilcomLifecycleJob, 'default')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer();
        $schedule->job(new EvaluateAlertSubscriptionsJob, 'sync')
            ->hourlyAt(25)
            ->withoutOverlapping(55)
            ->onOneServer();
        $schedule->job(new DispatchScheduledAlertBatchesJob, 'default')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer();
        $schedule->job(new RollupAlertMetricsJob(now('UTC')->subDay()->toDateString()), 'default')
            ->dailyAt('00:20')
            ->timezone('UTC')
            ->withoutOverlapping(60)
            ->onOneServer();
        $schedule->job(new PruneAlertHistoryJob, 'default')
            ->dailyAt('01:20')
            ->timezone('UTC')
            ->withoutOverlapping(120)
            ->onOneServer();
        $schedule->job(new ReconcileBlockadeReliefRequests, 'sync')
            ->hourlyAt(35)
            ->withoutOverlapping(55)
            ->onOneServer();
        $schedule->command('discord-queue:reap-leases')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer();
        $schedule->command('operations:reconcile')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer()
            ->when(fn (): bool => (bool) config('operations.features.coordination', false));
        $schedule->job(new SweepFederationOutboxJob, 'default')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer();
        $schedule->job(new ReconcileFederationLinksJob, 'default')
            ->everyFifteenMinutes()
            ->withoutOverlapping(14)
            ->onOneServer();
        $schedule->job(new ExpireFederationResourcesJob, 'default')
            ->everyFiveMinutes()
            ->withoutOverlapping(4)
            ->onOneServer();
        $schedule->job(new PruneFederationMessagesJob, 'default')
            ->dailyAt('02:30')
            ->withoutOverlapping(120)
            ->onOneServer();
        $schedule->command('discord:sync-city-tiers')
            ->hourlyAt(20)
            ->runInBackground()
            ->withoutOverlapping(55)
            ->onOneServer();
    }

    private function registerBackupSchedules(Schedule $schedule): void
    {
        $schedule->command('backup:run')
            ->cron('30 1,7,13,19 * * *')
            ->timezone('UTC')
            ->runInBackground()
            ->withoutOverlapping(360)
            ->when(fn (): bool => SettingService::isBackupsEnabled());
        $schedule->command('backup:monitor')
            ->dailyAt('02:10')
            ->runInBackground()
            ->withoutOverlapping(60)
            ->when(fn (): bool => SettingService::isBackupsEnabled());
        $schedule->command('backup:clean')
            ->dailyAt('02:20')
            ->runInBackground()
            ->withoutOverlapping(120)
            ->when(fn (): bool => SettingService::isBackupsEnabled());
    }

    private function registerTenantProcessingSchedules(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command('taxes:collect')
            ->hourlyAt('15')
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);

        $schedule->command('pw:sync-city-average')->dailyAt('00:05')->when($whenPWUp);
        $schedule->command('military:sign-in')->dailyAt('12:10')->when($whenPWUp);

        $schedule->command('inactivity:check')
            ->hourly()
            ->runInBackground()
            ->withoutOverlapping(55)
            ->when($whenPWUp);

        $schedule->command('auto:withdraw')
            ->everyOddHour('54')
            ->runInBackground()
            ->withoutOverlapping(10)
            ->when($whenPWUp);

        $schedule->command('audits:run')
            ->hourlyAt(30)
            ->runInBackground()
            ->withoutOverlapping(90)
            ->onOneServer();
        $schedule->job(new SendAuditRemindersJob, 'sync')
            ->dailyAt('18:00')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('recruit:nations')
            ->everyMinute()
            ->runInBackground()
            ->when($whenPWUp);
    }

    private function registerPublicWorldSchedules(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command('sync:treaties')->hourlyAt('10')->when($whenPWUp);
        $schedule->command('trades:update')
            ->hourlyAt('10')
            ->runInBackground()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);
        $schedule->command('market-prices:refresh')
            ->hourlyAt('12')
            ->runInBackground()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);
    }

    private function registerMixedEconomySchedule(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command('economy-context:refresh')
            ->hourlyAt('16')
            ->runInBackground()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);
    }

    private function registerRadiationSchedule(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command('pw:sync-radiation')
            ->hourlyAt('18')
            ->runInBackground()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);
    }

    private function registerTenantDerivedSchedules(Schedule $schedule, Closure $whenPWUp): void
    {
        $schedule->command('profitability:refresh')
            ->hourlyAt('20')
            ->runInBackground()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->when($whenPWUp);
        $schedule->command('build-recommendations:refresh')
            ->cron('25 */2 * * *')
            ->runInBackground()
            ->withoutOverlapping(110)
            ->onOneServer()
            ->when($whenPWUp);
        $schedule->command('rebuilding:refresh-estimates')
            ->everyTwoHours()
            ->withoutOverlapping(110);

        $schedule->job(new DispatchBeigeTurnAlertsJob('pre_turn'), 'sync')
            ->everyOddHour(50)
            ->withoutOverlapping(9)
            ->when($whenPWUp);

        $schedule->job(new DispatchBeigeTurnAlertsJob('post_turn'), 'sync')
            ->everyTwoHours(10)
            ->withoutOverlapping(9)
            ->when($whenPWUp);

        $schedule->command('queue:prune-failed --hours=48')->daily();
    }
}
