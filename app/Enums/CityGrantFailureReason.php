<?php

namespace App\Enums;

enum CityGrantFailureReason: string
{
    case Eligibility = 'eligibility';
    case Cooldown = 'cooldown';
    case PendingRequest = 'pending_request';
    case MissingAudit = 'missing_audit';
    case InsufficientData = 'insufficient_data';
    case PolicyLimit = 'policy_limit';
    case ExternalOutage = 'external_outage';
}
