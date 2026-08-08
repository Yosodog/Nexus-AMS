<?php

namespace App\Enums;

enum AlertSubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
    case SuspendedIneligible = 'suspended_ineligible';
    case DestinationUnhealthy = 'destination_unhealthy';
}
