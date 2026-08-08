<?php

namespace App\Domain\Federation\Enums;

enum DeliveryState: string
{
    case Pending = 'pending';
    case TransportAccepted = 'transport_accepted';
    case Validated = 'validated';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Failed = 'failed';
}
