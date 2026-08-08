<?php

namespace App\Enums;

enum AlertDeliveryStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Suppressed = 'suppressed';
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Undeliverable = 'undeliverable';
    case Failed = 'failed';
    case Quarantined = 'quarantined';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Suppressed,
            self::Delivered,
            self::Undeliverable,
            self::Failed,
            self::Quarantined,
            self::Cancelled,
            self::Superseded,
        ], true);
    }
}
