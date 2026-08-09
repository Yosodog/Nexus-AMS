<?php

namespace App\Domain\Federation\DTO;

use App\Domain\Federation\Support\StrictJson;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FederationDiscoveryDocument
{
    /**
     * @param  array<string, mixed>  $currentKey
     * @param  list<string>  $protocolVersions
     * @param  array<string, list<string>>  $resourceSchemas
     * @param  array<string, string>  $ingress
     * @param  array<string, int>  $sizeLimits
     */
    public function __construct(
        public string $installationId,
        public string $origin,
        public string $displayName,
        public int $ownershipEpoch,
        public array $currentKey,
        public array $protocolVersions,
        public array $resourceSchemas,
        public array $ingress,
        public array $sizeLimits,
    ) {}

    public static function fromJson(string $json): self
    {
        $data = StrictJson::decodeObject($json);
        $fields = [
            'installation_id', 'origin', 'display_name', 'ownership_epoch', 'current_key',
            'supported_protocol_versions', 'resource_schemas', 'ingress', 'size_limits',
        ];
        StrictJson::rejectUnknown($data, $fields);
        StrictJson::requireProperties($data, $fields);

        if (! is_string($data['installation_id'])
            || ! Str::isUlid($data['installation_id'])
            || ! is_string($data['origin'])
            || ! is_string($data['display_name'])
            || ! is_int($data['ownership_epoch'])
            || $data['ownership_epoch'] < 1
            || ! is_array($data['current_key'])
            || array_is_list($data['current_key'])
            || ! is_array($data['supported_protocol_versions'])
            || ! array_is_list($data['supported_protocol_versions'])
            || ! is_array($data['resource_schemas'])
            || array_is_list($data['resource_schemas'])
            || ! is_array($data['ingress'])
            || array_is_list($data['ingress'])
            || ! is_array($data['size_limits'])
            || array_is_list($data['size_limits'])) {
            throw new InvalidArgumentException('Discovery document has invalid field types.');
        }

        $keyFields = [
            'key_id', 'generation', 'signing_public_key', 'box_public_key',
            'signing_fingerprint', 'box_fingerprint',
        ];
        StrictJson::rejectUnknown($data['current_key'], $keyFields);
        StrictJson::requireProperties($data['current_key'], $keyFields);

        foreach ($keyFields as $field) {
            if ($field === 'generation') {
                if (! is_int($data['current_key'][$field]) || $data['current_key'][$field] < 1) {
                    throw new InvalidArgumentException('Discovery key generation is invalid.');
                }
            } elseif (! is_string($data['current_key'][$field])) {
                throw new InvalidArgumentException('Discovery key fields are invalid.');
            }
        }

        if (! Str::isUlid($data['current_key']['key_id'])
            || $data['supported_protocol_versions'] === []
            || count($data['supported_protocol_versions']) !== count(array_unique($data['supported_protocol_versions']))) {
            throw new InvalidArgumentException('Discovery key or protocol versions are invalid.');
        }

        foreach ($data['supported_protocol_versions'] as $version) {
            if (! is_string($version) || preg_match('/^[1-9][0-9]*\.[0-9]+$/D', $version) !== 1) {
                throw new InvalidArgumentException('Discovery protocol versions are invalid.');
            }
        }

        foreach ($data['resource_schemas'] as $resource => $versions) {
            if (! is_string($resource)
                || $resource === ''
                || ! is_array($versions)
                || ! array_is_list($versions)
                || $versions === []) {
                throw new InvalidArgumentException('Discovery resource schemas are invalid.');
            }

            foreach ($versions as $version) {
                if (! is_string($version) || preg_match('/^[1-9][0-9]*\.[0-9]+$/D', $version) !== 1) {
                    throw new InvalidArgumentException('Discovery resource schema versions are invalid.');
                }
            }
        }

        $expectedIngress = [
            'handshakes' => FederationEndpoint::Handshakes->value,
            'envelopes' => FederationEndpoint::Envelopes->value,
        ];

        if ($data['ingress'] !== $expectedIngress) {
            throw new InvalidArgumentException('Discovery ingress paths are unsupported.');
        }

        StrictJson::rejectUnknown($data['size_limits'], [
            'outer_request_bytes',
            'decrypted_payload_bytes',
        ]);
        StrictJson::requireProperties($data['size_limits'], [
            'outer_request_bytes',
            'decrypted_payload_bytes',
        ]);

        foreach ($data['size_limits'] as $limit) {
            if (! is_int($limit) || $limit < 1) {
                throw new InvalidArgumentException('Discovery size limits are invalid.');
            }
        }

        if (Str::length($data['display_name']) < 1 || Str::length($data['display_name']) > 255) {
            throw new InvalidArgumentException('Discovery display name is invalid.');
        }

        $origin = PeerOrigin::fromUrl($data['origin'])->value();

        return new self(
            installationId: $data['installation_id'],
            origin: $origin,
            displayName: $data['display_name'],
            ownershipEpoch: $data['ownership_epoch'],
            currentKey: $data['current_key'],
            protocolVersions: $data['supported_protocol_versions'],
            resourceSchemas: $data['resource_schemas'],
            ingress: $data['ingress'],
            sizeLimits: $data['size_limits'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'installation_id' => $this->installationId,
            'origin' => $this->origin,
            'display_name' => $this->displayName,
            'ownership_epoch' => $this->ownershipEpoch,
            'current_key' => $this->currentKey,
            'supported_protocol_versions' => $this->protocolVersions,
            'resource_schemas' => $this->resourceSchemas,
            'ingress' => $this->ingress,
            'size_limits' => $this->sizeLimits,
        ];
    }
}
