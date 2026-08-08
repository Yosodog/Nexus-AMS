<?php

declare(strict_types=1);

namespace App\Services\TenantControl;

use App\DataTransferObjects\SignedTenantControlRequest;
use App\Enums\TenantControlPurpose;
use App\Exceptions\TenantControlAuthenticationException;
use App\Exceptions\TenantControlConfigurationException;
use App\Services\RuntimeBuildMetadata;
use Carbon\CarbonImmutable;

final readonly class TenantControlAuthenticator
{
    public const CONTRACT_VERSION = 1;

    public const HEADER_CONTRACT = 'X-Nexus-Contract-Version';

    public const HEADER_TENANT_ID = 'X-Nexus-Tenant-Id';

    public const HEADER_PURPOSE = 'X-Nexus-Purpose';

    public const HEADER_TIMESTAMP = 'X-Nexus-Timestamp';

    public const HEADER_NONCE = 'X-Nexus-Nonce';

    public const HEADER_BODY_DIGEST = 'X-Nexus-Body-SHA256';

    public const HEADER_SIGNATURE = 'X-Nexus-Signature';

    private const MAX_BODY_BYTES = 65_536;

    public function __construct(
        private TenantControlKey $key,
        private RuntimeBuildMetadata $build,
    ) {}

    public function sign(
        TenantControlPurpose $purpose,
        string $body,
        ?CarbonImmutable $at = null,
        ?string $nonce = null,
    ): SignedTenantControlRequest {
        $this->assertBodyLength($body, configurationFailure: true);
        $tenantId = $this->tenantId();
        $timestamp = (string) ($at ?? CarbonImmutable::now())->getTimestamp();
        $nonce ??= bin2hex(random_bytes(16));

        if (preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            throw new TenantControlConfigurationException;
        }

        $bodyDigest = hash('sha256', $body);
        $signature = hash_hmac(
            'sha256',
            $this->canonicalPayload($purpose, $tenantId, $timestamp, $nonce, $bodyDigest),
            $this->key->value(),
        );

        return new SignedTenantControlRequest(
            body: $body,
            headers: [
                self::HEADER_CONTRACT => (string) self::CONTRACT_VERSION,
                self::HEADER_TENANT_ID => $tenantId,
                self::HEADER_PURPOSE => $purpose->value,
                self::HEADER_TIMESTAMP => $timestamp,
                self::HEADER_NONCE => $nonce,
                self::HEADER_BODY_DIGEST => $bodyDigest,
                self::HEADER_SIGNATURE => $signature,
            ],
            nonce: $nonce,
        );
    }

    /** @param array<string, string|null> $headers */
    public function verify(
        TenantControlPurpose $purpose,
        string $body,
        array $headers,
        string $expectedNonce,
        ?CarbonImmutable $now = null,
    ): void {
        $this->assertBodyLength($body, configurationFailure: false);
        $contract = $headers[self::HEADER_CONTRACT] ?? null;
        $tenantId = $headers[self::HEADER_TENANT_ID] ?? null;
        $providedPurpose = $headers[self::HEADER_PURPOSE] ?? null;
        $timestamp = $headers[self::HEADER_TIMESTAMP] ?? null;
        $nonce = $headers[self::HEADER_NONCE] ?? null;
        $bodyDigest = $headers[self::HEADER_BODY_DIGEST] ?? null;
        $signature = $headers[self::HEADER_SIGNATURE] ?? null;

        if ($contract !== (string) self::CONTRACT_VERSION
            || $tenantId !== $this->tenantId()
            || $providedPurpose !== $purpose->value
            || ! is_string($timestamp)
            || preg_match('/\A[0-9]{10}\z/D', $timestamp) !== 1
            || ! is_string($nonce)
            || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1
            || ! hash_equals($expectedNonce, $nonce)
            || ! is_string($bodyDigest)
            || preg_match('/\A[a-f0-9]{64}\z/D', $bodyDigest) !== 1
            || ! hash_equals(hash('sha256', $body), $bodyDigest)
            || ! is_string($signature)
            || preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1) {
            throw new TenantControlAuthenticationException;
        }

        $currentTimestamp = ($now ?? CarbonImmutable::now())->getTimestamp();
        $signedTimestamp = (int) $timestamp;
        $maxAge = $this->boundedConfig('response_max_age_seconds', 30, 300, 120);
        $futureTolerance = $this->boundedConfig('response_future_tolerance_seconds', 0, 60, 30);

        if ($signedTimestamp < $currentTimestamp - $maxAge
            || $signedTimestamp > $currentTimestamp + $futureTolerance) {
            throw new TenantControlAuthenticationException;
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $this->canonicalPayload($purpose, $tenantId, $timestamp, $nonce, $bodyDigest),
            $this->key->value(),
        );

        if (! hash_equals($expectedSignature, $signature)) {
            throw new TenantControlAuthenticationException;
        }
    }

    public function tenantId(): string
    {
        $tenantId = $this->build->tenantId();

        if ($tenantId === null) {
            throw new TenantControlConfigurationException;
        }

        return $tenantId;
    }

    private function canonicalPayload(
        TenantControlPurpose $purpose,
        string $tenantId,
        string $timestamp,
        string $nonce,
        string $bodyDigest,
    ): string {
        $fields = [
            'contract_version' => (string) self::CONTRACT_VERSION,
            'purpose' => $purpose->value,
            'tenant_id' => $tenantId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'body_sha256' => $bodyDigest,
        ];

        return implode("\n", array_map(
            static fn (string $key, string $value): string => $key.':'.strlen($value).':'.$value,
            array_keys($fields),
            array_values($fields),
        ));
    }

    private function assertBodyLength(string $body, bool $configurationFailure): void
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return;
        }

        if ($configurationFailure) {
            throw new TenantControlConfigurationException;
        }

        throw new TenantControlAuthenticationException;
    }

    private function boundedConfig(string $key, int $minimum, int $maximum, int $default): int
    {
        $value = config('nexus.control.'.$key);

        return is_int($value) && $value >= $minimum && $value <= $maximum
            ? $value
            : $default;
    }
}
