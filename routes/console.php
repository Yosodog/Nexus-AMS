<?php

use App\Console\Commands\ProcessDeposits;
use App\Services\PWHealthService;
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
Schedule::command('security:check-rapid-transactions')->everyMinute()->withoutOverlapping(1);

// Taxes
Schedule::command('taxes:collect')->hourlyAt('15')->when($whenPWUp);

Schedule::command('pw:sync-city-average')->dailyAt('00:05')->when($whenPWUp);

// Military sign in
Schedule::command('military:sign-in')->dailyAt('12:10')->when($whenPWUp);

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
