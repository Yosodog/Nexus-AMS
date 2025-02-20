<?php

use App\Console\Commands\ProcessDeposits;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ProcessDeposits::class)
    ->everyMinute()
    ->runInBackground();
