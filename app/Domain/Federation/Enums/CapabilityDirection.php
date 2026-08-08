<?php

namespace App\Domain\Federation\Enums;

enum CapabilityDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
