<?php

namespace App\Enums;

enum DiscordConnectionState: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
}
