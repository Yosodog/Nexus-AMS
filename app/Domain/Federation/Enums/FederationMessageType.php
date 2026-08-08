<?php

namespace App\Domain\Federation\Enums;

enum FederationMessageType: string
{
    case LinkRequest = 'link.request';
    case LinkAcceptance = 'link.acceptance';
    case LinkActivation = 'link.activation';
    case KeyRotation = 'installation.key_rotation';
    case EndpointChange = 'installation.endpoint_change';
    case LinkSuspensionNotice = 'link.suspension_notice';
    case CapabilityManifest = 'capability.manifest';
    case CoalitionInvitation = 'coalition.invitation';
    case CoalitionProposal = 'coalition.proposal';
    case CoalitionManifest = 'coalition.manifest';
    case CoalitionDissolved = 'coalition.dissolved';
    case ResourcePublished = 'resource.published';
    case ResourceUpdated = 'resource.updated';
    case ResourceAcknowledged = 'resource.acknowledged';
    case ResourceAccessRevoked = 'resource.access_revoked';
    case ResourceRevoked = 'resource.revoked';
    case DeliveryReceived = 'delivery.received';
    case ReconciliationManifest = 'reconciliation.manifest';

    public function isHandshake(): bool
    {
        return in_array($this, [self::LinkRequest, self::LinkAcceptance, self::LinkActivation], true);
    }

    public function isAllowedWhileSuspended(): bool
    {
        return in_array($this, [
            self::LinkSuspensionNotice,
            self::KeyRotation,
            self::EndpointChange,
            self::ResourceAccessRevoked,
            self::ResourceRevoked,
            self::CoalitionDissolved,
            self::ReconciliationManifest,
            self::DeliveryReceived,
        ], true);
    }
}
