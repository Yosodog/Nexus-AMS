<?php

namespace App\Domain\Federation\Protocol;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\StrictJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

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
            FederationMessageType::LinkSuspensionNotice => $this->linkSuspension($payload),
            FederationMessageType::CapabilityManifest => $this->capabilityManifest($payload),
            FederationMessageType::CoalitionInvitation => $this->coalitionInvitation($payload),
            FederationMessageType::CoalitionProposal => $this->coalitionProposal($payload),
            FederationMessageType::CoalitionManifest => $this->coalitionManifest($payload),
            FederationMessageType::CoalitionDissolved => $this->coalitionDissolved($payload),
            FederationMessageType::ResourcePublished,
            FederationMessageType::ResourceUpdated => WarPlanSnapshotV1::fromArray($payload),
            FederationMessageType::ResourceAcknowledged => $this->resourceAcknowledged($payload),
            FederationMessageType::ResourceAccessRevoked => $this->resourceRevoked($payload, true),
            FederationMessageType::ResourceRevoked => $this->resourceRevoked($payload, false),
            FederationMessageType::DeliveryReceived => $this->deliveryReceived($payload),
            FederationMessageType::ReconciliationManifest => $this->reconciliationManifest($payload),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function linkRequest(array $payload): void
    {
        $this->simple($payload, [
            'invitation_id', 'invitation_token', 'source_origin', 'source_display_name',
            'source_installation_id', 'source_ownership_epoch', 'source_key',
            'supported_protocol_versions', 'resource_schemas', 'expires_at',
        ], ['source_ownership_epoch'], [], ['source_key', 'supported_protocol_versions', 'resource_schemas']);
        $this->key($payload['source_key']);
        $this->versions($payload['supported_protocol_versions'], $payload['resource_schemas']);
        $this->ulids($payload, ['invitation_id', 'source_installation_id']);
        $this->token($payload['invitation_token']);
        $this->bounded($payload['source_origin'], 512);
        $this->bounded($payload['source_display_name'], 255);
        $this->timestamp($payload['expires_at']);

        if ($payload['source_ownership_epoch'] < 1) {
            throw new InvalidArgumentException('Link request ownership epoch must be positive.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function linkAcceptance(array $payload): void
    {
        $this->simple($payload, [
            'invitation_id', 'invitation_token', 'source_installation_id', 'recipient_origin',
            'recipient_display_name', 'recipient_installation_id', 'recipient_ownership_epoch',
            'recipient_key', 'supported_protocol_versions', 'resource_schemas', 'accepted_at',
        ], ['recipient_ownership_epoch'], [], ['recipient_key', 'supported_protocol_versions', 'resource_schemas']);
        $this->key($payload['recipient_key']);
        $this->versions($payload['supported_protocol_versions'], $payload['resource_schemas']);
        $this->ulids($payload, ['invitation_id', 'source_installation_id', 'recipient_installation_id']);
        $this->token($payload['invitation_token']);
        $this->bounded($payload['recipient_origin'], 512);
        $this->bounded($payload['recipient_display_name'], 255);
        $this->timestamp($payload['accepted_at']);

        if ($payload['recipient_ownership_epoch'] < 1) {
            throw new InvalidArgumentException('Link acceptance ownership epoch must be positive.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function linkActivation(array $payload): void
    {
        $this->simple($payload, [
            'invitation_id', 'invitation_token', 'link_id', 'source_installation_id',
            'recipient_installation_id', 'activated_at', 'acknowledgment',
        ], [], [], [], ['acknowledgment']);
        $this->ulids($payload, [
            'invitation_id', 'link_id', 'source_installation_id', 'recipient_installation_id',
        ]);
        $this->token($payload['invitation_token']);
        $this->timestamp($payload['activated_at']);
    }

    /** @param  array<string, mixed>  $payload */
    private function keyRotation(array $payload): void
    {
        $this->simple($payload, [
            'installation_id', 'ownership_epoch', 'old_key_id', 'new_key', 'statement',
            'old_signature', 'new_signature', 'issued_at',
        ], ['ownership_epoch'], [], ['new_key']);
        $this->key($payload['new_key']);
        $this->ulids($payload, ['installation_id', 'old_key_id']);
        $this->timestamp($payload['issued_at']);
        $this->bounded($payload['statement'], 32768);
        $this->bounded($payload['old_signature'], 128, allowEmpty: true);
        $this->bounded($payload['new_signature'], 128);

        if ($payload['ownership_epoch'] < 1) {
            throw new InvalidArgumentException('Key rotation ownership epoch must be positive.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function endpointChange(array $payload): void
    {
        $this->simple($payload, [
            'proposal_id', 'installation_id', 'old_origin', 'new_origin', 'ownership_epoch',
            'status', 'issued_at', 'expires_at',
        ], ['ownership_epoch']);
        $this->ulids($payload, ['proposal_id', 'installation_id']);
        $this->bounded($payload['old_origin'], 512);
        $this->bounded($payload['new_origin'], 512);
        $this->timestamp($payload['issued_at']);
        $this->timestamp($payload['expires_at']);

        if ($payload['ownership_epoch'] < 1
            || ! in_array($payload['status'], ['proposed', 'approved', 'rejected', 'activated'], true)) {
            throw new InvalidArgumentException('Endpoint change metadata is invalid.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function linkSuspension(array $payload): void
    {
        $this->simple($payload, ['link_id', 'reason_code', 'suspended_at']);
        $this->ulids($payload, ['link_id']);
        $this->bounded($payload['reason_code'], 64);
        $this->timestamp($payload['suspended_at']);
    }

    /** @param  array<string, mixed>  $payload */
    private function coalitionInvitation(array $payload): void
    {
        $this->simple($payload, [
            'action', 'invitation_id', 'invitation_token', 'coalition_id', 'coalition_name',
            'coordinator_installation_id', 'role', 'roster_revision', 'expires_at', 'acted_at',
        ], ['roster_revision'], ['acted_at']);
        $this->ulids($payload, ['invitation_id', 'coalition_id', 'coordinator_installation_id']);
        $this->token($payload['invitation_token']);
        $this->bounded($payload['coalition_name'], 255);
        $this->timestamp($payload['expires_at']);

        if ($payload['acted_at'] !== null) {
            $this->timestamp($payload['acted_at']);
        }

        if (! in_array($payload['action'], ['invite', 'accept'], true)
            || CoalitionRole::tryFrom($payload['role']) === null
            || $payload['role'] === CoalitionRole::Coordinator->value
            || $payload['roster_revision'] < 1) {
            throw new InvalidArgumentException('Coalition invitation metadata is invalid.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function coalitionDissolved(array $payload): void
    {
        $this->simple($payload, ['coalition_id', 'revision', 'dissolved_at', 'manifest_hash'], ['revision']);
        $this->ulids($payload, ['coalition_id']);
        $this->positive($payload['revision'], 'Coalition dissolution revision');
        $this->timestamp($payload['dissolved_at']);
        $this->hash($payload['manifest_hash']);
    }

    /** @param  array<string, mixed>  $payload */
    private function resourceAcknowledged(array $payload): void
    {
        $this->simple($payload, [
            'publication_id', 'version_id', 'version', 'revision', 'disposition', 'acknowledged_at',
        ], ['version', 'revision']);
        $this->ulids($payload, ['publication_id', 'version_id']);
        $this->positive($payload['version'], 'Resource version');
        $this->positive($payload['revision'], 'Resource revision');
        $this->timestamp($payload['acknowledged_at']);

        if (! in_array($payload['disposition'], [
            ReceivedDisposition::Accepted->value,
            ReceivedDisposition::Rejected->value,
        ], true)) {
            throw new InvalidArgumentException('Resource disposition is invalid.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function resourceRevoked(array $payload, bool $accessOnly): void
    {
        $fields = $accessOnly
            ? ['publication_id', 'recipient_installation_id', 'revision', 'reason_code', 'revoked_at']
            : ['publication_id', 'revision', 'reason_code', 'revoked_at'];
        $this->simple($payload, $fields, ['revision']);
        $this->ulids($payload, $accessOnly
            ? ['publication_id', 'recipient_installation_id']
            : ['publication_id']);
        $this->positive($payload['revision'], 'Resource revocation revision');
        $this->bounded($payload['reason_code'], 64);
        $this->timestamp($payload['revoked_at']);
    }

    /** @param  array<string, mixed>  $payload */
    private function deliveryReceived(array $payload): void
    {
        $this->simple($payload, ['original_message_id', 'received_at']);
        $this->ulids($payload, ['original_message_id']);
        $this->timestamp($payload['received_at']);
    }

    /** @param  array<string, mixed>  $payload */
    private function capabilityManifest(array $payload): void
    {
        $this->simple($payload, ['issuer_installation_id', 'generated_at', 'statements'], [], [], ['statements']);
        $this->ulids($payload, ['issuer_installation_id']);
        $this->timestamp($payload['generated_at']);
        $statements = $this->list($payload['statements'], 1000);

        foreach ($statements as $statement) {
            $this->simple($statement, [
                'peer_installation_id', 'coalition_id', 'resource_type', 'direction',
                'revision', 'state', 'expires_at', 'statement_hash',
            ], ['revision'], ['expires_at']);
            $this->ulids($statement, ['peer_installation_id', 'coalition_id']);
            $this->positive($statement['revision'], 'Capability revision');
            $this->hash($statement['statement_hash']);

            if ($statement['resource_type'] !== 'milcom.war-plan-snapshot'
                || CapabilityDirection::tryFrom($statement['direction']) === null
                || CapabilityState::tryFrom($statement['state']) === null) {
                throw new InvalidArgumentException('Capability statement metadata is invalid.');
            }

            if ($statement['expires_at'] !== null) {
                $this->timestamp($statement['expires_at']);
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function coalitionManifest(array $payload): void
    {
        $this->coalitionManifestCore($payload, true);
    }

    /** @param  array<string, mixed>  $payload */
    private function coalitionProposal(array $payload): void
    {
        $fields = [
            'proposal_id', 'coalition_id', 'proposal_type', 'workflow_key',
            'target_installation_id', 'requested_role', 'base_roster_revision',
            'payload_hash', 'expires_at',
        ];
        StrictJson::rejectUnknown($payload, [...$fields, 'transfer_manifest', 'transfer_approval']);
        StrictJson::requireProperties($payload, $fields);
        $this->simple(
            array_intersect_key($payload, array_flip($fields)),
            $fields,
            ['base_roster_revision'],
            ['target_installation_id', 'requested_role'],
        );
        $this->ulids($payload, ['proposal_id', 'coalition_id']);
        $this->positive($payload['base_roster_revision'], 'Coalition proposal base revision');
        $this->bounded($payload['workflow_key'], 96);
        $this->hash($payload['payload_hash']);
        $this->timestamp($payload['expires_at']);

        if ($payload['target_installation_id'] !== null) {
            $this->ulids($payload, ['target_installation_id']);
        }

        if ($payload['requested_role'] !== null
            && CoalitionRole::tryFrom($payload['requested_role']) === null) {
            throw new InvalidArgumentException('Coalition proposal role is invalid.');
        }

        if (preg_match(
            '/^(?:member\.(?:add|remove|role)|coordinator\.transfer)(?:\.(?:accepted|approved|rejected|completed))?$/D',
            $payload['proposal_type'],
        ) !== 1) {
            throw new InvalidArgumentException('Coalition proposal type is invalid.');
        }

        $isTransferApproval = $payload['proposal_type'] === 'coordinator.transfer.approved';
        $hasTransferFields = array_key_exists('transfer_manifest', $payload)
            || array_key_exists('transfer_approval', $payload);

        if ($isTransferApproval !== $hasTransferFields
            || ($hasTransferFields && (! array_key_exists('transfer_manifest', $payload)
                || ! array_key_exists('transfer_approval', $payload)))) {
            throw new InvalidArgumentException('Coordinator transfer approval proof is incomplete.');
        }

        if (! $hasTransferFields) {
            return;
        }

        if (! is_array($payload['transfer_manifest']) || array_is_list($payload['transfer_manifest'])) {
            throw new InvalidArgumentException('Coordinator transfer manifest must be an object.');
        }

        $this->coalitionManifestCore($payload['transfer_manifest'], false);
        $approval = $payload['transfer_approval'];

        if (! is_array($approval) || array_is_list($approval)) {
            throw new InvalidArgumentException('Coordinator transfer approval must be an object.');
        }

        $this->simple($approval, [
            'statement', 'coordinator_key_id', 'coordinator_signature',
        ], [], [], ['statement']);
        $this->ulids($approval, ['coordinator_key_id']);
        $this->bounded($approval['coordinator_signature'], 128);
        $this->transferStatement($approval['statement']);
    }

    /** @param  array<string, mixed>  $payload */
    private function coalitionManifestCore(array $payload, bool $allowTransferProof): void
    {
        $fields = [
            'coalition_id', 'name', 'coordinator_installation_id', 'revision', 'status',
            'expires_at', 'manifest_hash', 'members',
        ];
        StrictJson::rejectUnknown($payload, $allowTransferProof ? [...$fields, 'transfer_proof'] : $fields);
        StrictJson::requireProperties($payload, $fields);
        $this->simple(
            array_intersect_key($payload, array_flip($fields)),
            $fields,
            ['revision'],
            ['expires_at'],
            ['members'],
        );
        $this->ulids($payload, ['coalition_id', 'coordinator_installation_id']);
        $this->positive($payload['revision'], 'Coalition roster revision');
        $this->bounded($payload['name'], 255);
        $this->hash($payload['manifest_hash']);

        if (CoalitionStatus::tryFrom($payload['status']) === null) {
            throw new InvalidArgumentException('Coalition status is invalid.');
        }

        if ($payload['expires_at'] !== null) {
            $this->timestamp($payload['expires_at']);
        }

        foreach ($this->list($payload['members'], 1000) as $member) {
            $this->simple($member, [
                'installation_id', 'role', 'status', 'joined_at', 'expires_at', 'removed_at',
            ], [], ['joined_at', 'expires_at', 'removed_at']);
            $this->ulids($member, ['installation_id']);

            if (CoalitionRole::tryFrom($member['role']) === null
                || MembershipStatus::tryFrom($member['status']) === null) {
                throw new InvalidArgumentException('Coalition member role or status is invalid.');
            }

            foreach (['joined_at', 'expires_at', 'removed_at'] as $timestamp) {
                if ($member[$timestamp] !== null) {
                    $this->timestamp($member[$timestamp]);
                }
            }
        }

        if (! array_key_exists('transfer_proof', $payload)) {
            return;
        }

        $proof = $payload['transfer_proof'];

        if (! is_array($proof) || array_is_list($proof)) {
            throw new InvalidArgumentException('Coordinator transfer proof must be an object.');
        }

        $this->simple($proof, [
            'statement', 'previous_coordinator_key_id', 'previous_coordinator_signature',
            'new_coordinator_key_id', 'new_coordinator_signature',
        ], [], [], ['statement']);
        $this->ulids($proof, ['previous_coordinator_key_id', 'new_coordinator_key_id']);
        $this->bounded($proof['previous_coordinator_signature'], 128);
        $this->bounded($proof['new_coordinator_signature'], 128);
        $this->transferStatement($proof['statement']);
    }

    private function transferStatement(mixed $statement): void
    {
        if (! is_array($statement) || array_is_list($statement)) {
            throw new InvalidArgumentException('Coordinator transfer statement must be an object.');
        }

        $this->simple($statement, [
            'proposal_id', 'coalition_id', 'base_roster_revision', 'base_roster_hash',
            'previous_coordinator_installation_id', 'new_coordinator_installation_id',
            'manifest_hash',
        ], ['base_roster_revision']);
        $this->ulids($statement, [
            'proposal_id', 'coalition_id', 'previous_coordinator_installation_id',
            'new_coordinator_installation_id',
        ]);
        $this->positive($statement['base_roster_revision'], 'Coordinator transfer base revision');
        $this->hash($statement['base_roster_hash']);
        $this->hash($statement['manifest_hash']);
    }

    /** @param  array<string, mixed>  $payload */
    private function reconciliationManifest(array $payload): void
    {
        $this->simple($payload, ['generated_at', 'resources'], [], [], ['resources']);
        $this->timestamp($payload['generated_at']);

        foreach ($this->list($payload['resources'], 1000) as $resource) {
            $this->simple($resource, [
                'resource_type', 'resource_id', 'highest_revision', 'hash', 'state',
                'expires_at', 'missing_versions',
            ], ['highest_revision'], ['expires_at'], ['missing_versions']);
            $this->ulids($resource, ['resource_id']);
            $this->positive($resource['highest_revision'], 'Reconciliation resource revision');
            $this->hash($resource['hash']);

            if ($resource['resource_type'] !== 'milcom.war-plan-snapshot'
                || ! in_array($resource['state'], [
                    'published', 'partially_revoked', 'pending_review', 'accepted', 'rejected',
                    'revoked', 'expired',
                ], true)) {
                throw new InvalidArgumentException('Reconciliation resource metadata is invalid.');
            }

            if ($resource['expires_at'] !== null) {
                $this->timestamp($resource['expires_at']);
            }

            if (! is_array($resource['missing_versions']) || ! array_is_list($resource['missing_versions'])) {
                throw new InvalidArgumentException('Reconciliation versions must be a list.');
            }

            if (count($resource['missing_versions']) > 500
                || count($resource['missing_versions']) !== count(array_unique($resource['missing_versions']))) {
                throw new InvalidArgumentException('Reconciliation versions exceed protocol limits.');
            }

            foreach ($resource['missing_versions'] as $version) {
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
        $this->ulids($key, ['key_id']);
        $this->positive($key['generation'], 'Federation key generation');

        try {
            if (strlen(Base64Url::decode($key['signing_public_key'])) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen(Base64Url::decode($key['box_public_key'])) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('Federation public key size is invalid.');
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('Federation public key encoding is invalid.');
        }

        $this->fingerprint($key['signing_fingerprint']);
        $this->fingerprint($key['box_fingerprint']);
    }

    private function versions(mixed $protocols, mixed $resources): void
    {
        if (! is_array($protocols) || ! array_is_list($protocols) || $protocols === [] || count($protocols) > 10) {
            throw new InvalidArgumentException('Federation protocol versions must be a non-empty list.');
        }

        foreach ($protocols as $version) {
            if (! is_string($version) || preg_match('/^[1-9][0-9]*\.[0-9]+$/D', $version) !== 1) {
                throw new InvalidArgumentException('Federation protocol versions are invalid.');
            }
        }

        if (! is_array($resources) || array_is_list($resources) || count($resources) > 25) {
            throw new InvalidArgumentException('Federation resource schemas must be an object.');
        }

        foreach ($resources as $resource => $versions) {
            if (! is_string($resource) || $resource === '' || strlen($resource) > 96
                || ! is_array($versions) || ! array_is_list($versions) || count($versions) > 10) {
                throw new InvalidArgumentException('Federation resource schemas are invalid.');
            }

            foreach ($versions as $version) {
                if (! is_string($version) || preg_match('/^[1-9][0-9]*\.[0-9]+$/D', $version) !== 1) {
                    throw new InvalidArgumentException('Federation resource schema versions are invalid.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @param  list<string>  $integerFields
     * @param  list<string>  $nullableStringFields
     * @param  list<string>  $objectFields
     * @param  list<string>  $booleanFields
     */
    private function simple(
        array $payload,
        array $fields,
        array $integerFields = [],
        array $nullableStringFields = [],
        array $objectFields = [],
        array $booleanFields = [],
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

            if (in_array($field, $booleanFields, true)) {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException('Federation payload has invalid boolean fields.');
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
    private function list(mixed $value, int $maximum = 1000): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException('Federation payload field must be a list.');
        }

        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('Federation payload list items must be objects.');
            }
        }

        return $value;
    }

    /** @param  array<string, mixed>  $payload */
    private function ulids(array $payload, array $fields): void
    {
        foreach ($fields as $field) {
            if (! is_string($payload[$field] ?? null) || ! Str::isUlid($payload[$field])) {
                throw new InvalidArgumentException('Federation payload contains an invalid ULID.');
            }
        }
    }

    private function positive(mixed $value, string $label): void
    {
        if (! is_int($value)
            || $value < 1
            || $value > (int) config('federation.limits.max_resource_revision', 1000000000)) {
            throw new InvalidArgumentException($label.' must be positive.');
        }
    }

    private function bounded(mixed $value, int $maximum, bool $allowEmpty = false): void
    {
        if (! is_string($value)
            || (! $allowEmpty && $value === '')
            || Str::length($value) > $maximum) {
            throw new InvalidArgumentException('Federation payload string exceeds protocol limits.');
        }
    }

    private function timestamp(mixed $value): void
    {
        if (! is_string($value) || strlen($value) > 64) {
            throw new InvalidArgumentException('Federation timestamp is invalid.');
        }

        try {
            CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new InvalidArgumentException('Federation timestamp is invalid.');
        }
    }

    private function hash(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Federation hash is invalid.');
        }
    }

    private function fingerprint(mixed $value): void
    {
        if (! is_string($value)
            || preg_match('/^[A-F0-9]{4}(?:-[A-F0-9]{4}){15}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Federation fingerprint is invalid.');
        }
    }

    private function token(mixed $value): void
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Federation invitation token is invalid.');
        }

        try {
            if (strlen(Base64Url::decode($value)) !== 32) {
                throw new InvalidArgumentException('Federation invitation token is invalid.');
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('Federation invitation token is invalid.');
        }
    }
}
