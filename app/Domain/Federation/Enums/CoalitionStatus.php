<?php

namespace App\Domain\Federation\Enums;

enum CoalitionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Dissolved = 'dissolved';
}
