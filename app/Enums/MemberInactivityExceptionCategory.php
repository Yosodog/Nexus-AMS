<?php

namespace App\Enums;

enum MemberInactivityExceptionCategory: string
{
    case Vacation = 'vacation';
    case MilitaryLeave = 'military_leave';
    case VerifiedOutage = 'verified_outage';
    case ApprovedException = 'approved_exception';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Vacation',
            self::MilitaryLeave => 'Military leave',
            self::VerifiedOutage => 'Verified outage',
            self::ApprovedException => 'Approved exception',
        };
    }
}
