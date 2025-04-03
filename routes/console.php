<?php

use App\Console\Commands\ProcessDeposits;
use Illuminate\Support\Facades\Schedule;

// Syncing
Schedule::command('sync:nations')->hourlyAt(15)->runInBackground()->withoutOverlapping(10);
Schedule::command('sync:alliances')->hourlyAt(15)->runInBackground()->withoutOverlapping(10);

// Deposits
Schedule::command(ProcessDeposits::class)->everyMinute()->runInBackground();

// Loans
Schedule::command('loans:process-payments')->dailyAt('00:15');

// Other system stuff
Schedule::command('telescope:prune --hours=72')->dailyAt("23:45");

// Taxes
Schedule::command('taxes:collect')->hourlyAt("15");
