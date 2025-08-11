<?php

use App\Console\Commands\ProcessDeposits;
use App\Services\PWHealthService;
use Illuminate\Support\Facades\Schedule;


$whenPWUp = fn() => app(PWHealthService::class)->isUp();

Schedule::command('pw:health-check')->everyMinute();

// Syncing
Schedule::command('sync:nations')->twiceDailyAt(0, 12, 15)->runInBackground()
    ->withoutOverlapping(10)
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

// Other system stuff
Schedule::command('telescope:prune --hours=72')->dailyAt("23:45");

// Taxes
Schedule::command('taxes:collect')->hourlyAt("15")->when($whenPWUp);

// Military sign in
Schedule::command("military:sign-in")->dailyAt("12:10")->when($whenPWUp);

// Treaty sync
Schedule::command("sync:treaties")->hourlyAt("10")->when($whenPWUp);
Schedule::command("trades:update")->hourlyAt("10")->when($whenPWUp);

