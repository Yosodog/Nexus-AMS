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
            self::Membership => 'Membership and account',
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
            self::Membership => 'Nation activity and account changes.',
            self::Applications => 'Membership requests and decisions.',
            self::Finance => 'Transactions, loans, and grants you can view.',
            self::Audits => 'Audit status changes without private details.',
            self::Military => 'War planning assignments, results, and notifications.',
            self::Communications => 'Communication history without message contents.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
