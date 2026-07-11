<?php

namespace App\Enums;

enum BlockadeReliefStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Claimed], true);
    }
}
