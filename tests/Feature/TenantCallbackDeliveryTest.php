<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TenantCallbackTransport;
use App\Enums\NexusRuntime;
use App\Enums\TenantCallbackStatus;
use App\Enums\TenantControlPurpose;
use App\Exceptions\TenantControlTransportException;
use App\Jobs\DeliverTenantCallback;
use App\Models\TenantCallbackDelivery;
use App\Services\RuntimeCapabilities;
use App\Services\TenantCallbacks\TenantCallbackDeliveryService;
use App\Services\TenantControl\TenantControlAuthenticator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class TenantCallbackDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK_URL = 'https://console.example.test/internal/tenant-callbacks';

    private const TENANT_ID = '01JZ0000000000000000000000';

    private string $key;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $keyFile = tempnam(sys_get_temp_dir(), 'nexus-callback-key-');
        $this->assertNotFalse($keyFile);
        $this->keyFile = $keyFile;
        $this->key = base64_encode(random_bytes(48));
        $this->assertNotFalse(file_put_contents($this->keyFile, $this->key."\n"));

        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.release_id' => 'release-callback-test',
            'nexus.control.callback_url' => self::CALLBACK_URL,
            'nexus.control.callback_key_file' => $this->keyFile,
            'nexus.control.callback_queue' => 'tenant-control',
            'nexus.control.callback_lease_seconds' => 90,
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

    public function test_signed_callback_is_delivered_once_without_disclosing_key_material(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();

        Http::fake(fn (Request $request) => $this->acceptedResponse($request));

        $this->deliveryService()->deliver($delivery->id);
        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();

        $this->assertSame(TenantCallbackStatus::Delivered, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNull($delivery->last_failure_code);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($delivery): bool {
            $nonce = $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE);
            app(TenantControlAuthenticator::class)->verify(
                TenantControlPurpose::TenantCallbackRequest,
                $request->body(),
                $this->requestAuthenticationHeaders($request),
                $nonce,
            );

            $decoded = json_decode($request->body(), true, 16, JSON_THROW_ON_ERROR);
            $serializedHeaders = json_encode($request->headers(), JSON_THROW_ON_ERROR);

            return $request->url() === self::CALLBACK_URL
                && $decoded['callback_id'] === $delivery->callback_id
                && $decoded['tenant_id'] === self::TENANT_ID
                && $decoded['release_id'] === $delivery->release_id
                && $decoded['payload'] === [
                    'bootstrap_redemption_id' => $delivery->payload['bootstrap_redemption_id'],
                    'cloud_user_id' => $delivery->payload['cloud_user_id'],
                    'local_user_id' => $delivery->payload['local_user_id'],
                    'mode' => $delivery->payload['mode'],
                    'nation_id' => $delivery->payload['nation_id'],
                ]
                && ! str_contains($request->body(), $this->key)
                && ! str_contains($serializedHeaders, $this->key);
        });
    }

    public function test_ambiguous_connection_failure_retries_same_effect_with_a_fresh_nonce(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        $bodies = [];
        $nonces = [];
        $attempt = 0;

        Http::fake(function (Request $request) use (&$attempt, &$bodies, &$nonces) {
            $attempt++;
            $bodies[] = $request->body();
            $nonces[] = $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE);

            if ($attempt === 1) {
                $failedConnection = Http::failedConnection('upstream details must not escape');

                return $failedConnection($request);
            }

            return $this->acceptedResponse($request, 'duplicate');
        });

        try {
            $this->deliveryService()->deliver($delivery->id);
            $this->fail('The ambiguous callback outcome did not request a retry.');
        } catch (TenantControlTransportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('connection_unknown_outcome', $exception->failureCode);
            $this->assertSame('Tenant control request failed.', $exception->getMessage());
            $this->assertStringNotContainsString(self::CALLBACK_URL, $exception->getMessage());
            $this->assertStringNotContainsString($this->key, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Retryable, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertSame('connection_unknown_outcome', $delivery->last_failure_code);
        $this->assertNotNull($delivery->next_attempt_at);

        $this->travelTo($delivery->next_attempt_at->addSecond());
        $this->deliveryService()->deliver($delivery->id);
        $delivery->refresh();

        $this->assertSame(TenantCallbackStatus::Delivered, $delivery->status);
        $this->assertSame(2, $delivery->attempt_count);
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertNotSame($nonces[0], $nonces[1]);
        Http::assertSentCount(2);
    }

    public function test_non_retryable_response_is_terminal_and_never_resends(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        Http::fake([self::CALLBACK_URL => Http::response('sensitive upstream body', 400)]);

        $this->deliveryService()->deliver($delivery->id);
        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Rejected, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertSame(400, $delivery->last_response_status);
        $this->assertSame('dependency_rejected_callback', $delivery->last_failure_code);
        $this->assertNull($delivery->next_attempt_at);
        Http::assertSentCount(1);
    }

    public function test_rate_limited_response_is_recorded_for_a_bounded_retry(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        Http::fake([self::CALLBACK_URL => Http::response('ignored upstream body', 429)]);

        try {
            $this->deliveryService()->deliver($delivery->id);
            $this->fail('A retryable callback response was treated as successful.');
        } catch (TenantControlTransportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('dependency_retryable_response', $exception->failureCode);
            $this->assertSame(429, $exception->responseStatus);
        }

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Retryable, $delivery->status);
        $this->assertSame(429, $delivery->last_response_status);
        $this->assertSame(now()->addSeconds(15)->timestamp, $delivery->next_attempt_at?->timestamp);
    }

    public function test_success_status_with_an_unsigned_response_is_terminally_rejected(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        Http::fake([self::CALLBACK_URL => Http::response('{"status":"accepted"}', 202)]);

        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Rejected, $delivery->status);
        $this->assertSame('callback_response_authentication_failed', $delivery->last_failure_code);
        $this->assertSame(202, $delivery->last_response_status);
    }

    public function test_signed_response_accepts_json_object_keys_in_any_order(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();

        Http::fake(function (Request $request) use ($delivery) {
            $body = json_encode([
                'status' => 'accepted',
                'callback_id' => $delivery->callback_id,
                'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $signed = app(TenantControlAuthenticator::class)->sign(
                TenantControlPurpose::TenantCallbackResponse,
                $body,
                nonce: $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE),
            );

            return Http::response($body, 202, $signed->headers);
        });

        $this->deliveryService()->deliver($delivery->id);

        $this->assertSame(TenantCallbackStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_signed_response_with_an_extra_field_is_terminally_rejected(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();

        Http::fake(fn (Request $request) => $this->signedResponse($request, [
            'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
            'callback_id' => $delivery->callback_id,
            'status' => 'accepted',
            'unexpected' => 'field',
        ]));

        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Rejected, $delivery->status);
        $this->assertSame('invalid_callback_response', $delivery->last_failure_code);
        $this->assertSame(202, $delivery->last_response_status);
    }

    public function test_oversized_response_is_terminally_rejected(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        Http::fake([
            self::CALLBACK_URL => Http::response(str_repeat('a', 8_193), 202, [
                'Content-Length' => '8193',
            ]),
        ]);

        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Rejected, $delivery->status);
        $this->assertSame('invalid_control_response', $delivery->last_failure_code);
        $this->assertSame(202, $delivery->last_response_status);
    }

    public function test_transfer_limit_wrapped_by_http_client_remains_terminal(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        Http::fake(fn () => throw new ConnectionException(
            'HTTP client wrapper details',
            previous: new TenantControlTransportException(
                failureCode: 'invalid_control_response',
                retryable: false,
                responseStatus: 202,
            ),
        ));

        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Rejected, $delivery->status);
        $this->assertSame('invalid_control_response', $delivery->last_failure_code);
        $this->assertSame(202, $delivery->last_response_status);
    }

    public function test_key_unavailability_during_response_verification_is_retryable(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();

        Http::fake(function (Request $request) {
            $response = $this->acceptedResponse($request);
            unlink($this->keyFile);

            return $response;
        });

        try {
            $this->deliveryService()->deliver($delivery->id);
            $this->fail('Transient control-key unavailability was accepted as terminal.');
        } catch (TenantControlTransportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('configuration_unavailable', $exception->failureCode);
            $this->assertSame(202, $exception->responseStatus);
            $this->assertSame('Tenant control request failed.', $exception->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Retryable, $delivery->status);
        $this->assertSame('configuration_unavailable', $delivery->last_failure_code);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    public function test_unexpected_transport_failure_is_retried_without_logging_sensitive_details(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        $sensitiveDetail = 'private-provider-diagnostic';
        Log::spy();
        $this->app->bind(
            TenantCallbackTransport::class,
            fn (): TenantCallbackTransport => new class($sensitiveDetail) implements TenantCallbackTransport
            {
                public function __construct(private readonly string $sensitiveDetail) {}

                public function send(TenantCallbackDelivery $delivery): void
                {
                    throw new RuntimeException($this->sensitiveDetail);
                }
            },
        );

        try {
            $this->deliveryService()->deliver($delivery->id);
            $this->fail('An unexpected callback transport failure was swallowed.');
        } catch (TenantControlTransportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('unexpected_transport_failure', $exception->failureCode);
            $this->assertStringNotContainsString($sensitiveDetail, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Tenant callback transport raised an unexpected exception.'
                && $context === [
                    'delivery_id' => $delivery->id,
                    'exception' => RuntimeException::class,
                ]
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $sensitiveDetail),
        );
        $this->assertDatabaseHas('tenant_callback_deliveries', [
            'id' => $delivery->id,
            'status' => TenantCallbackStatus::Retryable->value,
            'last_failure_code' => 'unexpected_transport_failure',
        ]);
    }

    public function test_delivery_for_another_tenant_is_rejected_before_network_access(): void
    {
        Http::preventStrayRequests();
        $delivery = TenantCallbackDelivery::factory()->create([
            'tenant_id' => '01JZ1111111111111111111111',
        ]);

        $this->deliveryService()->deliver($delivery->id);

        $delivery->refresh();
        $this->assertSame(TenantCallbackStatus::Rejected, $delivery->status);
        $this->assertSame('invalid_callback_identity', $delivery->last_failure_code);
        Http::assertNothingSent();
    }

    public function test_dispatch_command_queues_only_due_or_abandoned_deliveries(): void
    {
        Queue::fake();

        $pending = TenantCallbackDelivery::factory()->create();
        $due = TenantCallbackDelivery::factory()->create([
            'status' => TenantCallbackStatus::Retryable,
            'next_attempt_at' => now()->subSecond(),
        ]);
        $abandoned = TenantCallbackDelivery::factory()->create([
            'status' => TenantCallbackStatus::Delivering,
            'last_attempted_at' => now()->subSeconds(91),
        ]);
        $future = TenantCallbackDelivery::factory()->create([
            'status' => TenantCallbackStatus::Retryable,
            'next_attempt_at' => now()->addMinute(),
        ]);
        $leased = TenantCallbackDelivery::factory()->create([
            'status' => TenantCallbackStatus::Delivering,
            'last_attempted_at' => now(),
        ]);
        $delivered = TenantCallbackDelivery::factory()->create([
            'status' => TenantCallbackStatus::Delivered,
            'delivered_at' => now(),
        ]);

        $this->artisan('nexus:dispatch-tenant-callbacks', ['--limit' => 100])
            ->expectsOutputToContain('Dispatched 3 tenant callback(s).')
            ->assertSuccessful();

        foreach ([$pending, $due, $abandoned] as $expected) {
            Queue::assertPushed(
                DeliverTenantCallback::class,
                fn (DeliverTenantCallback $job): bool => $job->deliveryId === $expected->id
                    && $job->queue === 'tenant-control',
            );
        }

        foreach ([$future, $leased, $delivered] as $unexpected) {
            Queue::assertNotPushed(
                DeliverTenantCallback::class,
                fn (DeliverTenantCallback $job): bool => $job->deliveryId === $unexpected->id,
            );
        }
    }

    public function test_standalone_dispatch_short_circuits_before_database_key_and_network_access(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $this->configureRuntime(NexusRuntime::Standalone);
        config([
            'nexus.control.callback_url' => null,
            'nexus.control.callback_key_file' => '/missing/private/key',
        ]);
        Schema::drop('tenant_callback_deliveries');

        try {
            $this->artisan('nexus:dispatch-tenant-callbacks')
                ->expectsOutputToContain('Tenant callbacks are not enabled for this runtime.')
                ->assertSuccessful();
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        } finally {
            $this->migration()->up();
        }
    }

    public function test_failed_job_marks_an_unfinished_delivery_exhausted(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create([
            'status' => TenantCallbackStatus::Retryable,
            'attempt_count' => 6,
            'next_attempt_at' => now(),
        ]);
        $job = new DeliverTenantCallback($delivery->id);

        $job->failed(null);

        $this->assertSame('tenant-callback-delivery:'.$delivery->id, $job->uniqueId());
        $this->assertSame(6, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertSame(180, $job->uniqueFor);
        $this->assertSame([15, 60, 300, 900, 1_800], $job->backoff());
        $this->assertDatabaseHas('tenant_callback_deliveries', [
            'id' => $delivery->id,
            'status' => TenantCallbackStatus::Exhausted->value,
            'last_failure_code' => 'retry_exhausted',
            'next_attempt_at' => null,
        ]);
    }

    public function test_callback_identity_and_payload_are_immutable(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        $payload = $delivery->payload;
        $payload['nation_id']++;
        $delivery->payload = $payload;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Tenant callback field [payload] is immutable.');
        $delivery->save();
    }

    public function test_migration_resumes_and_repairs_a_missing_due_index_without_losing_rows(): void
    {
        $delivery = TenantCallbackDelivery::factory()->create();
        Schema::table('tenant_callback_deliveries', function (Blueprint $table): void {
            $table->dropIndex('tenant_callback_deliveries_due_index');
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex(
            'tenant_callback_deliveries',
            ['status', 'next_attempt_at'],
        ));
        $this->assertDatabaseHas('tenant_callback_deliveries', [
            'id' => $delivery->id,
            'callback_id' => $delivery->callback_id,
        ]);
    }

    public function test_migration_refuses_an_incomplete_preexisting_table_for_forward_recovery(): void
    {
        Schema::drop('tenant_callback_deliveries');
        Schema::create('tenant_callback_deliveries', function (Blueprint $table): void {
            $table->id();
        });

        try {
            $this->migration()->up();
            $this->fail('The incomplete callback delivery table was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The tenant_callback_deliveries table is incomplete; repair it before resuming migration.',
                $exception->getMessage(),
            );
        } finally {
            Schema::dropIfExists('tenant_callback_deliveries');
            $this->migration()->up();
        }
    }

    private function acceptedResponse(Request $request, string $status = 'accepted'): mixed
    {
        return $this->signedResponse($request, [
            'contract_version' => TenantControlAuthenticator::CONTRACT_VERSION,
            'callback_id' => json_decode($request->body(), true, 16, JSON_THROW_ON_ERROR)['callback_id'],
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function signedResponse(Request $request, array $payload): mixed
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $nonce = $this->requestHeader($request, TenantControlAuthenticator::HEADER_NONCE);
        $signed = app(TenantControlAuthenticator::class)->sign(
            TenantControlPurpose::TenantCallbackResponse,
            $body,
            nonce: $nonce,
        );

        return Http::response($body, 202, $signed->headers);
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

    private function deliveryService(): TenantCallbackDeliveryService
    {
        return app(TenantCallbackDeliveryService::class);
    }

    private function configureRuntime(NexusRuntime $runtime): void
    {
        config(['nexus.runtime' => $runtime->value]);
        $this->forgetRuntimeSingletons();
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_08_213619_create_tenant_callback_deliveries_table.php',
        );
    }
}
