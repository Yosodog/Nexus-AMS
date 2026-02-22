<?php

use App\Console\Commands\ProcessDeposits;
use App\Jobs\DispatchBeigeTurnAlertsJob;
use App\Services\PWHealthService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Schedule;

Schedule::command('pw:health-check')->everyMinute();

$whenPWUp = fn () => app(PWHealthService::class)->isUp();

// Syncing
Schedule::command('sync:nations:rolling --scope=highscore')
    ->dailyAt('00:15')
    ->runInBackground()
    ->withoutOverlapping(5)
    ->when(function () use ($whenPWUp) {
        // Only run if PW is up AND today is NOT Monday, so it doesn't overlap with the weekly sync
        return $whenPWUp() && ! now()->isMonday();
    });

Schedule::command('sync:nations:rolling --scope=all')
    ->weeklyOn(1, '00:30')   // Monday 00:30
    ->runInBackground()
    ->withoutOverlapping(5)
    ->when($whenPWUp);

Schedule::command('sync:alliances')->twiceDailyAt(0, 12, 15)->runInBackground()
    ->withoutOverlapping(10)
    ->when($whenPWUp);
Schedule::command('sync:wars')->hourlyAt(10)->runInBackground()
    ->withoutOverlapping(10)
    ->when($whenPWUp);

// Deposits
Schedule::command(ProcessDeposits::class)->everyMinute()->runInBackground()->when($whenPWUp);

// Loan
Schedule::command('loans:process-payments')->dailyAt('00:15');

// Payroll
Schedule::command('payroll:run-daily')
    ->dailyAt('00:30')
    ->timezone('America/Chicago');

// Other system stuff
Schedule::command('telescope:prune --hours=72')->dailyAt('23:45');
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('23:30');
Schedule::command('security:check-rapid-transactions')->everyMinute()->withoutOverlapping(1);
Schedule::command('users:disable-inactive')->dailyAt('01:05')->withoutOverlapping(120);
Schedule::command('audit:prune')->dailyAt('01:15');

// Backups
Schedule::command('backup:run --only-to-disk=s3')
    ->everySixHours()
    ->runInBackground()
    ->withoutOverlapping(360)
    ->when(fn () => SettingService::isBackupsEnabled());
Schedule::command('backup:clean')
    ->dailyAt('02:20')
    ->runInBackground()
    ->withoutOverlapping(120)
    ->when(fn () => SettingService::isBackupsEnabled());

// Taxes
Schedule::command('taxes:collect')->hourlyAt('15')->when($whenPWUp);

Schedule::command('pw:sync-city-average')->dailyAt('00:05')->when($whenPWUp);

// Military sign in
Schedule::command('military:sign-in')->dailyAt('12:10')->when($whenPWUp);

// Inactivity checks
Schedule::command('inactivity:check')
    ->hourly()
    ->runInBackground()
    ->withoutOverlapping(55)
    ->when($whenPWUp);

// Auto withdraw. Run right before a turn change.
Schedule::command('auto:withdraw')->everyOddHour('54')->runInBackground()
    ->withoutOverlapping(10)->when($whenPWUp);

// Audits
Schedule::command('audits:run')
    ->everyFifteenMinutes()
    ->runInBackground()
    ->withoutOverlapping(10);

// Recruitment
Schedule::command('recruit:nations')->everyMinute()->runInBackground()->when($whenPWUp);

// Treaty sync
Schedule::command('sync:treaties')->hourlyAt('10')->when($whenPWUp);
Schedule::command('trades:update')->hourlyAt('10')->when($whenPWUp);
Schedule::command('rebuilding:refresh-estimates')->everyTwoHours()->withoutOverlapping(110);

Schedule::job(new DispatchBeigeTurnAlertsJob('pre_turn'), 'sync')
    ->everyOddHour(50)
    ->withoutOverlapping(9)
    ->when($whenPWUp);

Schedule::job(new DispatchBeigeTurnAlertsJob('post_turn'), 'sync')
    ->everyTwoHours(10)
    ->withoutOverlapping(9)
    ->when($whenPWUp);
