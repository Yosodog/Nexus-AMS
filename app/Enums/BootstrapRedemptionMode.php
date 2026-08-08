<?php

declare(strict_types=1);

namespace App\Enums;

enum BootstrapRedemptionMode: string
{
    case Created = 'created';
    case Linked = 'linked';
}
