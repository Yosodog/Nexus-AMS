<?php

namespace App\Domain\Federation\Enums;

enum FederationKeyRotationPhase: string
{
    case Proposed = 'proposed';
    case Acknowledged = 'acknowledged';
    case Activated = 'activated';
    case Reapproved = 'reapproved';
}
