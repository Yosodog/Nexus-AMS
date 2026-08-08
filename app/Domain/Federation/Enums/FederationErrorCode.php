<?php

namespace App\Domain\Federation\Enums;

enum FederationErrorCode: string
{
    case InvalidEnvelope = 'invalid_envelope';
    case InvalidSignature = 'invalid_signature';
    case RecipientMismatch = 'recipient_mismatch';
    case MessageReplayed = 'message_replayed';
    case MessageExpired = 'message_expired';
    case UnknownPeer = 'unknown_peer';
    case LinkInactive = 'link_inactive';
    case CoalitionInactive = 'coalition_inactive';
    case MembershipRequired = 'membership_required';
    case CapabilityDenied = 'capability_denied';
    case ProtocolUnsupported = 'protocol_unsupported';
    case SchemaUnsupported = 'schema_unsupported';
    case PayloadTooLarge = 'payload_too_large';
    case VersionConflict = 'version_conflict';
    case RateLimited = 'rate_limited';
    case TemporaryUnavailable = 'temporary_unavailable';
}
