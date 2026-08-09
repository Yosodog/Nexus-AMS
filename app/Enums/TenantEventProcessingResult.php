<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantEventProcessingResult: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
}
