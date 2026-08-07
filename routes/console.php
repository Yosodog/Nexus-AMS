<?php

use App\Console\NexusScheduleRegistrar;
use Illuminate\Console\Scheduling\Schedule;

app(NexusScheduleRegistrar::class)->register(app(Schedule::class));
