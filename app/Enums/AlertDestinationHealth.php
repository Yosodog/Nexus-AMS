<?php

namespace App\Enums;

enum AlertDestinationHealth: string
{
    case Unverified = 'unverified';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
