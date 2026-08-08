<?php

namespace App\Enums;

enum DiscordActorContextState: string
{
    case Unlinked = 'unlinked';
    case Ambiguous = 'ambiguous';
    case Disabled = 'disabled';
    case NexusUnverified = 'nexus_unverified';
    case NoNation = 'no_nation';
    case MfaRequired = 'mfa_required';
    case Ready = 'ready';
    case InstallationUnavailable = 'installation_unavailable';
}
