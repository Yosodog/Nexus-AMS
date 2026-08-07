<?php

namespace App\Enums;

enum ScheduledTaskRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
    case Overlap = 'overlap';

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
