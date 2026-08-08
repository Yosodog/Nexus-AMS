<?php

declare(strict_types=1);

namespace App\Services\TenantControl;

use App\Contracts\BootstrapTokenIntrospector;
use App\DataTransferObjects\BootstrapClaims;
use App\Enums\TenantBootstrapAction;
use App\Enums\TenantControlPurpose;
use App\Exceptions\BootstrapIntrospectionException;
use App\Exceptions\TenantControlAuthenticationException;
use App\Exceptions\TenantControlConfigurationException;
use App\Exceptions\TenantControlTransportException;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class HttpBootstrapTokenIntrospector implements BootstrapTokenIntrospector
{
    private const MAX_TOKEN_TTL_SECONDS = 900;

    public function __construct(
        private RuntimeCapabilities $capabilities,
        private RuntimeBuildMetadata $build,
        private TenantControlAuthenticator $authenticator,
        private TenantControlEndpoint $endpoint,
        private TenantControlResponseGuard $responseGuard,
    ) {}

    public function introspect(string $tokenHash): BootstrapClaims
    {
        if (! $this->capabilities->acceptsBootstrapRedemption()) {
            throw $this->unauthorized('runtime_not_hosted');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            throw $this->unauthorized('invalid_token_hash');
        }

        try {
            $body = $this->requestBody($tokenHash);
            $signed = $this->authenticator->sign(
                TenantControlPurpose::BootstrapIntrospectionRequest,
                $body,
            );
            $response = Http::acceptJson()
                ->connectTimeout($this->boundedConfig('connect_timeout_seconds', 1, 10, 3))
                ->timeout($this->boundedConfig('request_timeout_seconds', 2, 30, 10))
                ->withOptions($this->requestOptions())
                ->withHeaders($signed->headers)
                ->withBody($signed->body, 'application/json')
                ->post($this->endpoint->fromConfig('nexus.control.bootstrap_introspection_url'));
        } catch (TenantControlTransportException $exception) {
            throw $this->fromTransportFailure($exception);
        } catch (RequestException $exception) {
            $nestedFailure = $this->nestedTransportFailure($exception);

            if ($nestedFailure !== null) {
                throw $this->fromTransportFailure($nestedFailure);
            }

            throw $this->unauthorized('invalid_control_response');
        } catch (ConnectionException $exception) {
            $nestedFailure = $this->nestedTransportFailure($exception);

            if ($nestedFailure !== null) {
                throw $this->fromTransportFailure($nestedFailure);
            }

            throw $this->unavailable('connection_unknown_outcome');
        } catch (TenantControlConfigurationException) {
            throw $this->unavailable('configuration_unavailable');
        }

        if ($this->isRetryableStatus($response->status())) {
            throw $this->unavailable('dependency_retryable_response');
        }

        if ($response->status() !== 200) {
            throw $this->unauthorized('dependency_rejected_bootstrap');
        }

        return $this->claimsFromResponse($tokenHash, $response, $signed->nonce);
    }

    private function requestBody(string $tokenHash): string
    {
        $tenantId = $this->authenticator->tenantId();
        $releaseId = $this->build->releaseId();

        if (! $this->build->hasConfiguredReleaseId()) {
            throw new TenantControlConfigurationException;
        }

        try {
            return json_encode([
                'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
                'token_sha256' => $tokenHash,
                'tenant_id' => $tenantId,
                'action' => TenantBootstrapAction::InitialAdmin->value,
                'release_id' => $releaseId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new TenantControlConfigurationException;
        }
    }

    private function claimsFromResponse(
        string $tokenHash,
        Response $response,
        string $expectedNonce,
    ): BootstrapClaims {
        $body = $response->body();

        try {
            $this->responseGuard->assertBody($body, $response->status());
            $this->authenticator->verify(
                TenantControlPurpose::BootstrapIntrospectionResponse,
                $body,
                $this->authenticationHeaders($response),
                $expectedNonce,
            );
        } catch (TenantControlTransportException $exception) {
            throw $this->fromTransportFailure($exception);
        } catch (TenantControlAuthenticationException) {
            throw $this->unauthorized('response_authentication_failed');
        } catch (TenantControlConfigurationException) {
            throw $this->unavailable('configuration_unavailable');
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unauthorized('invalid_control_response');
        }

        if (! is_array($decoded) || ! is_string($decoded['status'] ?? null)) {
            throw $this->unauthorized('invalid_control_response');
        }

        if ($decoded['status'] === 'denied') {
            $this->assertDeniedResponse($decoded, $tokenHash);

            throw $this->unauthorized('token_denied');
        }

        return $this->activeClaims($decoded, $tokenHash, $body);
    }

    /** @param array<mixed> $decoded */
    private function assertDeniedResponse(array $decoded, string $tokenHash): void
    {
        $expectedKeys = [
            'contract_version',
            'status',
            'token_sha256',
            'tenant_id',
            'action',
            'release_id',
        ];

        if (! $this->hasExactKeys($decoded, $expectedKeys)
            || ($decoded['contract_version'] ?? null) !== TenantControlAuthenticator::CONTRACT_VERSION
            || ! is_string($decoded['token_sha256'] ?? null)
            || ! hash_equals($tokenHash, $decoded['token_sha256'])
            || ($decoded['tenant_id'] ?? null) !== $this->authenticator->tenantId()
            || ($decoded['action'] ?? null) !== TenantBootstrapAction::InitialAdmin->value
            || ($decoded['release_id'] ?? null) !== $this->build->releaseId()) {
            throw $this->unauthorized('invalid_control_response');
        }
    }

    /** @param array<mixed> $decoded */
    private function activeClaims(array $decoded, string $tokenHash, string $body): BootstrapClaims
    {
        $expectedKeys = [
            'contract_version',
            'status',
            'token_sha256',
            'tenant_id',
            'cloud_user_id',
            'action',
            'release_id',
            'alliance_id',
            'nation_id',
            'issued_at',
            'expires_at',
        ];
        $cloudUserId = $decoded['cloud_user_id'] ?? null;
        $allianceId = $decoded['alliance_id'] ?? null;
        $nationId = $decoded['nation_id'] ?? null;

        if (! $this->hasExactKeys($decoded, $expectedKeys)
            || ($decoded['contract_version'] ?? null) !== TenantControlAuthenticator::CONTRACT_VERSION
            || ($decoded['status'] ?? null) !== 'active'
            || ! is_string($decoded['token_sha256'] ?? null)
            || ! hash_equals($tokenHash, $decoded['token_sha256'])
            || ($decoded['tenant_id'] ?? null) !== $this->authenticator->tenantId()
            || ! is_string($cloudUserId)
            || (! Str::isUlid($cloudUserId) && ! Str::isUuid($cloudUserId))
            || ($decoded['action'] ?? null) !== TenantBootstrapAction::InitialAdmin->value
            || ($decoded['release_id'] ?? null) !== $this->build->releaseId()
            || ! is_int($allianceId)
            || $allianceId < 1
            || ! is_int($nationId)
            || $nationId < 1) {
            throw $this->unauthorized('invalid_control_response');
        }

        $issuedAt = $this->parseTimestamp($decoded['issued_at'] ?? null);
        $expiresAt = $this->parseTimestamp($decoded['expires_at'] ?? null);
        $now = CarbonImmutable::now('UTC');
        $futureTolerance = $this->boundedConfig(
            'response_future_tolerance_seconds',
            0,
            60,
            30,
        );
        $claimLifetime = $expiresAt->getTimestamp() - $issuedAt->getTimestamp();

        if ($issuedAt->getTimestamp() > $now->getTimestamp() + $futureTolerance
            || $expiresAt->getTimestamp() <= $now->getTimestamp()
            || $claimLifetime < 1
            || $claimLifetime > $this->boundedConfig(
                'bootstrap_token_max_ttl_seconds',
                1,
                self::MAX_TOKEN_TTL_SECONDS,
                self::MAX_TOKEN_TTL_SECONDS,
            )) {
            throw $this->unauthorized('expired_or_invalid_claims');
        }

        return new BootstrapClaims(
            tenantId: (string) $decoded['tenant_id'],
            cloudUserId: Str::isUlid($cloudUserId)
                ? strtoupper($cloudUserId)
                : strtolower($cloudUserId),
            action: TenantBootstrapAction::InitialAdmin,
            releaseId: (string) $decoded['release_id'],
            allianceId: $allianceId,
            nationId: $nationId,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            claimsDigest: hash('sha256', $body),
        );
    }

    private function parseTimestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) !== 1) {
            throw $this->unauthorized('invalid_control_response');
        }

        try {
            $timestamp = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        } catch (Throwable) {
            throw $this->unauthorized('invalid_control_response');
        }

        if ($timestamp->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw $this->unauthorized('invalid_control_response');
        }

        return $timestamp;
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $payload, array $expectedKeys): bool
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    /** @return array<string, string|null> */
    private function authenticationHeaders(Response $response): array
    {
        return [
            TenantControlAuthenticator::HEADER_CONTRACT => $response->header(TenantControlAuthenticator::HEADER_CONTRACT),
            TenantControlAuthenticator::HEADER_TENANT_ID => $response->header(TenantControlAuthenticator::HEADER_TENANT_ID),
            TenantControlAuthenticator::HEADER_PURPOSE => $response->header(TenantControlAuthenticator::HEADER_PURPOSE),
            TenantControlAuthenticator::HEADER_TIMESTAMP => $response->header(TenantControlAuthenticator::HEADER_TIMESTAMP),
            TenantControlAuthenticator::HEADER_NONCE => $response->header(TenantControlAuthenticator::HEADER_NONCE),
            TenantControlAuthenticator::HEADER_BODY_DIGEST => $response->header(TenantControlAuthenticator::HEADER_BODY_DIGEST),
            TenantControlAuthenticator::HEADER_SIGNATURE => $response->header(TenantControlAuthenticator::HEADER_SIGNATURE),
        ];
    }

    private function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private function nestedTransportFailure(Throwable $exception): ?TenantControlTransportException
    {
        $current = $exception->getPrevious();

        while ($current !== null) {
            if ($current instanceof TenantControlTransportException) {
                return $current;
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function requestOptions(): array
    {
        $responseGuard = $this->responseGuard;

        return [
            'allow_redirects' => false,
            'on_headers' => static function (mixed $response) use ($responseGuard): void {
                if ($response instanceof ResponseInterface) {
                    $responseGuard->assertHeaders($response);
                }
            },
            'progress' => static function (
                int|float $downloadTotal,
                int|float $downloaded,
            ) use ($responseGuard): void {
                $responseGuard->assertProgress($downloadTotal, $downloaded);
            },
        ];
    }

    private function fromTransportFailure(
        TenantControlTransportException $exception,
    ): BootstrapIntrospectionException {
        return $exception->retryable
            ? $this->unavailable($exception->failureCode)
            : $this->unauthorized($exception->failureCode);
    }

    private function unavailable(string $errorCode): BootstrapIntrospectionException
    {
        return new BootstrapIntrospectionException(
            errorCode: $errorCode,
            retryable: true,
            httpStatus: 503,
        );
    }

    private function unauthorized(string $errorCode): BootstrapIntrospectionException
    {
        return new BootstrapIntrospectionException(
            errorCode: $errorCode,
            retryable: false,
            httpStatus: 403,
        );
    }

    private function boundedConfig(string $key, int $minimum, int $maximum, int $default): int
    {
        $value = config('nexus.control.'.$key);

        return is_int($value) && $value >= $minimum && $value <= $maximum
            ? $value
            : $default;
    }
}
