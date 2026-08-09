<?php

namespace App\Domain\Federation\Enums;

enum FederationEndpointChangeStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Activated = 'activated';
    case Rejected = 'rejected';
}
