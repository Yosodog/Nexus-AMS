<?php

namespace App\Domain\Federation\Enums;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Removed = 'removed';
    case Expired = 'expired';
}
