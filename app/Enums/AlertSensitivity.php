<?php

namespace App\Enums;

enum AlertSensitivity: string
{
    case Public = 'public';
    case Member = 'member';
    case Internal = 'internal';
    case Restricted = 'restricted';
}
