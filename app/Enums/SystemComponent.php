<?php

namespace App\Enums;

enum SystemComponent: string
{
    case Core = 'nexus-core';
    case Subs = 'nexus-subs';
    case Discord = 'nexus-discord';

    public function label(): string
    {
        return match ($this) {
            self::Core => 'Nexus Core',
            self::Subs => 'Nexus Subs',
            self::Discord => 'Nexus Discord',
        };
    }

    public function optional(): bool
    {
        return $this !== self::Core;
    }
}
