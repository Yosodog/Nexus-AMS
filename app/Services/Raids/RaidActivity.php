<?php

namespace App\Services\Raids;

use Carbon\CarbonInterface;

/**
 * Buckets a nation's last activity relative to a point in time.
 */
final class RaidActivity
{
    public const ACTIVE = 'active';

    public const RECENT = 'recent';

    public const IDLE = 'idle';

    public const INACTIVE = 'inactive';

    public const ABANDONED = 'abandoned';

    public static function bucket(?CarbonInterface $lastActive, CarbonInterface $at): string
    {
        if ($lastActive === null) {
            return self::ABANDONED;
        }

        $hours = ($at->getTimestamp() - $lastActive->getTimestamp()) / 3600;

        return match (true) {
            $hours < 24 => self::ACTIVE,
            $hours < 72 => self::RECENT,
            $hours < 168 => self::IDLE,
            $hours < 720 => self::INACTIVE,
            default => self::ABANDONED,
        };
    }

    private function __construct() {}
}
