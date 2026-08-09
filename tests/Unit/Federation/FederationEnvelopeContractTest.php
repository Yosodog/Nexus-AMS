<?php

namespace Tests\Unit\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\DTO\FederationEnvelope;
use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\DTO\WarPlanTargetV1;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Domain\Federation\Protocol\FederationEnvelopeService;
use App\Domain\Federation\Protocol\MessageSchemaRegistry;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Models\FederationIdentityKey;
use App\Models\FederationPeerKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class FederationEnvelopeContractTest extends TestCase
{
    public function test_sealed_envelope_round_trips_with_exact_canonical_payload_bytes(): void
    {
        [$senderKey, $senderMaterial] = $this->identityKey();
        [$recipientKey, $recipientMaterial] = $this->identityKey();
        $senderInstallationId = (string) Str::ulid();
        $recipientInstallationId = (string) Str::ulid();
        $payload = [
            'original_message_id' => (string) Str::ulid(),
            'received_at' => now()->utc()->toIso8601String(),
        ];
        $service = new FederationEnvelopeService(new FederationCryptography, new MessageSchemaRegistry);

        $envelope = $service->seal(
            type: FederationMessageType::DeliveryReceived,
            payload: $payload,
            senderInstallationId: $senderInstallationId,
            senderKey: $senderKey,
            recipientInstallationId: $recipientInstallationId,
            recipientKeyId: $recipientKey->id,
            recipientBoxPublicKey: $recipientMaterial['box_public_key'],
            expiresAt: CarbonImmutable::now('UTC')->addMinutes(10),
        );
        $opened = $service->open(
            envelope: $envelope,
            expectedRecipientInstallationId: $recipientInstallationId,
            recipientKey: $recipientKey,
            senderKey: $this->peerKey($senderMaterial),
        );

        $this->assertSame($payload, $opened->payload);
        $this->assertSame(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $opened->rawPayload);
        $this->assertSame($senderInstallationId, $opened->header->senderInstallationId);
        $this->assertSame($recipientInstallationId, $opened->header->recipientInstallationId);
        $this->assertSame($envelope->toJson(), FederationEnvelope::fromJson($envelope->toJson())->toJson());
    }

    public function test_envelope_rejects_unknown_and_duplicate_properties_before_verification(): void
    {
        $valid = [
            'version' => '1.0',
            'protected' => 'protected',
            'ciphertext' => 'ciphertext',
            'signature' => 'signature',
        ];

        try {
            FederationEnvelope::fromJson(json_encode([...$valid, 'unexpected' => true], JSON_THROW_ON_ERROR));
            $this->fail('An unknown envelope property was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unknown', strtolower($exception->getMessage()));
        }

        try {
            FederationEnvelope::fromJson(
                '{"version":"1.0","version":"1.0","protected":"protected",'
                .'"ciphertext":"ciphertext","signature":"signature"}',
            );
            $this->fail('A duplicate envelope property was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('duplicate', strtolower($exception->getMessage()));
        }
    }

    public function test_signature_and_ciphertext_tampering_are_non_retryable(): void
    {
        [$senderKey, $senderMaterial] = $this->identityKey();
        [$recipientKey, $recipientMaterial] = $this->identityKey();
        $senderInstallationId = (string) Str::ulid();
        $recipientInstallationId = (string) Str::ulid();
        $service = new FederationEnvelopeService(new FederationCryptography, new MessageSchemaRegistry);
        $envelope = $service->seal(
            FederationMessageType::DeliveryReceived,
            [
                'original_message_id' => (string) Str::ulid(),
                'received_at' => now()->utc()->toIso8601String(),
            ],
            $senderInstallationId,
            $senderKey,
            $recipientInstallationId,
            $recipientKey->id,
            $recipientMaterial['box_public_key'],
            CarbonImmutable::now('UTC')->addMinutes(10),
        );

        $tamperedCiphertext = Base64Url::decode($envelope->ciphertext);
        $tamperedCiphertext[0] = chr(ord($tamperedCiphertext[0]) ^ 1);
        $cases = [
            new FederationEnvelope(
                $envelope->version,
                $envelope->protected,
                Base64Url::encode($tamperedCiphertext),
                $envelope->signature,
            ),
            new FederationEnvelope(
                $envelope->version,
                $envelope->protected,
                $envelope->ciphertext,
                $envelope->signature.'A',
            ),
        ];

        foreach ($cases as $tampered) {
            try {
                $service->open(
                    $tampered,
                    $recipientInstallationId,
                    $recipientKey,
                    $this->peerKey($senderMaterial),
                );
                $this->fail('A tampered envelope was accepted.');
            } catch (FederationProtocolException $exception) {
                $this->assertSame(FederationErrorCode::InvalidSignature, $exception->errorCode);
            }
        }
    }

    public function test_recipient_mismatch_and_protocol_downgrade_are_rejected(): void
    {
        [$senderKey, $senderMaterial] = $this->identityKey();
        [$recipientKey, $recipientMaterial] = $this->identityKey();
        $service = new FederationEnvelopeService(new FederationCryptography, new MessageSchemaRegistry);
        $envelope = $service->seal(
            FederationMessageType::DeliveryReceived,
            [
                'original_message_id' => (string) Str::ulid(),
                'received_at' => now()->utc()->toIso8601String(),
            ],
            (string) Str::ulid(),
            $senderKey,
            (string) Str::ulid(),
            $recipientKey->id,
            $recipientMaterial['box_public_key'],
            CarbonImmutable::now('UTC')->addMinutes(10),
        );

        try {
            $service->open(
                $envelope,
                (string) Str::ulid(),
                $recipientKey,
                $this->peerKey($senderMaterial),
            );
            $this->fail('A wrong recipient was accepted.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame(FederationErrorCode::RecipientMismatch, $exception->errorCode);
        }

        try {
            $service->open(
                new FederationEnvelope('2.0', $envelope->protected, $envelope->ciphertext, $envelope->signature),
                (string) $envelope->protected === '' ? '' : $this->headerRecipient($envelope),
                $recipientKey,
                $this->peerKey($senderMaterial),
            );
            $this->fail('A protocol downgrade was accepted.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame(FederationErrorCode::ProtocolUnsupported, $exception->errorCode);
        }
    }

    public function test_resource_envelopes_require_the_negotiated_schema(): void
    {
        [$senderKey, $senderMaterial] = $this->identityKey();
        [$recipientKey, $recipientMaterial] = $this->identityKey();
        $senderInstallationId = (string) Str::ulid();
        $recipientInstallationId = (string) Str::ulid();
        $payload = $this->warPlanPayload($senderInstallationId, $recipientInstallationId);
        $service = new FederationEnvelopeService(new FederationCryptography, new MessageSchemaRegistry);
        $envelope = $service->seal(
            FederationMessageType::ResourcePublished,
            $payload,
            $senderInstallationId,
            $senderKey,
            $recipientInstallationId,
            $recipientKey->id,
            $recipientMaterial['box_public_key'],
            CarbonImmutable::now('UTC')->addMinutes(10),
            'milcom.war-plan-snapshot/9.0',
        );

        try {
            $service->open(
                $envelope,
                $recipientInstallationId,
                $recipientKey,
                $this->peerKey($senderMaterial),
            );
            $this->fail('An unsupported resource schema was accepted.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame(FederationErrorCode::SchemaUnsupported, $exception->errorCode);
        }
    }

    public function test_message_schema_rejects_fields_outside_the_contract(): void
    {
        [$senderKey, $recipientMaterial] = $this->identityKey();
        $service = new FederationEnvelopeService(new FederationCryptography, new MessageSchemaRegistry);

        $this->expectException(InvalidArgumentException::class);
        $service->seal(
            FederationMessageType::DeliveryReceived,
            [
                'original_message_id' => (string) Str::ulid(),
                'received_at' => now()->utc()->toIso8601String(),
                'decrypted_payload' => 'must not cross the boundary',
            ],
            (string) Str::ulid(),
            $senderKey,
            (string) Str::ulid(),
            (string) Str::ulid(),
            $recipientMaterial['box_public_key'],
            CarbonImmutable::now('UTC')->addMinutes(10),
        );
    }

    public function test_resource_versions_and_revisions_are_bounded_before_processing(): void
    {
        config()->set('federation.limits.max_resource_version', 10);
        config()->set('federation.limits.max_resource_revision', 10);
        $sourceInstallationId = (string) Str::ulid();
        $recipientInstallationId = (string) Str::ulid();
        $payload = $this->warPlanPayload($sourceInstallationId, $recipientInstallationId);
        $payload['version'] = 11;

        $this->expectException(InvalidArgumentException::class);
        (new MessageSchemaRegistry)->validate(FederationMessageType::ResourcePublished, $payload);
    }

    /** @return array{0: FederationIdentityKey, 1: array<string, string>} */
    private function identityKey(): array
    {
        $material = (new FederationCryptography)->generateKeyMaterial();
        $key = new FederationIdentityKey;
        $key->forceFill([
            'id' => (string) Str::ulid(),
            'identity_id' => (string) Str::ulid(),
            'generation' => 1,
            'status' => FederationKeyStatus::Active->value,
            'active_key' => 1,
            ...$material,
            'activated_at' => now(),
        ]);

        return [$key, $material];
    }

    /** @param  array<string, string>  $material */
    private function peerKey(array $material): FederationPeerKey
    {
        $key = new FederationPeerKey;
        $key->forceFill([
            'id' => (string) Str::ulid(),
            'federation_link_id' => (string) Str::ulid(),
            'remote_key_id' => (string) Str::ulid(),
            'generation' => 1,
            'status' => FederationKeyStatus::Active->value,
            'signing_public_key' => $material['signing_public_key'],
            'box_public_key' => $material['box_public_key'],
            'signing_fingerprint' => $material['signing_fingerprint'],
            'box_fingerprint' => $material['box_fingerprint'],
            'approved_at' => now(),
        ]);

        return $key;
    }

    private function headerRecipient(FederationEnvelope $envelope): string
    {
        $header = json_decode(Base64Url::decode($envelope->protected), true, 64, JSON_THROW_ON_ERROR);

        return (string) $header['recipient_installation_id'];
    }

    /** @return array<string, mixed> */
    private function warPlanPayload(string $sourceInstallationId, string $recipientInstallationId): array
    {
        $publishedAt = CarbonImmutable::now('UTC');
        $snapshot = new WarPlanSnapshotV1(
            publicationId: (string) Str::ulid(),
            versionId: (string) Str::ulid(),
            version: 1,
            revision: 1,
            sourceInstallationId: $sourceInstallationId,
            sourceAllianceId: 1,
            coalitionId: (string) Str::ulid(),
            rosterRevision: 1,
            sourceGeneration: 1,
            publishedAt: $publishedAt,
            expiresAt: $publishedAt->addDay(),
            recipientInstallationId: $recipientInstallationId,
            title: 'Federation test wave',
            waveLabel: 'Wave 1',
            recipientInstructions: '',
            targets: [new WarPlanTargetV1(
                targetNationId: 123,
                targetNationName: 'Target Nation',
                targetAllianceId: null,
                targetAllianceName: null,
                priorityTier: PriorityTier::Standard,
                warType: 'ORDINARY',
                minimumTeamSize: 1,
                desiredTeamSize: 2,
                deadlineAt: null,
            )],
        );

        return $snapshot->toArray();
    }
}
