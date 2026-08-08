<?php

namespace App\Domain\Federation\Enums;

enum CapabilityState: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
