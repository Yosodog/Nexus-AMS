<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantEventRejectionReason: string
{
    case MalformedEnvelope = 'malformed_envelope';
    case AuthenticationFailed = 'authentication_failed';
    case ExpiredEnvelope = 'expired_envelope';
    case WrongTenant = 'wrong_tenant';
    case UnsupportedContract = 'unsupported_contract';
    case UnsupportedEventType = 'unsupported_event_type';
    case InvalidEventBody = 'invalid_event_body';
    case FutureEvent = 'future_event';
    case RoutingMismatch = 'routing_mismatch';
}
