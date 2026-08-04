<?php

namespace App\Domain\Milcom\Enums;

enum AssignmentStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Dispatched = 'dispatched';
    case Engaged = 'engaged';
    case Completed = 'completed';
    case Released = 'released';
    case Failed = 'failed';

    public function reservesCapacity(): bool
    {
        return in_array($this, [self::Approved, self::Dispatched, self::Engaged], true);
    }
}
