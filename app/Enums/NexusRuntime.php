<?php

namespace App\Enums;

enum NexusRuntime: string
{
    case Standalone = 'standalone';
    case HostedTenant = 'hosted-tenant';
    case WorldWriter = 'world-writer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
