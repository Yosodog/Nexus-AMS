<?php

namespace App\Domain\Federation\DTO;

use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProtectedHeader
{
    /** @var list<string> */
    private const FIELDS = [
        'message_id',
        'message_type',
        'sender_installation_id',
        'recipient_installation_id',
        'sender_key_id',
        'recipient_key_id',
        'issued_at',
        'expires_at',
        'nonce',
        'payload_digest',
        'protocol_version',
        'resource_schema',
        'handshake_signing_public_key',
    ];

    public function __construct(
        public string $messageId,
        public FederationMessageType $messageType,
        public string $senderInstallationId,
        public string $recipientInstallationId,
        public string $senderKeyId,
        public string $recipientKeyId,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        public string $nonce,
        public string $payloadDigest,
        public string $protocolVersion,
        public ?string $resourceSchema = null,
        public ?string $handshakeSigningPublicKey = null,
    ) {
        foreach ([$messageId, $senderInstallationId, $recipientInstallationId, $senderKeyId, $recipientKeyId] as $id) {
            if (! Str::isUlid($id)) {
                throw new InvalidArgumentException('Protected header contains an invalid ULID.');
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $payloadDigest) !== 1) {
            throw new InvalidArgumentException('Protected header contains an invalid payload digest.');
        }

        if (preg_match('/^[1-9][0-9]*\.[0-9]+$/D', $protocolVersion) !== 1
            || strlen($protocolVersion) > 16) {
            throw new InvalidArgumentException('Protected header contains an invalid protocol version.');
        }

        try {
            if (strlen(Base64Url::decode($nonce)) !== 24) {
                throw new InvalidArgumentException('Protected header contains an invalid nonce.');
            }

            if ($handshakeSigningPublicKey !== null
                && strlen(Base64Url::decode($handshakeSigningPublicKey)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('Protected header contains an invalid handshake key.');
            }
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Protected header contains invalid encoded fields.');
        }

        if ($resourceSchema !== null
            && (strlen($resourceSchema) > 64
                || preg_match('/^[a-z0-9][a-z0-9.-]*\/[1-9][0-9]*\.[0-9]+$/D', $resourceSchema) !== 1)) {
            throw new InvalidArgumentException('Protected header contains an invalid resource schema.');
        }
    }

    public static function fromJson(string $json): self
    {
        $data = StrictJson::decodeObject($json);
        StrictJson::rejectUnknown($data, self::FIELDS);
        StrictJson::requireProperties($data, array_slice(self::FIELDS, 0, 11));

        foreach (array_slice(self::FIELDS, 0, 11) as $field) {
            if (! is_string($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException('Protected header fields must be non-empty strings.');
            }
        }

        if (isset($data['resource_schema']) && ! is_string($data['resource_schema'])) {
            throw new InvalidArgumentException('Protected resource schema must be a string.');
        }

        if (isset($data['handshake_signing_public_key'])
            && ! is_string($data['handshake_signing_public_key'])) {
            throw new InvalidArgumentException('Protected handshake key must be a string.');
        }

        return new self(
            messageId: $data['message_id'],
            messageType: FederationMessageType::from($data['message_type']),
            senderInstallationId: $data['sender_installation_id'],
            recipientInstallationId: $data['recipient_installation_id'],
            senderKeyId: $data['sender_key_id'],
            recipientKeyId: $data['recipient_key_id'],
            issuedAt: CarbonImmutable::parse($data['issued_at']),
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            nonce: $data['nonce'],
            payloadDigest: $data['payload_digest'],
            protocolVersion: $data['protocol_version'],
            resourceSchema: $data['resource_schema'] ?? null,
            handshakeSigningPublicKey: $data['handshake_signing_public_key'] ?? null,
        );
    }

    public function toJson(): string
    {
        return CanonicalJson::encode(array_filter([
            'message_id' => $this->messageId,
            'message_type' => $this->messageType->value,
            'sender_installation_id' => $this->senderInstallationId,
            'recipient_installation_id' => $this->recipientInstallationId,
            'sender_key_id' => $this->senderKeyId,
            'recipient_key_id' => $this->recipientKeyId,
            'issued_at' => $this->issuedAt->utc()->toIso8601String(),
            'expires_at' => $this->expiresAt->utc()->toIso8601String(),
            'nonce' => $this->nonce,
            'payload_digest' => $this->payloadDigest,
            'protocol_version' => $this->protocolVersion,
            'resource_schema' => $this->resourceSchema,
            'handshake_signing_public_key' => $this->handshakeSigningPublicKey,
        ], fn (mixed $value): bool => $value !== null));
    }
}
