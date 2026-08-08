<?php

namespace App\Enums;

enum AlertBatchStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Undeliverable = 'undeliverable';
    case Failed = 'failed';
    case Quarantined = 'quarantined';
    case Cancelled = 'cancelled';
}
