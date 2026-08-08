<?php

namespace App\Domain\Federation\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case TransportAccepted = 'transport_accepted';
    case Validated = 'validated';
    case Failed = 'failed';
    case Expired = 'expired';
}
