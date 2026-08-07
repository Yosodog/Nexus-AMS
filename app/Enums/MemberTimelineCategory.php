<?php

namespace App\Enums;

enum MemberTimelineCategory: string
{
    case Membership = 'membership';
    case Applications = 'applications';
    case Finance = 'finance';
    case Audits = 'audits';
    case Military = 'military';
    case Communications = 'communications';

    public function label(): string
    {
        return match ($this) {
            self::Membership => 'Membership & account',
            self::Applications => 'Applications',
            self::Finance => 'Finance',
            self::Audits => 'Audits',
            self::Military => 'Military',
            self::Communications => 'Communications',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Membership => 'Nation observations and authorized account lifecycle records.',
            self::Applications => 'Membership requests and recorded decisions.',
            self::Finance => 'Transactions, loans, and grant activity you are allowed to view.',
            self::Audits => 'Safe audit lifecycle events without finding evidence or private notes.',
            self::Military => 'Milcom assignment, outcome, and delivery records.',
            self::Communications => 'Communication events without message bodies.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
