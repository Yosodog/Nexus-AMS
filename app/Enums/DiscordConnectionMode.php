<?php

namespace App\Enums;

enum DiscordConnectionMode: string
{
    case Dedicated = 'dedicated';
    case OfficialShared = 'official-shared';
}
