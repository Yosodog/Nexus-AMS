<?php

use App\Console\Commands\ProcessDeposits;
use Illuminate\Support\Facades\Schedule;

// Syncing
Schedule::command('sync:nations')->twiceDailyAt(0, 12, 15)->runInBackground()
    ->withoutOverlapping(10);
Schedule::command('sync:alliances')->twiceDailyAt(0, 12, 15)->runInBackground()
    ->withoutOverlapping(10);
Schedule::command('sync:wars')->hourlyAt(10)->runInBackground()
    ->withoutOverlapping(10);

// Deposits
Schedule::command(ProcessDeposits::class)->everyMinute()->runInBackground();

// Loan
Schedule::command('loans:process-payments')->dailyAt('00:15');

// Other system stuff
Schedule::command('telescope:prune --hours=72')->dailyAt("23:45");

// Taxes
Schedule::command('taxes:collect')->hourlyAt("15");

// Military sign in
Schedule::command("military:sign-in")->dailyAt("12:10");
