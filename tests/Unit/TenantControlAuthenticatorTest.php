<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NexusRuntime;
use App\Enums\TenantControlPurpose;
use App\Exceptions\TenantControlAuthenticationException;
use App\Exceptions\TenantControlConfigurationException;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use App\Services\TenantControl\TenantControlAuthenticator;
use App\Services\TenantControl\TenantControlEndpoint;
use App\Services\TenantControl\TenantControlKey;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TenantControlAuthenticatorTest extends TestCase
{
    private const TENANT_ID = '01JZ0000000000000000000000';

    private string $keyFile;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $keyFile = tempnam(sys_get_temp_dir(), 'nexus-control-key-');
        $this->assertNotFalse($keyFile);
        $this->keyFile = $keyFile;
        $this->key = base64_encode(random_bytes(48));
        file_put_contents($this->keyFile, $this->key."\n");

        config([
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.control.callback_key_file' => $this->keyFile,
            'nexus.control.response_max_age_seconds' => 120,
            'nexus.control.response_future_tolerance_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile) && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    public function test_it_signs_and_verifies_the_versioned_canonical_contract(): void
    {
        $now = CarbonImmutable::parse('2026-08-08T21:45:00Z');
        $body = '{"status":"accepted"}';
        $nonce = str_repeat('a', 32);
        $authenticator = $this->authenticator();

        $signed = $authenticator->sign(
            TenantControlPurpose::TenantCallbackResponse,
            $body,
            $now,
            $nonce,
        );

        $bodyDigest = hash('sha256', $body);
        $canonical = implode("\n", [
            'contract_version:1:1',
            'purpose:24:tenant.callback.response',
            'tenant_id:26:'.self::TENANT_ID,
            'timestamp:10:'.$now->getTimestamp(),
            'nonce:32:'.$nonce,
            'body_sha256:64:'.$bodyDigest,
        ]);

        $this->assertSame(
            hash_hmac('sha256', $canonical, $this->key),
            $signed->headers[TenantControlAuthenticator::HEADER_SIGNATURE],
        );
        $this->assertSame($nonce, $signed->nonce);
        $authenticator->verify(
            TenantControlPurpose::TenantCallbackResponse,
            $body,
            $signed->headers,
            $nonce,
            $now,
        );
        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_body_tenant_purpose_nonce_and_freshness_tampering(): void
    {
        $now = CarbonImmutable::parse('2026-08-08T21:45:00Z');
        $authenticator = $this->authenticator();
        $signed = $authenticator->sign(
            TenantControlPurpose::TenantCallbackResponse,
            '{}',
            $now,
            str_repeat('b', 32),
        );

        $cases = [
            'body' => ['{"changed":true}', $signed->headers, $signed->nonce, $now],
            'tenant' => ['{}', [
                ...$signed->headers,
                TenantControlAuthenticator::HEADER_TENANT_ID => '01JZ1111111111111111111111',
            ], $signed->nonce, $now],
            'nonce' => ['{}', $signed->headers, str_repeat('c', 32), $now],
            'stale' => ['{}', $signed->headers, $signed->nonce, $now->addSeconds(121)],
            'future' => ['{}', $signed->headers, $signed->nonce, $now->subSeconds(31)],
        ];

        foreach ($cases as $case) {
            try {
                $authenticator->verify(
                    TenantControlPurpose::TenantCallbackResponse,
                    $case[0],
                    $case[1],
                    $case[2],
                    $case[3],
                );
                $this->fail('The tampered tenant control message was accepted.');
            } catch (TenantControlAuthenticationException $exception) {
                $this->assertSame(
                    'Tenant control response authentication failed.',
                    $exception->getMessage(),
                );
            }
        }

        $this->expectException(TenantControlAuthenticationException::class);
        $authenticator->verify(
            TenantControlPurpose::TenantCallbackRequest,
            '{}',
            $signed->headers,
            $signed->nonce,
            $now,
        );
    }

    public function test_missing_or_unsafe_key_material_fails_without_disclosing_the_path_or_value(): void
    {
        config(['nexus.control.callback_key_file' => '/missing/private/key']);

        try {
            $this->authenticator()->sign(TenantControlPurpose::TenantCallbackRequest, '{}');
            $this->fail('Missing tenant control key material was accepted.');
        } catch (TenantControlConfigurationException $exception) {
            $this->assertSame('Tenant control authentication is unavailable.', $exception->getMessage());
            $this->assertStringNotContainsString('/missing/private/key', $exception->getMessage());
            $this->assertStringNotContainsString($this->key, $exception->getMessage());
        }
    }

    public function test_control_endpoints_are_https_server_owned_urls_without_credentials_or_parameters(): void
    {
        $endpoint = new TenantControlEndpoint;
        config(['nexus.control.callback_url' => 'https://console.example.test/internal/tenant-callbacks']);

        $this->assertSame(
            'https://console.example.test/internal/tenant-callbacks',
            $endpoint->fromConfig('nexus.control.callback_url'),
        );

        foreach ([
            'http://console.example.test/callbacks',
            'https://127.0.0.1/callbacks',
            'https://localhost/callbacks',
            'https://user@console.example.test/callbacks',
            'https://console.example.test/callbacks?tenant=other',
            'https://console.example.test/callbacks#fragment',
        ] as $invalidUrl) {
            config(['nexus.control.callback_url' => $invalidUrl]);

            try {
                $endpoint->fromConfig('nexus.control.callback_url');
                $this->fail("Unsafe control endpoint [{$invalidUrl}] was accepted.");
            } catch (TenantControlConfigurationException $exception) {
                $this->assertSame('Tenant control authentication is unavailable.', $exception->getMessage());
            }
        }
    }

    private function authenticator(): TenantControlAuthenticator
    {
        return new TenantControlAuthenticator(
            new TenantControlKey,
            new RuntimeBuildMetadata(
                NexusRuntime::HostedTenant,
                new RuntimeCapabilities(NexusRuntime::HostedTenant),
            ),
        );
    }
}
