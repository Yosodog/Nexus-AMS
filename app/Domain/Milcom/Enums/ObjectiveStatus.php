<?php

namespace App\Domain\Milcom\Enums;

enum ObjectiveStatus: string
{
    case Pending = 'pending';
    case Review = 'review';
    case Blocked = 'blocked';
    case Approved = 'approved';
    case Dispatching = 'dispatching';
    case Dispatched = 'dispatched';
    case Engaged = 'engaged';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled, self::Expired], true);
    }

    public function reservesCapacity(): bool
    {
        return in_array($this, [self::Approved, self::Dispatching, self::Dispatched, self::Engaged], true);
    }
}
