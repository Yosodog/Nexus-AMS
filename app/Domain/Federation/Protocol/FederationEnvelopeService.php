<?php

namespace App\Domain\Federation\Protocol;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\DTO\FederationEnvelope;
use App\Domain\Federation\DTO\OpenedEnvelope;
use App\Domain\Federation\DTO\ProtectedHeader;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationIdentityKey;
use App\Models\FederationPeerKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class FederationEnvelopeService
{
    public function __construct(
        private readonly FederationCryptography $cryptography,
        private readonly MessageSchemaRegistry $schemas,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function seal(
        FederationMessageType $type,
        array $payload,
        string $senderInstallationId,
        FederationIdentityKey $senderKey,
        string $recipientInstallationId,
        string $recipientKeyId,
        string $recipientBoxPublicKey,
        CarbonImmutable $expiresAt,
        ?string $resourceSchema = null,
        bool $includeHandshakeKey = false,
    ): FederationEnvelope {
        $this->schemas->validate($type, $payload);
        $plaintext = CanonicalJson::encode($payload);
        $limit = (int) config('federation.limits.decrypted_payload_bytes', 524288);

        if (strlen($plaintext) > $limit) {
            throw new FederationProtocolException(FederationErrorCode::PayloadTooLarge, 413);
        }

        $now = CarbonImmutable::now('UTC');
        $header = new ProtectedHeader(
            messageId: (string) Str::ulid(),
            messageType: $type,
            senderInstallationId: $senderInstallationId,
            recipientInstallationId: $recipientInstallationId,
            senderKeyId: $senderKey->id,
            recipientKeyId: $recipientKeyId,
            issuedAt: $now,
            expiresAt: $expiresAt,
            nonce: Base64Url::encode(random_bytes(24)),
            payloadDigest: hash('sha256', $plaintext),
            protocolVersion: (string) config('federation.protocol_version', '1.0'),
            resourceSchema: $resourceSchema,
            handshakeSigningPublicKey: $includeHandshakeKey ? $senderKey->signing_public_key : null,
        );
        $protected = Base64Url::encode($header->toJson());
        $ciphertext = $this->cryptography->seal($plaintext, $recipientBoxPublicKey);
        $version = (string) config('federation.protocol_version', '1.0');
        $signature = $this->cryptography->sign(
            self::signatureInput($version, $protected, $ciphertext),
            $senderKey->signing_private_key,
        );

        return new FederationEnvelope($version, $protected, $ciphertext, $signature);
    }

    public function open(
        FederationEnvelope $envelope,
        string $expectedRecipientInstallationId,
        FederationIdentityKey $recipientKey,
        FederationPeerKey|string $senderKey,
    ): OpenedEnvelope {
        try {
            if ($envelope->version !== (string) config('federation.protocol_version', '1.0')) {
                throw new FederationProtocolException(FederationErrorCode::ProtocolUnsupported, 422);
            }

            $header = ProtectedHeader::fromJson(Base64Url::decode($envelope->protected));
        } catch (FederationProtocolException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        $this->assertHeader($header, $expectedRecipientInstallationId, $recipientKey);
        $senderPublicKey = $senderKey instanceof FederationPeerKey
            ? $senderKey->signing_public_key
            : $senderKey;

        if (! $this->cryptography->verify(
            self::signatureInput($envelope->version, $envelope->protected, $envelope->ciphertext),
            $envelope->signature,
            $senderPublicKey,
        )) {
            throw new FederationProtocolException(FederationErrorCode::InvalidSignature, 401);
        }

        $plaintext = $this->cryptography->open(
            $envelope->ciphertext,
            $recipientKey->box_public_key,
            $recipientKey->box_private_key,
        );

        if (! is_string($plaintext)) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        if (strlen($plaintext) > (int) config('federation.limits.decrypted_payload_bytes', 524288)) {
            throw new FederationProtocolException(FederationErrorCode::PayloadTooLarge, 413);
        }

        if (! hash_equals($header->payloadDigest, hash('sha256', $plaintext))) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        try {
            $payload = StrictJson::decodeObject($plaintext);
            $this->schemas->validate($header->messageType, $payload);
        } catch (Throwable) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        return new OpenedEnvelope($envelope, $header, $plaintext, $payload);
    }

    public static function signatureInput(string $version, string $protected, string $ciphertext): string
    {
        $fields = compact('version', 'protected', 'ciphertext');
        $parts = ['nexus-federation-envelope-v1'];

        foreach ($fields as $name => $value) {
            $parts[] = $name.':'.strlen($value).':'.$value;
        }

        return implode("\n", $parts);
    }

    private function assertHeader(
        ProtectedHeader $header,
        string $expectedRecipientInstallationId,
        FederationIdentityKey $recipientKey,
    ): void {
        if (! hash_equals($expectedRecipientInstallationId, $header->recipientInstallationId)
            || ! hash_equals($recipientKey->id, $header->recipientKeyId)) {
            throw new FederationProtocolException(FederationErrorCode::RecipientMismatch, 403);
        }

        $now = CarbonImmutable::now('UTC');
        $skew = max((int) config('federation.clock_skew_seconds', 300), 0);

        if ($header->issuedAt->isAfter($now->addSeconds($skew))) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        if ($header->expiresAt->isBefore($now->subSeconds($skew))) {
            throw new FederationProtocolException(FederationErrorCode::MessageExpired, 410);
        }

        if (! $header->expiresAt->isAfter($header->issuedAt)) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        if ($header->messageType === FederationMessageType::ResourcePublished
            || $header->messageType === FederationMessageType::ResourceUpdated) {
            if ($header->resourceSchema !== 'milcom.war-plan-snapshot/1.0') {
                throw new FederationProtocolException(FederationErrorCode::SchemaUnsupported, 422);
            }
        } elseif ($header->resourceSchema !== null) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }
    }
}
