<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TenantEvents;

use App\Enums\NexusRuntime;
use App\Enums\TenantEventRejectionReason;
use App\Enums\TenantEventType;
use App\Exceptions\TenantEventConfigurationException;
use App\Exceptions\TenantEventRejectedException;
use App\Services\RuntimeCapabilities;
use App\Services\TenantEvents\TenantEventAuthenticator;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantEventAuthenticatorTest extends TestCase
{
    private const TENANT_ID = '01KZHV17VQ9S6GDGBK0QJ5GF1Z';

    private string $key;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $keyFile = tempnam(sys_get_temp_dir(), 'nexus-tenant-event-key-');
        $this->assertNotFalse($keyFile);
        $this->keyFile = $keyFile;
        $this->key = base64_encode(random_bytes(48));
        $this->assertNotFalse(file_put_contents($this->keyFile, $this->key."\n"));

        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.tenant_events.enabled' => true,
            'nexus.tenant_events.key_file' => $this->keyFile,
            'nexus.tenant_events.max_body_bytes' => 8_192,
            'nexus.tenant_events.max_age_seconds' => 300,
            'nexus.tenant_events.future_tolerance_seconds' => 30,
        ]);
        $this->forgetRuntimeSingletons();
        $this->travelTo('2026-08-08 22:00:05 UTC');
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile) && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    public function test_documented_v1_fixture_is_authenticated_and_normalized(): void
    {
        $event = $this->authenticator()->verify($this->signedFields($this->fixtureBody()));

        $this->assertSame('01KZHV17VQ9S6GDGBK0QJ5GF1Y', $event->deliveryId);
        $this->assertSame('world:war:123456:create:v1', $event->eventId);
        $this->assertSame(1, $event->contractVersion);
        $this->assertSame(self::TENANT_ID, $event->tenantId);
        $this->assertSame(TenantEventType::WarDeclared, $event->type);
        $this->assertSame(123456, $event->subjectId);
        $this->assertSame([10014], $event->matchedAllianceIds);
        $this->assertSame('war:123456', $event->subjectKey());
        $this->assertSame('2026-08-08T22:00:00+00:00', $event->occurredAt->toIso8601String());
        $this->assertSame(hash('sha256', $this->fixtureBody()), $event->bodyDigest);
    }

    public function test_tampering_wrong_purpose_and_wrong_tenant_are_rejected_before_body_use(): void
    {
        $tampered = $this->signedFields($this->fixtureBody());
        $tampered['body'] = str_replace('123456', '123457', $tampered['body']);
        $this->assertRejected($tampered, TenantEventRejectionReason::AuthenticationFailed);

        $wrongPurpose = $this->signedFields($this->fixtureBody(), [
            'purpose' => 'tenant-callback',
        ]);
        $this->assertRejected($wrongPurpose, TenantEventRejectionReason::UnsupportedContract);

        $wrongTenant = $this->signedFields($this->fixtureBody(), [
            'tenant_id' => (string) Str::ulid(),
        ]);
        $this->assertRejected($wrongTenant, TenantEventRejectionReason::WrongTenant);
    }

    public function test_stale_and_future_transport_envelopes_are_rejected(): void
    {
        $stale = $this->signedFields($this->fixtureBody(), [
            'timestamp' => (string) now()->subSeconds(301)->getTimestamp(),
        ]);
        $future = $this->signedFields($this->fixtureBody(), [
            'timestamp' => (string) now()->addSeconds(31)->getTimestamp(),
        ]);

        $this->assertRejected($stale, TenantEventRejectionReason::ExpiredEnvelope);
        $this->assertRejected($future, TenantEventRejectionReason::ExpiredEnvelope);
    }

    public function test_unknown_private_and_unsupported_event_fields_fail_closed(): void
    {
        $private = $this->fixture();
        $private['account'] = ['discord_id' => 'private-canary'];
        $this->assertRejected(
            $this->signedFields($this->encode($private)),
            TenantEventRejectionReason::InvalidEventBody,
        );

        $unsupported = $this->fixture();
        $unsupported['type'] = 'account.updated';
        $this->assertRejected(
            $this->signedFields($this->encode($unsupported)),
            TenantEventRejectionReason::UnsupportedEventType,
        );

        $unknownTransport = $this->signedFields($this->fixtureBody());
        $unknownTransport['payload'] = 'private-canary';
        $this->assertRejected($unknownTransport, TenantEventRejectionReason::MalformedEnvelope);
    }

    public function test_malformed_oversized_and_future_event_bodies_are_rejected(): void
    {
        $this->assertRejected(
            $this->signedFields('{not-json'),
            TenantEventRejectionReason::InvalidEventBody,
        );

        config(['nexus.tenant_events.max_body_bytes' => 1_024]);
        $this->assertRejected(
            $this->signedFields(str_repeat('x', 1_025)),
            TenantEventRejectionReason::AuthenticationFailed,
        );

        config(['nexus.tenant_events.max_body_bytes' => 8_192]);
        $future = $this->fixture();
        $future['occurred_at'] = '2026-08-08T22:00:36Z';
        $this->assertRejected(
            $this->signedFields($this->encode($future)),
            TenantEventRejectionReason::FutureEvent,
        );
    }

    public function test_configuration_failures_never_disclose_key_material(): void
    {
        config(['nexus.tenant_events.key_file' => '/missing/private/tenant-event-key']);

        try {
            $this->authenticator()->verify($this->signedFields($this->fixtureBody()));
            $this->fail('A missing tenant-event key was accepted.');
        } catch (TenantEventConfigurationException $exception) {
            $this->assertSame('Tenant event transport is not safely configured.', $exception->getMessage());
            $this->assertStringNotContainsString($this->key, $exception->getMessage());
            $this->assertStringNotContainsString('123456', $exception->getMessage());
        }
    }

    /** @param array<string, string> $fields */
    private function assertRejected(array $fields, TenantEventRejectionReason $reason): void
    {
        try {
            $this->authenticator()->verify($fields);
            $this->fail("Tenant event was not rejected as [{$reason->value}].");
        } catch (TenantEventRejectedException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame('Tenant event was rejected.', $exception->getMessage());
            $this->assertStringNotContainsString($this->key, $exception->getMessage());
            $this->assertStringNotContainsString('private-canary', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedFields(string $body, array $overrides = []): array
    {
        $fields = array_replace([
            'body' => $body,
            'contract_version' => (string) TenantEventAuthenticator::CONTRACT_VERSION,
            'tenant_id' => self::TENANT_ID,
            'purpose' => TenantEventAuthenticator::PURPOSE,
            'timestamp' => (string) now()->getTimestamp(),
            'nonce' => str_repeat('a', 32),
            'body_sha256' => hash('sha256', $body),
        ], $overrides);
        $fields['signature'] = hash_hmac(
            'sha256',
            TenantEventAuthenticator::canonicalPayload(
                $fields['tenant_id'],
                $fields['timestamp'],
                $fields['nonce'],
                $fields['body_sha256'],
            ),
            $this->key,
        );

        return $fields;
    }

    private function fixtureBody(): string
    {
        $body = file_get_contents(base_path('tests/Fixtures/TenantEvents/tenant-event-v1.json'));
        $this->assertIsString($body);

        return trim($body);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode($this->fixtureBody(), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function authenticator(): TenantEventAuthenticator
    {
        return app(TenantEventAuthenticator::class);
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }
}
