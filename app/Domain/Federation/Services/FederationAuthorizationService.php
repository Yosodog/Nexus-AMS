<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationLink;

final class FederationAuthorizationService
{
    public function assertCanPublish(FederationCoalition $coalition, FederationLink $link): void
    {
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();
        $this->assertCommonScope($coalition, $link, $identity->id);
        $this->assertCurrentCapability(
            issuerId: $identity->id,
            peerId: $link->remote_installation_id,
            coalition: $coalition,
            direction: CapabilityDirection::Outbound,
        );
        $this->assertCurrentCapability(
            issuerId: $link->remote_installation_id,
            peerId: $identity->id,
            coalition: $coalition,
            direction: CapabilityDirection::Inbound,
        );
    }

    public function assertCanReceive(FederationCoalition $coalition, FederationLink $link): void
    {
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();
        $this->assertCommonScope($coalition, $link, $identity->id);
        $this->assertCurrentCapability(
            issuerId: $identity->id,
            peerId: $link->remote_installation_id,
            coalition: $coalition,
            direction: CapabilityDirection::Inbound,
        );
        $this->assertCurrentCapability(
            issuerId: $link->remote_installation_id,
            peerId: $identity->id,
            coalition: $coalition,
            direction: CapabilityDirection::Outbound,
        );
    }

    private function assertCommonScope(
        FederationCoalition $coalition,
        FederationLink $link,
        string $localInstallationId,
    ): void {
        if ($link->status !== FederationLinkStatus::Active) {
            throw new FederationProtocolException(FederationErrorCode::LinkInactive, 403);
        }

        if ($coalition->status !== CoalitionStatus::Active
            || ($coalition->expires_at !== null && $coalition->expires_at->isPast())) {
            throw new FederationProtocolException(FederationErrorCode::CoalitionInactive, 403);
        }

        $members = $coalition->memberships()
            ->whereIn('installation_id', [$localInstallationId, $link->remote_installation_id])
            ->where('status', MembershipStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('installation_id');

        if ($members->unique()->count() !== 2) {
            throw new FederationProtocolException(FederationErrorCode::MembershipRequired, 403);
        }
    }

    private function assertCurrentCapability(
        string $issuerId,
        string $peerId,
        FederationCoalition $coalition,
        CapabilityDirection $direction,
    ): void {
        $capability = FederationCapability::query()
            ->where('issuer_installation_id', $issuerId)
            ->where('peer_installation_id', $peerId)
            ->where('federation_coalition_id', $coalition->id)
            ->where('resource_type', FederationResourceType::WarPlanSnapshot->value)
            ->where('direction', $direction->value)
            ->latest('revision')
            ->first();

        if (! $capability instanceof FederationCapability
            || $capability->state !== CapabilityState::Active
            || ($capability->expires_at !== null && $capability->expires_at->isPast())) {
            throw new FederationProtocolException(FederationErrorCode::CapabilityDenied, 403);
        }
    }
}
