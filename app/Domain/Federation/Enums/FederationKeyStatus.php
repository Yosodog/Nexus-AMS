<?php

namespace App\Domain\Federation\Enums;

enum FederationKeyStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Retiring = 'retiring';
    case Retired = 'retired';
    case Compromised = 'compromised';
}
