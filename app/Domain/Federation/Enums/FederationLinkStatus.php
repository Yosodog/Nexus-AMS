<?php

namespace App\Domain\Federation\Enums;

enum FederationLinkStatus: string
{
    case PendingRemote = 'pending_remote';
    case PendingLocal = 'pending_local';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Revoked, self::Expired], true);
    }
}
