<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessHeartbeatRole: string
{
    case Queue = 'queue';
    case Scheduler = 'scheduler';
}
