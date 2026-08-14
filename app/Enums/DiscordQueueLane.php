<?php

namespace App\Enums;

enum DiscordQueueLane: string
{
    case Alerts = 'alerts';
    case Digests = 'digests';
    case SideEffects = 'side_effects';
}
