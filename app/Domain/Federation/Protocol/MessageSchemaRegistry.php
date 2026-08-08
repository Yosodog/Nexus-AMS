<?php

namespace App\Domain\Federation\Protocol;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Support\StrictJson;
use InvalidArgumentException;

final class MessageSchemaRegistry
{
    /** @param  array<string, mixed>  $payload */
    public function validate(FederationMessageType $type, array $payload): void
    {
        match ($type) {
            FederationMessageType::LinkRequest => $this->linkRequest($payload),
            FederationMessageType::LinkAcceptance => $this->linkAcceptance($payload),
            FederationMessageType::LinkActivation => $this->linkActivation($payload),
            FederationMessageType::KeyRotation => $this->keyRotation($payload),
            FederationMessageType::EndpointChange => $this->endpointChange($payload),
            FederationMessageType::LinkSuspensionNotice => $this->simple($payload, [
                'link_id', 'reason_code', 'suspended_at',
            ]),
            FederationMessageType::CapabilityManifest => $this->capabilityManifest($payload),
            FederationMessageType::CoalitionInvitation => $this->simple($payload, [
                'action', 'invitation_id', 'invitation_token', 'coalition_id', 'coalition_name',
                'coordinator_installation_id', 'role', 'roster_revision', 'expires_at', 'acted_at',
            ], ['roster_revision'], ['acted_at']),
            FederationMessageType::CoalitionProposal => $this->simple($payload, [
                'proposal_id', 'coalition_id', 'proposal_type', 'workflow_key',
                'target_installation_id', 'requested_role', 'base_roster_revision',
                'payload_hash', 'expires_at',
            ], ['base_roster_revision'], ['target_installation_id', 'requested_role']),
            FederationMessageType::CoalitionManifest => $this->coalitionManifest($payload),
            FederationMessageType::CoalitionDissolved => $this->simple($payload, [
                'coalition_id', 'revision', 'dissolved_at', 'manifest_hash',
            ], ['revision']),
            FederationMessageType::ResourcePublished,
            FederationMessageType::ResourceUpdated => WarPlanSnapshotV1::fromArray($payload),
            FederationMessageType::ResourceAcknowledged => $this->simple($payload, [
                'publication_id', 'version_id', 'version', 'revision', 'disposition', 'acknowledged_at',
            ], ['version', 'revision']),
            FederationMessageType::ResourceAccessRevoked => $this->simple($payload, [
                'publication_id', 'recipient_installation_id', 'revision', 'reason_code', 'revoked_at',
            ], ['revision']),
            FederationMessageType::ResourceRevoked => $this->simple($payload, [
                'publication_id', 'revision', 'reason_code', 'revoked_at',
            ], ['revision']),
            FederationMessageType::DeliveryReceived => $this->simple($payload, [
                'original_message_id', 'received_at',
            ]),
            FederationMessageType::ReconciliationManifest => $this->reconciliationManifest($payload),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function linkRequest(array $payload): void
    {
        $this->simple($payload, [
            'invitation_id', 'invitation_token', 'source_origin', 'source_display_name',
            'source_installation_id', 'source_ownership_epoch', 'source_key', 'expires_at',
        ], ['source_ownership_epoch'], [], ['source_key']);
        $this->key($payload['source_key']);
    }

    /** @param  array<string, mixed>  $payload */
    private function linkAcceptance(array $payload): void
    {
        $this->simple($payload, [
            'invitation_id', 'invitation_token', 'source_installation_id', 'recipient_origin',
            'recipient_display_name', 'recipient_installation_id', 'recipient_ownership_epoch',
            'recipient_key', 'accepted_at',
        ], ['recipient_ownership_epoch'], [], ['recipient_key']);
        $this->key($payload['recipient_key']);
    }

    /** @param  array<string, mixed>  $payload */
    private function linkActivation(array $payload): void
    {
        $this->simple($payload, [
            'invitation_id', 'invitation_token', 'link_id', 'source_installation_id',
            'recipient_installation_id', 'activated_at', 'acknowledgment',
        ]);

        if (! is_bool($payload['acknowledgment'])) {
            throw new InvalidArgumentException('Link activation acknowledgment must be boolean.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function keyRotation(array $payload): void
    {
        $this->simple($payload, [
            'installation_id', 'ownership_epoch', 'old_key_id', 'new_key', 'statement',
            'old_signature', 'new_signature', 'issued_at',
        ], ['ownership_epoch'], [], ['new_key']);
        $this->key($payload['new_key']);
    }

    /** @param  array<string, mixed>  $payload */
    private function endpointChange(array $payload): void
    {
        $this->simple($payload, [
            'proposal_id', 'installation_id', 'old_origin', 'new_origin', 'ownership_epoch',
            'status', 'issued_at', 'expires_at',
        ], ['ownership_epoch']);
    }

    /** @param  array<string, mixed>  $payload */
    private function capabilityManifest(array $payload): void
    {
        $this->simple($payload, ['issuer_installation_id', 'generated_at', 'statements'], [], [], ['statements']);

        foreach ($this->list($payload['statements']) as $statement) {
            $this->simple($statement, [
                'peer_installation_id', 'coalition_id', 'resource_type', 'direction',
                'revision', 'state', 'expires_at', 'statement_hash',
            ], ['revision'], ['expires_at']);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function coalitionManifest(array $payload): void
    {
        $this->simple($payload, [
            'coalition_id', 'name', 'coordinator_installation_id', 'revision', 'status',
            'expires_at', 'manifest_hash', 'members',
        ], ['revision'], ['expires_at'], ['members']);

        foreach ($this->list($payload['members']) as $member) {
            $this->simple($member, [
                'installation_id', 'role', 'status', 'joined_at', 'expires_at', 'removed_at',
            ], [], ['joined_at', 'expires_at', 'removed_at']);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function reconciliationManifest(array $payload): void
    {
        $this->simple($payload, ['generated_at', 'resources'], [], [], ['resources']);

        foreach ($this->list($payload['resources']) as $resource) {
            $this->simple($resource, [
                'resource_type', 'resource_id', 'highest_revision', 'hash', 'state',
                'expires_at', 'missing_versions',
            ], ['highest_revision'], ['expires_at'], ['missing_versions']);

            foreach ($this->list($resource['missing_versions']) as $version) {
                if (! is_int($version) || $version < 1) {
                    throw new InvalidArgumentException('Reconciliation versions must be positive integers.');
                }
            }
        }
    }

    private function key(mixed $key): void
    {
        if (! is_array($key) || array_is_list($key)) {
            throw new InvalidArgumentException('Federation key must be an object.');
        }

        $this->simple($key, [
            'key_id', 'generation', 'signing_public_key', 'box_public_key',
            'signing_fingerprint', 'box_fingerprint',
        ], ['generation']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @param  list<string>  $integerFields
     * @param  list<string>  $nullableStringFields
     * @param  list<string>  $objectFields
     */
    private function simple(
        array $payload,
        array $fields,
        array $integerFields = [],
        array $nullableStringFields = [],
        array $objectFields = [],
    ): void {
        StrictJson::rejectUnknown($payload, $fields);
        StrictJson::requireProperties($payload, $fields);

        foreach ($fields as $field) {
            $value = $payload[$field];

            if (in_array($field, $integerFields, true)) {
                if (! is_int($value) || $value < 0) {
                    throw new InvalidArgumentException('Federation payload has invalid numeric fields.');
                }

                continue;
            }

            if (in_array($field, $objectFields, true)) {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('Federation payload has invalid object fields.');
                }

                continue;
            }

            if (in_array($field, $nullableStringFields, true) && $value === null) {
                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException('Federation payload has invalid string fields.');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Federation payload field must be a list.');
        }

        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('Federation payload list items must be objects.');
            }
        }

        return $value;
    }
}
