<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\NexusRuntime;
use App\Events\WarDeclared;
use App\Models\War;
use App\Services\RuntimeCapabilities;
use App\Services\TenantEvents\TenantEventAuthenticator;
use App\Services\TenantEvents\TenantEventStreamConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TenantEventStreamConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = '01KZHV17VQ9S6GDGBK0QJ5GF1Z';

    private string $key;

    private string $keyFile;

    private string $stream;

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
            'nexus.tenant_events.consumer' => 'integration-consumer',
            'nexus.tenant_events.block_ms' => 10,
            'nexus.tenant_events.read_count' => 10,
            'nexus.tenant_events.claim_idle_ms' => 1,
            'nexus.tenant_events.max_deliveries' => 5,
            'nexus.tenant_events.max_age_seconds' => 300,
            'nexus.tenant_events.future_tolerance_seconds' => 30,
            'database.redis.options.prefix' => 'must-not-prefix-tenant-events:',
            'database.redis.tenant_events.url' => env(
                'TENANT_EVENTS_REDIS_TEST_URL',
                'redis://127.0.0.1:6379/15',
            ),
            'database.redis.tenant_events.prefix' => '',
        ]);
        $this->forgetRuntimeSingletons();
        Redis::purge('tenant_events');
        $this->stream = app(TenantEventStreamConsumer::class)->streamName();
        $this->raw('DEL', $this->stream);
        $this->travelTo('2026-08-08 22:00:05 UTC');
    }

    protected function tearDown(): void
    {
        if (isset($this->stream)) {
            $this->raw('DEL', $this->stream);
        }

        Redis::purge('tenant_events');

        if (isset($this->keyFile) && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    public function test_command_processes_and_acknowledges_a_signed_event_once(): void
    {
        Event::fake([WarDeclared::class]);
        $this->createWar();
        $fields = $this->signedFields($this->fixtureBody());
        $this->publish($fields);

        $this->artisan('nexus:consume-tenant-events', ['--once' => true])
            ->expectsOutputToContain('Tenant event consumer started.')
            ->assertSuccessful();

        $this->publish($this->signedFields($this->fixtureBody(), str_repeat('b', 32)));
        $this->artisan('nexus:consume-tenant-events', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseCount('tenant_event_receipts', 1);
        $this->assertDatabaseCount('war_declaration_receipts', 1);
        $this->assertSame(0, $this->pendingCount());
        $this->assertSame(2, (int) $this->raw('XLEN', $this->stream));
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_consumer_reclaims_a_pending_event_after_another_process_stops(): void
    {
        Event::fake([WarDeclared::class]);
        $this->createWar();
        $consumer = app(TenantEventStreamConsumer::class);
        $consumer->ensureConsumerGroup();
        $this->publish($this->signedFields($this->fixtureBody()));
        $this->raw(
            'XREADGROUP',
            'GROUP',
            'nexus-ams-v1',
            'abandoned-consumer',
            'COUNT',
            '1',
            'STREAMS',
            $this->stream,
            '>',
        );
        usleep(5_000);

        $this->assertSame(1, $consumer->consumeOnce());
        $this->assertSame(0, $this->pendingCount());
        $this->assertDatabaseCount('tenant_event_receipts', 1);
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_retryable_missing_world_row_remains_pending_then_commits_after_projection(): void
    {
        Event::fake([WarDeclared::class]);
        $consumer = app(TenantEventStreamConsumer::class);
        $consumer->ensureConsumerGroup();
        $this->publish($this->signedFields($this->fixtureBody()));

        $this->assertSame(1, $consumer->consumeOnce());
        $this->assertSame(1, $this->pendingCount());
        $this->assertDatabaseCount('tenant_event_receipts', 0);

        $this->createWar();
        usleep(5_000);
        $this->assertSame(1, $consumer->consumeOnce());
        $this->assertSame(0, $this->pendingCount());
        $this->assertDatabaseCount('tenant_event_receipts', 1);
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_tampered_event_is_acknowledged_without_payload_or_key_logging(): void
    {
        Log::spy();
        $body = $this->fixtureBody();
        $fields = $this->signedFields($body);
        $fields['body'] = str_replace('123456', 'private-canary', $body);
        $this->publish($fields);

        $this->artisan('nexus:consume-tenant-events', ['--once' => true])->assertSuccessful();

        $this->assertSame(0, $this->pendingCount());
        $this->assertDatabaseCount('tenant_event_receipts', 0);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'Rejected tenant event.'
                && ($context['failure_code'] ?? null) === 'authentication_failed'
                && ! str_contains($encoded, 'private-canary')
                && ! str_contains($encoded, $this->key);
        })->once();
    }

    /** @param array<string, string> $fields */
    private function publish(array $fields): string
    {
        $arguments = ['XADD', $this->stream, '*'];

        foreach ($fields as $field => $value) {
            $arguments[] = $field;
            $arguments[] = $value;
        }

        return (string) $this->raw(...$arguments);
    }

    /** @return array<string, string> */
    private function signedFields(string $body, string $nonce = ''): array
    {
        $timestamp = (string) now()->getTimestamp();
        $nonce = $nonce !== '' ? $nonce : str_repeat('a', 32);
        $bodyDigest = hash('sha256', $body);

        return [
            'body' => $body,
            'contract_version' => (string) TenantEventAuthenticator::CONTRACT_VERSION,
            'tenant_id' => self::TENANT_ID,
            'purpose' => TenantEventAuthenticator::PURPOSE,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'body_sha256' => $bodyDigest,
            'signature' => hash_hmac(
                'sha256',
                TenantEventAuthenticator::canonicalPayload(
                    self::TENANT_ID,
                    $timestamp,
                    $nonce,
                    $bodyDigest,
                ),
                $this->key,
            ),
        ];
    }

    private function pendingCount(): int
    {
        $pending = $this->raw('XPENDING', $this->stream, 'nexus-ams-v1');

        return is_array($pending) && isset($pending[0]) ? (int) $pending[0] : 0;
    }

    private function fixtureBody(): string
    {
        $body = file_get_contents(base_path('tests/Fixtures/TenantEvents/tenant-event-v1.json'));
        $this->assertIsString($body);

        return trim($body);
    }

    private function createWar(): War
    {
        return War::query()->create([
            'id' => 123456,
            'date' => now(),
            'reason' => 'Tenant event integration test',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => 10,
            'att_alliance_id' => 10014,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 20,
            'def_alliance_id' => 20028,
            'def_alliance_position' => 'OFFICER',
        ]);
    }

    private function raw(string ...$arguments): mixed
    {
        return Redis::connection('tenant_events')->client()->rawCommand(...$arguments);
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }
}
