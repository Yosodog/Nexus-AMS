<?php

namespace App\Services\Inactivity;

use App\Models\InactivityEvent;
use App\Models\Nation;

interface InactivityActionHandler
{
    public function handle(Nation $nation, InactivityEvent $event, InactivityActionContext $context): InactivityActionResult;
}
