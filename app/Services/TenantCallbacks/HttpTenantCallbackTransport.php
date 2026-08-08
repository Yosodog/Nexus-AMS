<?php

declare(strict_types=1);

namespace App\Services\TenantCallbacks;

use App\Contracts\TenantCallbackTransport;
use App\Enums\TenantCallbackType;
use App\Enums\TenantControlPurpose;
use App\Exceptions\TenantCallbackTransportException;
use App\Exceptions\TenantControlAuthenticationException;
use App\Exceptions\TenantControlConfigurationException;
use App\Models\TenantCallbackDelivery;
use App\Services\TenantControl\TenantControlAuthenticator;
use App\Services\TenantControl\TenantControlEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class HttpTenantCallbackTransport implements TenantCallbackTransport
{
    public function __construct(
        private TenantControlAuthenticator $authenticator,
        private TenantControlEndpoint $endpoint,
        private TenantCallbackResponseGuard $responseGuard,
    ) {}

    public function send(TenantCallbackDelivery $delivery): void
    {
        try {
            $body = $this->body($delivery);
            $signed = $this->authenticator->sign(TenantControlPurpose::TenantCallbackRequest, $body);
            $response = Http::acceptJson()
                ->connectTimeout($this->boundedConfig('connect_timeout_seconds', 1, 10, 3))
                ->timeout($this->boundedConfig('request_timeout_seconds', 2, 30, 10))
                ->withOptions($this->requestOptions())
                ->withHeaders($signed->headers)
                ->withBody($signed->body, 'application/json')
                ->post($this->endpoint->fromConfig('nexus.control.callback_url'));
        } catch (TenantCallbackTransportException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            $nestedFailure = $this->nestedTransportFailure($exception);

            if ($nestedFailure !== null) {
                throw $nestedFailure;
            }

            throw new TenantCallbackTransportException(
                failureCode: 'invalid_callback_response',
                retryable: false,
                responseStatus: $exception->response->status(),
            );
        } catch (ConnectionException $exception) {
            $nestedFailure = $this->nestedTransportFailure($exception);

            if ($nestedFailure !== null) {
                throw $nestedFailure;
            }

            throw new TenantCallbackTransportException(
                failureCode: 'connection_unknown_outcome',
                retryable: true,
            );
        } catch (TenantControlConfigurationException) {
            throw new TenantCallbackTransportException(
                failureCode: 'configuration_unavailable',
                retryable: true,
            );
        }

        if ($this->isRetryableStatus($response->status())) {
            throw new TenantCallbackTransportException(
                failureCode: 'dependency_retryable_response',
                retryable: true,
                responseStatus: $response->status(),
            );
        }

        if (! $response->successful()) {
            throw new TenantCallbackTransportException(
                failureCode: 'dependency_rejected_callback',
                retryable: false,
                responseStatus: $response->status(),
            );
        }

        $this->assertAcceptedResponse($delivery, $response, $signed->nonce);
    }

    private function body(TenantCallbackDelivery $delivery): string
    {
        if ($delivery->tenant_id !== $this->authenticator->tenantId()
            || ! Str::isUlid($delivery->callback_id)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:@-]{0,63}\z/D', $delivery->release_id) !== 1) {
            throw new TenantCallbackTransportException(
                failureCode: 'invalid_callback_identity',
                retryable: false,
            );
        }

        $payload = $this->validatedPayload($delivery);

        try {
            return json_encode([
                'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
                'callback_id' => $delivery->callback_id,
                'tenant_id' => $delivery->tenant_id,
                'event_type' => $delivery->event_type->value,
                'occurred_at' => $delivery->occurred_at->toIso8601String(),
                'release_id' => $delivery->release_id,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new TenantCallbackTransportException(
                failureCode: 'invalid_callback_payload',
                retryable: false,
            );
        }
    }

    /** @return array<string, int|string> */
    private function validatedPayload(TenantCallbackDelivery $delivery): array
    {
        if ($delivery->event_type !== TenantCallbackType::BootstrapRedeemed) {
            throw new TenantCallbackTransportException(
                failureCode: 'unsupported_callback_type',
                retryable: false,
            );
        }

        $payload = $delivery->payload;
        $expectedKeys = [
            'bootstrap_redemption_id',
            'cloud_user_id',
            'local_user_id',
            'mode',
            'nation_id',
        ];

        if (! is_array($payload)
            || count($payload) !== count($expectedKeys)
            || array_diff(array_keys($payload), $expectedKeys) !== []
            || ! is_int($payload['bootstrap_redemption_id'] ?? null)
            || $payload['bootstrap_redemption_id'] < 1
            || ! is_string($payload['cloud_user_id'] ?? null)
            || (! Str::isUlid($payload['cloud_user_id']) && ! Str::isUuid($payload['cloud_user_id']))
            || ! is_int($payload['local_user_id'] ?? null)
            || $payload['local_user_id'] < 1
            || ! in_array($payload['mode'] ?? null, ['created', 'linked'], true)
            || ! is_int($payload['nation_id'] ?? null)
            || $payload['nation_id'] < 1) {
            throw new TenantCallbackTransportException(
                failureCode: 'invalid_callback_payload',
                retryable: false,
            );
        }

        return [
            'bootstrap_redemption_id' => $payload['bootstrap_redemption_id'],
            'cloud_user_id' => $payload['cloud_user_id'],
            'local_user_id' => $payload['local_user_id'],
            'mode' => $payload['mode'],
            'nation_id' => $payload['nation_id'],
        ];
    }

    private function assertAcceptedResponse(
        TenantCallbackDelivery $delivery,
        Response $response,
        string $expectedNonce,
    ): void {
        $body = $response->body();
        $this->responseGuard->assertBody($body, $response->status());

        try {
            $this->authenticator->verify(
                TenantControlPurpose::TenantCallbackResponse,
                $body,
                $this->authenticationHeaders($response),
                $expectedNonce,
            );
        } catch (TenantControlAuthenticationException) {
            throw new TenantCallbackTransportException(
                failureCode: 'callback_response_authentication_failed',
                retryable: false,
                responseStatus: $response->status(),
            );
        } catch (TenantControlConfigurationException) {
            throw new TenantCallbackTransportException(
                failureCode: 'configuration_unavailable',
                retryable: true,
                responseStatus: $response->status(),
            );
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TenantCallbackTransportException(
                failureCode: 'invalid_callback_response',
                retryable: false,
                responseStatus: $response->status(),
            );
        }

        $expectedKeys = ['contract_version', 'callback_id', 'status'];

        if (! is_array($decoded)
            || count($decoded) !== count($expectedKeys)
            || array_diff(array_keys($decoded), $expectedKeys) !== []
            || ($decoded['contract_version'] ?? null) !== TenantControlAuthenticator::CONTRACT_VERSION
            || ($decoded['callback_id'] ?? null) !== $delivery->callback_id
            || ! in_array($decoded['status'] ?? null, ['accepted', 'duplicate'], true)) {
            throw new TenantCallbackTransportException(
                failureCode: 'invalid_callback_response',
                retryable: false,
                responseStatus: $response->status(),
            );
        }
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

    private function nestedTransportFailure(Throwable $exception): ?TenantCallbackTransportException
    {
        $current = $exception->getPrevious();

        while ($current !== null) {
            if ($current instanceof TenantCallbackTransportException) {
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

    private function boundedConfig(string $key, int $minimum, int $maximum, int $default): int
    {
        $value = config('nexus.control.'.$key);

        return is_int($value) && $value >= $minimum && $value <= $maximum
            ? $value
            : $default;
    }
}
