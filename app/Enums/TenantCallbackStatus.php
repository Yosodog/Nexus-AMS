<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantCallbackStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Retryable = 'retryable';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
    case Exhausted = 'exhausted';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Rejected, self::Exhausted], true);
    }
}
