<?php

namespace App\Enums;

enum ScheduledTaskRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
    case Overlap = 'overlap';

    /**
     * The run's finish was never recorded within its expected bound, so its
     * outcome and duration are unknown.
     */
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
