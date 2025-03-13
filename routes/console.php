<?php

use App\Console\Commands\ProcessDeposits;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ProcessDeposits::class)
    ->everyMinute()
    ->runInBackground();

Schedule::command('loans:process-payments')->dailyAt('00:15'); // Runs every day at midnight
