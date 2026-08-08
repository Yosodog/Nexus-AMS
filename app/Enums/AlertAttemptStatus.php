<?php

namespace App\Enums;

enum AlertAttemptStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case RetryableFailure = 'retryable_failure';
    case PermanentFailure = 'permanent_failure';
    case Quarantined = 'quarantined';
}
