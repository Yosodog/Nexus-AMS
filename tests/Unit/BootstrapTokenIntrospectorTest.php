<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\BootstrapTokenIntrospector;
use App\Enums\NexusRuntime;
use App\Enums\TenantBootstrapAction;
use App\Enums\TenantControlPurpose;
use App\Exceptions\BootstrapIntrospectionException;
use App\Services\RuntimeCapabilities;
use App\Services\TenantControl\TenantControlAuthenticator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BootstrapTokenIntrospectorTest extends TestCase
{
    private const INTROSPECTION_URL = 'https://console.example.test/internal/bootstrap/introspect';

    private const TENANT_ID = '01JZ0000000000000000000000';

    private const RELEASE_ID = 'release-bootstrap-test';

    private const ALLIANCE_ID = 12_345;

    private const NATION_ID = 98_765;

    private string $key;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $keyFile = tempnam(sys_get_temp_dir(), 'nexus-control-key-');
        $this->assertNotFalse($keyFile);
        $this->keyFile = $keyFile;
        $this->key = base64_encode(random_bytes(48));
        $this->assertNotFalse(file_put_contents($this->keyFile, $this->key."\n"));

        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.release_id' => self::RELEASE_ID,
            'nexus.control.bootstrap_introspection_url' => self::INTROSPECTION_URL,
            'nexus.control.callback_key_file' => $this->keyFile,
            'nexus.control.bootstrap_token_max_ttl_seconds' => 900,
        ]);
        $this->forgetRuntimeSingletons();
        $this->travelTo('2026-08-08 22:00:00');
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile) && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    public function test_active_signed_claims_are_returned_without_sending_raw_credentials(): void
    {
        $token = 'nxb_'.str_repeat('a', 64);
        $tokenHash = hash('sha256', $token);
        $cloudUserId = (string) Str::ulid();

        Http::fake(function (Request $request) use ($tokenHash, $cloudUserId) {
            $this->assertAuthenticatedRequest($request, $tokenHash);

            return $this->signedResponse($request, $this->activePayload($tokenHash, [
                'cloud_user_id' => $cloudUserId,
            ]));
        });

        $claims = $this->introspector()->introspect($tokenHash);

        $this->assertSame(self::TENANT_ID, $claims->tenantId);
        $this->assertSame(strtoupper($cloudUserId), $claims->cloudUserId);
        $this->assertSame(TenantBootstrapAction::InitialAdmin, $claims->action);
        $this->assertSame(self::RELEASE_ID, $claims->releaseId);
        $this->assertSame(self::ALLIANCE_ID, $claims->allianceId);
        $this->assertSame(self::NATION_ID, $claims->nationId);
        $this->assertSame('2026-08-08T21:59:00Z', $claims->issuedAt->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-08-08T22:10:00Z', $claims->expiresAt->format('Y-m-d\TH:i:s\Z'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $claims->claimsDigest);

        Http::assertSent(function (Request $request) use ($token, $tokenHash): bool {
            $headers = json_encode($request->headers(), JSON_THROW_ON_ERROR);

            return $request->url() === self::INTROSPECTION_URL
                && str_contains($request->body(), $tokenHash)
                && ! str_contains($request->body(), $token)
                && ! str_contains($request->body(), $this->key)
                && ! str_contains($headers, $this->key);
        });
    }

    public function test_signed_denial_is_terminally_rejected(): void
    {
        $tokenHash = hash('sha256', 'denied-token');
        Http::fake(fn (Request $request) => $this->signedResponse($request, [
            'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
            'status' => 'denied',
            'token_sha256' => $tokenHash,
            'tenant_id' => self::TENANT_ID,
            'action' => TenantBootstrapAction::InitialAdmin->value,
            'release_id' => self::RELEASE_ID,
        ]));

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'token_denied',
            retryable: false,
            httpStatus: 403,
        );
    }

    public function test_expired_claims_are_rejected(): void
    {
        $tokenHash = hash('sha256', 'expired-token');
        Http::fake(fn (Request $request) => $this->signedResponse(
            $request,
            $this->activePayload($tokenHash, [
                'issued_at' => '2026-08-08T21:40:00Z',
                'expires_at' => '2026-08-08T21:55:00Z',
            ]),
        ));

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'expired_or_invalid_claims',
            retryable: false,
            httpStatus: 403,
        );
    }

    public function test_claim_lifetime_is_bounded(): void
    {
        config(['nexus.control.bootstrap_token_max_ttl_seconds' => 3_600]);
        $tokenHash = hash('sha256', 'long-lived-token');
        Http::fake(fn (Request $request) => $this->signedResponse(
            $request,
            $this->activePayload($tokenHash, [
                'issued_at' => '2026-08-08T22:00:00Z',
                'expires_at' => '2026-08-08T22:15:01Z',
            ]),
        ));

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'expired_or_invalid_claims',
            retryable: false,
            httpStatus: 403,
        );
    }

    public function test_signed_response_with_extra_fields_is_rejected(): void
    {
        $tokenHash = hash('sha256', 'extra-field-token');
        Http::fake(fn (Request $request) => $this->signedResponse(
            $request,
            $this->activePayload($tokenHash, ['unexpected' => true]),
        ));

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'invalid_control_response',
            retryable: false,
            httpStatus: 403,
        );
    }

    #[DataProvider('invalidBoundClaimsProvider')]
    public function test_signed_response_cannot_substitute_request_bound_claims(array $overrides): void
    {
        $tokenHash = hash('sha256', 'bound-claim-token');
        Http::fake(fn (Request $request) => $this->signedResponse(
            $request,
            $this->activePayload($tokenHash, $overrides),
        ));

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'invalid_control_response',
            retryable: false,
            httpStatus: 403,
        );
    }

    public function test_unsigned_response_is_rejected(): void
    {
        $tokenHash = hash('sha256', 'unsigned-token');
        Http::fake([
            self::INTROSPECTION_URL => Http::response(
                json_encode($this->activePayload($tokenHash), JSON_THROW_ON_ERROR),
                200,
            ),
        ]);

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'response_authentication_failed',
            retryable: false,
            httpStatus: 403,
        );
    }

    public function test_retryable_dependency_status_is_reported_as_unavailable(): void
    {
        $tokenHash = hash('sha256', 'retryable-token');
        Http::fake([self::INTROSPECTION_URL => Http::response('temporarily unavailable', 503)]);

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'dependency_retryable_response',
            retryable: true,
            httpStatus: 503,
        );
    }

    public function test_connection_failure_is_retryable_without_exposing_provider_details(): void
    {
        $tokenHash = hash('sha256', 'connection-failure-token');
        Http::fake(Http::failedConnection('sensitive provider diagnostic'));

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'connection_unknown_outcome',
            retryable: true,
            httpStatus: 503,
        );
    }

    public function test_missing_control_key_is_retryable_before_network_access(): void
    {
        unlink($this->keyFile);
        Http::preventStrayRequests();

        try {
            $this->assertFailure(
                fn () => $this->introspector()->introspect(hash('sha256', 'missing-key-token')),
                'configuration_unavailable',
                retryable: true,
                httpStatus: 503,
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_oversized_response_is_terminally_rejected(): void
    {
        $tokenHash = hash('sha256', 'oversized-token');
        Http::fake([
            self::INTROSPECTION_URL => Http::response(str_repeat('a', 8_193), 200, [
                'Content-Length' => '8193',
            ]),
        ]);

        $this->assertFailure(
            fn () => $this->introspector()->introspect($tokenHash),
            'invalid_control_response',
            retryable: false,
            httpStatus: 403,
        );
    }

    public function test_standalone_runtime_rejects_before_reading_keys_or_using_network(): void
    {
        config([
            'nexus.runtime' => NexusRuntime::Standalone->value,
            'nexus.control.callback_key_file' => '/missing/control-key',
        ]);
        $this->forgetRuntimeSingletons();
        Http::preventStrayRequests();

        try {
            $this->assertFailure(
                fn () => $this->introspector()->introspect(hash('sha256', 'standalone-token')),
                'runtime_not_hosted',
                retryable: false,
                httpStatus: 403,
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_invalid_hash_is_rejected_before_network_access(): void
    {
        Http::preventStrayRequests();
        try {
            $this->assertFailure(
                fn () => $this->introspector()->introspect('not-a-token-hash'),
                'invalid_token_hash',
                retryable: false,
                httpStatus: 403,
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    /** @param array<string, mixed> $overrides */
    private function activePayload(string $tokenHash, array $overrides = []): array
    {
        return array_replace([
            'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
            'status' => 'active',
            'token_sha256' => $tokenHash,
            'tenant_id' => self::TENANT_ID,
            'cloud_user_id' => (string) Str::ulid(),
            'action' => TenantBootstrapAction::InitialAdmin->value,
            'release_id' => self::RELEASE_ID,
            'alliance_id' => self::ALLIANCE_ID,
            'nation_id' => self::NATION_ID,
            'issued_at' => '2026-08-08T21:59:00Z',
            'expires_at' => '2026-08-08T22:10:00Z',
        ], $overrides);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBoundClaimsProvider(): iterable
    {
        yield 'token digest' => [['token_sha256' => str_repeat('f', 64)]];
        yield 'tenant' => [['tenant_id' => '01JZ9999999999999999999999']];
        yield 'release' => [['release_id' => 'different-release']];
        yield 'action' => [['action' => 'different-action']];
        yield 'status' => [['status' => 'approved']];
        yield 'cloud identity' => [['cloud_user_id' => 'not-an-immutable-id']];
        yield 'alliance type' => [['alliance_id' => (string) self::ALLIANCE_ID]];
        yield 'invalid timestamp' => [['issued_at' => '2026-02-30T21:59:00Z']];
    }

    /** @param array<string, mixed> $payload */
    private function signedResponse(Request $request, array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signed = app(TenantControlAuthenticator::class)->sign(
            TenantControlPurpose::BootstrapIntrospectionResponse,
            $body,
            nonce: $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE),
        );

        return Http::response($body, 200, $signed->headers);
    }

    private function assertAuthenticatedRequest(Request $request, string $tokenHash): void
    {
        $nonce = $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE);
        app(TenantControlAuthenticator::class)->verify(
            TenantControlPurpose::BootstrapIntrospectionRequest,
            $request->body(),
            $this->requestAuthenticationHeaders($request),
            $nonce,
        );

        $this->assertSame([
            'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
            'token_sha256' => $tokenHash,
            'tenant_id' => self::TENANT_ID,
            'action' => TenantBootstrapAction::InitialAdmin->value,
            'release_id' => self::RELEASE_ID,
        ], json_decode($request->body(), true, 16, JSON_THROW_ON_ERROR));
    }

    /** @param callable(): mixed $operation */
    private function assertFailure(
        callable $operation,
        string $code,
        bool $retryable,
        int $httpStatus,
    ): void {
        try {
            $operation();
            $this->fail('Bootstrap introspection unexpectedly succeeded.');
        } catch (BootstrapIntrospectionException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($retryable, $exception->retryable);
            $this->assertSame($httpStatus, $exception->httpStatus);
            $this->assertSame(
                $retryable
                    ? 'Bootstrap verification is temporarily unavailable.'
                    : 'Bootstrap could not be authorized.',
                $exception->getMessage(),
            );
            $this->assertNull($exception->getPrevious());
        }
    }

    /** @return array<string, string|null> */
    private function requestAuthenticationHeaders(Request $request): array
    {
        return [
            TenantControlAuthenticator::HEADER_CONTRACT => $this->requestHeader($request, TenantControlAuthenticator::HEADER_CONTRACT),
            TenantControlAuthenticator::HEADER_TENANT_ID => $this->requestHeader($request, TenantControlAuthenticator::HEADER_TENANT_ID),
            TenantControlAuthenticator::HEADER_PURPOSE => $this->requestHeader($request, TenantControlAuthenticator::HEADER_PURPOSE),
            TenantControlAuthenticator::HEADER_TIMESTAMP => $this->requestHeader($request, TenantControlAuthenticator::HEADER_TIMESTAMP),
            TenantControlAuthenticator::HEADER_NONCE => $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE),
            TenantControlAuthenticator::HEADER_BODY_DIGEST => $this->requestHeader($request, TenantControlAuthenticator::HEADER_BODY_DIGEST),
            TenantControlAuthenticator::HEADER_SIGNATURE => $this->requestHeader($request, TenantControlAuthenticator::HEADER_SIGNATURE),
        ];
    }

    private function requestHeader(Request $request, string $name): string
    {
        $value = $request->header($name)[0] ?? null;

        return is_string($value) ? $value : '';
    }

    private function introspector(): BootstrapTokenIntrospector
    {
        return app(BootstrapTokenIntrospector::class);
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }
}
