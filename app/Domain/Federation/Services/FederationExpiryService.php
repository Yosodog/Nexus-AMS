<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionMembership;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationIdentityKey;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPublication;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use Illuminate\Support\Facades\DB;

final class FederationExpiryService
{
    public function __construct(
        private readonly FederationReceivedWarPlanService $receivedPlans,
        private readonly FederationHoldService $holds,
        private readonly FederationCoalitionService $coalitions,
        private readonly WarPlanPublicationService $publications,
    ) {}

    public function run(): void
    {
        $this->expirePendingWorkflows();
        $this->expireCoalitionsAndMemberships();
        $this->expireCapabilities();
        $this->expirePublications();
        $this->expireReceivedResources();
        $this->recoverStaleImports();
        $this->expireTransportMessages();
        $this->retireOldLocalKeys();
    }

    private function expirePendingWorkflows(): void
    {
        foreach ([FederationLinkInvitation::class, FederationCoalitionInvitation::class] as $modelClass) {
            $modelClass::query()
                ->whereIn('status', [
                    FederationWorkflowStatus::Pending->value,
                    FederationWorkflowStatus::Approved->value,
                ])
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => FederationWorkflowStatus::Expired->value,
                    'pending_key' => null,
                    'updated_at' => now(),
                ]);
        }

        FederationCoalitionProposal::query()
            ->where('status', FederationWorkflowStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => FederationWorkflowStatus::Expired->value,
                'pending_key' => null,
                'updated_at' => now(),
            ]);

        FederationLink::query()
            ->whereIn('status', [
                FederationLinkStatus::PendingLocal->value,
                FederationLinkStatus::PendingRemote->value,
            ])
            ->whereNull('active_at')
            ->whereDoesntHave('invitations', fn ($query) => $query
                ->whereIn('status', [
                    FederationWorkflowStatus::Pending->value,
                    FederationWorkflowStatus::Approved->value,
                ])
                ->where('expires_at', '>', now()))
            ->update([
                'status' => FederationLinkStatus::Expired->value,
                'expired_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function expireCoalitionsAndMemberships(): void
    {
        $coalitions = FederationCoalition::query()
            ->where('status', CoalitionStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($coalitions as $coalition) {
            $this->coalitions->expire($coalition);
            $this->receivedPlans->invalidateCoalition($coalition->id, 'coalition_expired');
        }

        $memberships = FederationCoalitionMembership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($memberships as $membership) {
            $membership->forceFill(['status' => MembershipStatus::Expired])->save();
            $this->receivedPlans->invalidateCoalition(
                $membership->federation_coalition_id,
                'coalition_membership_expired'
            );
        }
    }

    private function expireCapabilities(): void
    {
        $capabilities = FederationCapability::query()
            ->where('state', CapabilityState::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($capabilities as $capability) {
            $hasNewer = FederationCapability::query()
                ->where('issuer_installation_id', $capability->issuer_installation_id)
                ->where('peer_installation_id', $capability->peer_installation_id)
                ->where('federation_coalition_id', $capability->federation_coalition_id)
                ->where('resource_type', $capability->resource_type->value)
                ->where('direction', $capability->direction->value)
                ->where('revision', '>', $capability->revision)
                ->exists();
            $capability->forceFill(['state' => CapabilityState::Expired])->save();

            if (! $hasNewer) {
                $invalidatesReceiving = ($capability->is_local
                    && $capability->direction->value === 'inbound')
                    || (! $capability->is_local && $capability->direction->value === 'outbound');

                if ($invalidatesReceiving) {
                    $sourceInstallationId = $capability->is_local
                        ? $capability->peer_installation_id
                        : $capability->issuer_installation_id;
                    $this->receivedPlans->invalidateCoalition(
                        $capability->federation_coalition_id,
                        'capability_expired',
                        $sourceInstallationId,
                    );
                } else {
                    $recipientInstallationId = $capability->is_local
                        ? $capability->peer_installation_id
                        : $capability->issuer_installation_id;
                    $this->publications->revokeForRecipientScope(
                        $capability->federation_coalition_id,
                        $recipientInstallationId,
                        'capability_expired',
                    );
                }
            }
        }
    }

    private function expirePublications(): void
    {
        $publicationIds = FederationPublication::query()
            ->whereIn('status', [
                PublicationStatus::Published->value,
                PublicationStatus::PartiallyRevoked->value,
            ])
            ->where('expires_at', '<=', now())
            ->pluck('id');

        if ($publicationIds->isEmpty()) {
            return;
        }

        FederationPublication::query()->whereIn('id', $publicationIds)->update([
            'status' => PublicationStatus::Expired->value,
            'updated_at' => now(),
        ]);
        FederationPublicationDelivery::query()
            ->whereHas('version', fn ($query) => $query->whereIn('federation_publication_id', $publicationIds))
            ->update([
                'canonical_payload' => null,
                'updated_at' => now(),
            ]);
    }

    private function expireReceivedResources(): void
    {
        $resources = FederationReceivedResource::query()
            ->whereNotIn('state', [
                ReceivedResourceState::Revoked->value,
                ReceivedResourceState::Expired->value,
            ])
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($resources as $resource) {
            DB::transaction(function () use ($resource): void {
                $locked = FederationReceivedResource::query()->lockForUpdate()->findOrFail($resource->id);
                $locked->versions()->update([
                    'canonical_payload' => null,
                    'payload_purged_at' => now(),
                    'updated_at' => now(),
                ]);
                $locked->forceFill([
                    'state' => ReceivedResourceState::Expired,
                    'payload_purged_at' => now(),
                ])->save();
            }, attempts: 5);
            $this->holds->placeForResource($resource, 'resource_expired');
        }
    }

    private function expireTransportMessages(): void
    {
        FederationInboxMessage::query()
            ->whereIn('status', [InboxStatus::Accepted->value, InboxStatus::Processing->value])
            ->where('expires_at', '<=', now())
            ->update([
                'status' => InboxStatus::Quarantined->value,
                'safe_error_code' => 'message_expired',
                'envelope_body' => null,
                'decrypted_payload' => null,
                'quarantined_at' => now(),
                'updated_at' => now(),
            ]);
        FederationOutboxMessage::query()
            ->whereIn('status', [
                OutboxStatus::Pending->value,
                OutboxStatus::Delivering->value,
                OutboxStatus::TransportAccepted->value,
            ])
            ->where('expires_at', '<=', now())
            ->update([
                'status' => OutboxStatus::Expired->value,
                'safe_error_code' => 'message_expired',
                'envelope_body' => null,
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function recoverStaleImports(): void
    {
        FederationReceivedVersion::query()
            ->where('import_state', ImportState::Importing->value)
            ->where('updated_at', '<=', now()->subMinutes(15))
            ->update([
                'import_state' => ImportState::Failed->value,
                'safe_error_code' => 'import_interrupted',
                'updated_at' => now(),
            ]);
    }

    private function retireOldLocalKeys(): void
    {
        FederationIdentityKey::query()
            ->where('status', FederationKeyStatus::Retiring->value)
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->each(function (FederationIdentityKey $key): void {
                $key->forceFill([
                    'status' => FederationKeyStatus::Retired,
                    'signing_private_key' => null,
                    'box_private_key' => null,
                    'retired_at' => now(),
                ])->save();
            });
    }
}
