<?php

namespace Tests\Feature\API;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordConnection;
use App\Models\DiscordQueue;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Discord\DiscordQueueClaimGate;
use App\Services\Discord\DiscordQueueService;
use App\Services\Discord\Relay\CanonicalJson;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DiscordQueueClaimFastPathTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICATION_ID = '123456789012345678';

    private const CONNECTION_ID = '11111111-2222-4333-8444-555555555555';

    private const GUILD_ID = '223456789012345678';

    private string $relaySecretKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.discord_bot_key', 'discord-test-token');
        Carbon::setTestNow('2026-11-26 12:00:00');

        $keypair = sodium_crypto_sign_seed_keypair(hash('sha256', static::class, true));
        $this->relaySecretKey = sodium_crypto_sign_secretkey($keypair);
        DiscordConnection::query()->create([
            'id' => self::CONNECTION_ID,
            'mode' => DiscordConnectionMode::Dedicated,
            'state' => DiscordConnectionState::Active,
            'application_id' => self::APPLICATION_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
            'protocol_version' => 2,
            'relay_current_key_id' => 'relay-current',
            'relay_current_public_key' => $this->base64Url(sodium_crypto_sign_publickey($keypair)),
            'capability_version' => 1,
            'capabilities' => [
                'capabilities' => ['relay.proof.v2', 'queue.connection-context.v1'],
                'supported_queue_actions' => array_column(DiscordQueueAction::cases(), 'value'),
            ],
            'v1_reader_enabled' => false,
            'activated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_second_consecutive_empty_claim_skips_the_queue_and_keeps_the_response_bytes(): void
    {
        $first = $this->claim();

        $queries = $this->recordQueries();
        $second = $this->claim();

        $this->assertSame(['data' => null, 'meta' => ['app_name' => config('app.name')]], $second->json());
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame([], $this->queueQueries($queries));
        $this->assertCount(1, $queries->sql, 'Only the relay credential lookup may reach the database.');
        $this->assertStringContainsString('discord_connections', $queries->sql[0]);
    }

    public function test_enqueue_makes_the_item_claimable_at_once(): void
    {
        $this->primeEmptyLane();

        $command = app(DiscordQueueService::class)->enqueue(
            DiscordQueueAction::CityTierSync,
            ['message' => 'Sync roles'],
            DiscordQueueLane::SideEffects,
        );

        $this->claim()->assertJsonPath('data.id', $command->id)
            ->assertJsonPath('data.status', DiscordQueueStatus::Processing->value);
    }

    public function test_enqueue_inside_a_transaction_invalidates_only_after_commit(): void
    {
        $this->primeEmptyLane();
        $gate = app(DiscordQueueClaimGate::class);
        $epochBefore = Cache::get(DiscordQueueClaimGate::EPOCH_KEY);

        DB::transaction(function () use ($epochBefore): void {
            app(DiscordQueueService::class)->enqueue(
                DiscordQueueAction::CityTierSync,
                ['message' => 'Sync roles'],
                DiscordQueueLane::SideEffects,
            );

            $this->assertSame($epochBefore, Cache::get(DiscordQueueClaimGate::EPOCH_KEY));
        });

        $this->assertNotSame($epochBefore, Cache::get(DiscordQueueClaimGate::EPOCH_KEY));
        $this->assertFalse($gate->isKnownEmpty([DiscordQueueLane::SideEffects], $this->connectionContext(), (string) Str::uuid()));
    }

    public function test_delayed_item_is_returned_once_it_is_due_even_inside_the_marker_ttl(): void
    {
        $command = app(DiscordQueueService::class)->enqueue(
            DiscordQueueAction::CityTierSync,
            ['message' => 'Sync roles'],
            DiscordQueueLane::SideEffects,
            availableAt: Carbon::now()->addSeconds(10),
        );

        $this->claim()->assertJsonPath('data', null);
        $this->claim()->assertJsonPath('data', null);

        Carbon::setTestNow(Carbon::now()->addSeconds(9));
        $this->claim()->assertJsonPath('data', null);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->claim()->assertJsonPath('data.id', $command->id);
    }

    public function test_failed_acknowledgement_requeue_invalidates_and_returns_the_item_when_due(): void
    {
        $command = $this->createCommand();
        $claim = $this->claim()->assertJsonPath('data.id', $command->id);
        $this->primeEmptyLane();

        $this->signedJson('POST', "/api/v1/discord/queue/{$command->id}/status", [
            'lease_token' => $claim->json('data.lease_token'),
            'status' => DiscordQueueStatus::Failed->value,
            'error_code' => 'discord_timeout',
            'result' => ['retry_after_ms' => 5_000],
        ])->assertOk()->assertJsonPath('data.status', DiscordQueueStatus::Pending->value);

        $this->assertLaneNotKnownEmpty();
        $this->claim()->assertJsonPath('data', null);

        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $this->claim()->assertJsonPath('data.id', $command->id)->assertJsonPath('data.attempts', 2);
    }

    public function test_reaped_lease_invalidates_and_returns_the_item_when_due(): void
    {
        $command = $this->createCommand();
        $this->claim()->assertJsonPath('data.id', $command->id);

        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->primeEmptyLane();

        Artisan::call('discord-queue:reap-leases');

        $this->assertSame(DiscordQueueStatus::Pending, $command->fresh()->status);
        $this->assertLaneNotKnownEmpty();

        Carbon::setTestNow($command->fresh()->available_at);
        $this->claim()->assertJsonPath('data.id', $command->id);
    }

    public function test_admin_retry_of_a_failed_item_makes_it_claimable_at_once(): void
    {
        $command = $this->createCommand(attributes: [
            'status' => DiscordQueueStatus::Failed,
            'attempts' => 3,
        ]);
        $this->primeEmptyLane();

        $command->forceFill([
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'last_error' => null,
        ])->save();

        $this->claim()->assertJsonPath('data.id', $command->id);
    }

    public function test_suppression_and_completion_do_not_invalidate_markers(): void
    {
        $command = $this->createCommand();
        $claim = $this->claim()->assertJsonPath('data.id', $command->id);
        $this->primeEmptyLane();
        $epochBefore = Cache::get(DiscordQueueClaimGate::EPOCH_KEY);

        $this->signedJson('POST', "/api/v1/discord/queue/{$command->id}/status", [
            'lease_token' => $claim->json('data.lease_token'),
            'status' => DiscordQueueStatus::Complete->value,
        ])->assertOk();

        $this->assertSame($epochBefore, Cache::get(DiscordQueueClaimGate::EPOCH_KEY));
    }

    public function test_marker_on_one_lane_does_not_hide_another_lane(): void
    {
        $this->primeEmptyLane(DiscordQueueLane::SideEffects);
        $alert = DiscordQueue::withoutEvents(fn (): DiscordQueue => $this->createCommand(
            'ALERT_DELIVERY_V1',
            ['lane' => DiscordQueueLane::Alerts],
        ));

        $this->claim(DiscordQueueLane::Alerts)->assertJsonPath('data.id', $alert->id);
    }

    public function test_replayed_claim_request_still_returns_its_lease_after_the_lane_is_marked_empty(): void
    {
        $command = $this->createCommand();
        $requestId = (string) Str::uuid();
        $workerId = (string) Str::uuid();

        $first = $this->claim(requestId: $requestId, workerId: $workerId)->assertJsonPath('data.id', $command->id);
        $this->primeEmptyLane();

        $this->claim(requestId: $requestId, workerId: $workerId)
            ->assertJsonPath('data.id', $command->id)
            ->assertJsonPath('data.lease_token', $first->json('data.lease_token'));
        $this->assertSame(1, $command->fresh()->attempts);
    }

    public function test_marker_expires_after_the_ttl_when_an_invalidation_is_missed(): void
    {
        $this->primeEmptyLane();
        $command = DiscordQueue::withoutEvents(fn (): DiscordQueue => $this->createCommand());

        $this->claim()->assertJsonPath('data', null);

        Carbon::setTestNow(Carbon::now()->addSeconds(DiscordQueueClaimGate::EMPTY_TTL_SECONDS));
        $this->claim()->assertJsonPath('data.id', $command->id);
    }

    public function test_concurrent_claims_never_receive_the_same_item(): void
    {
        $first = $this->createCommand(attributes: ['available_at' => Carbon::now()->subSeconds(2)]);
        $second = $this->createCommand(attributes: ['available_at' => Carbon::now()->subSecond()]);

        $ids = [
            $this->claim()->json('data.id'),
            $this->claim()->json('data.id'),
        ];
        $this->claim()->assertJsonPath('data', null);

        $this->assertSame([$first->id, $second->id], $ids);
        $this->assertSame(2, DiscordQueue::query()->where('status', DiscordQueueStatus::Processing->value)->count());
    }

    public function test_unauthenticated_and_badly_signed_claims_are_rejected_before_the_fast_path(): void
    {
        $this->primeEmptyLane();
        $gateReads = 0;
        Event::listen(RetrievingManyKeys::class, function () use (&$gateReads): void {
            $gateReads++;
        });

        $payload = $this->claimPayload(DiscordQueueLane::SideEffects, (string) Str::uuid(), (string) Str::uuid());
        $headers = $this->serviceHeaders('POST', '/api/v1/discord/queue/claim', json_encode($payload, JSON_THROW_ON_ERROR), 'queue.claim');

        $this->json('POST', '/api/v1/discord/queue/claim', $payload, ['Authorization' => 'Bearer wrong'] + $headers)
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => ['code' => 'unauthorized', 'message' => 'Discord bot authentication failed.'],
                'meta' => ['contract_version' => 1, 'app_name' => config('app.name')],
            ]);

        $forged = $headers;
        $forged['X-Nexus-Discord-Relay-Signature'] = str_repeat('0', 128);
        $this->json('POST', '/api/v1/discord/queue/claim', $payload, $forged)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'discord_relay_header_mismatch');

        $tampered = $payload;
        $tampered['worker_id'] = (string) Str::uuid();
        $this->json('POST', '/api/v1/discord/queue/claim', $tampered, $headers)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'discord_relay_request_binding_mismatch');

        $otherKeypair = sodium_crypto_sign_seed_keypair(hash('sha256', 'another relay', true));
        $this->relaySecretKey = sodium_crypto_sign_secretkey($otherKeypair);
        $this->json('POST', '/api/v1/discord/queue/claim', $payload, $this->serviceHeaders(
            'POST',
            '/api/v1/discord/queue/claim',
            json_encode($payload, JSON_THROW_ON_ERROR),
            'queue.claim',
        ))->assertUnauthorized()->assertJsonPath('error.code', 'invalid_discord_relay_signature');

        $this->assertSame(0, $gateReads);
    }

    public function test_revoked_or_unknown_connections_are_rejected_without_the_configured_fallback(): void
    {
        config([
            'services.discord.connection_id' => '99999999-2222-4333-8444-555555555555',
            'services.discord.application_id' => self::APPLICATION_ID,
            'services.discord.guild_id' => self::GUILD_ID,
            'services.discord.relay_protocol_version' => 2,
        ]);

        $resolver = app(DiscordConnectionResolver::class);
        $this->assertSame(self::CONNECTION_ID, $resolver->resolveForVerification(self::CONNECTION_ID)->connectionId);

        try {
            $resolver->resolveForVerification('99999999-2222-4333-8444-555555555555');
            $this->fail('An active persisted connection must supersede the configured fallback.');
        } catch (DiscordConnectionResolutionException $exception) {
            $this->assertSame('discord_connection_not_active', $exception->errorCode);
        }

        $this->primeEmptyLane();
        DiscordConnection::query()->whereKey(self::CONNECTION_ID)->update(['state' => DiscordConnectionState::Revoked->value]);

        $this->claim(assertOk: false)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_connection_not_active');
    }

    private function primeEmptyLane(DiscordQueueLane $lane = DiscordQueueLane::SideEffects): void
    {
        $this->claim($lane)->assertJsonPath('data', null);
        $this->assertTrue(app(DiscordQueueClaimGate::class)->isKnownEmpty(
            [$lane],
            $this->connectionContext(),
            (string) Str::uuid(),
        ));
    }

    private function assertLaneNotKnownEmpty(DiscordQueueLane $lane = DiscordQueueLane::SideEffects): void
    {
        $this->assertFalse(app(DiscordQueueClaimGate::class)->isKnownEmpty(
            [$lane],
            $this->connectionContext(),
            (string) Str::uuid(),
        ));
    }

    private function connectionContext(): DiscordConnectionContext
    {
        return app(DiscordConnectionResolver::class)->resolveForVerification(self::CONNECTION_ID);
    }

    /**
     * @return object{sql: list<string>}
     */
    private function recordQueries(): object
    {
        $log = new class
        {
            /** @var list<string> */
            public array $sql = [];
        };
        DB::listen(function (QueryExecuted $query) use ($log): void {
            $log->sql[] = $query->sql;
        });

        return $log;
    }

    /**
     * @param  object{sql: list<string>}  $queries
     * @return list<string>
     */
    private function queueQueries(object $queries): array
    {
        return array_values(array_filter(
            $queries->sql,
            fn (string $sql): bool => str_contains($sql, 'discord_queue'),
        ));
    }

    private function claim(
        DiscordQueueLane $lane = DiscordQueueLane::SideEffects,
        ?string $requestId = null,
        ?string $workerId = null,
        bool $assertOk = true,
    ): TestResponse {
        $response = $this->signedJson('POST', '/api/v1/discord/queue/claim', $this->claimPayload(
            $lane,
            $requestId ?? (string) Str::uuid(),
            $workerId ?? (string) Str::uuid(),
        ));

        return $assertOk ? $response->assertOk() : $response;
    }

    /** @return array<string, mixed> */
    private function claimPayload(DiscordQueueLane $lane, string $requestId, string $workerId): array
    {
        return [
            'worker_id' => $workerId,
            'request_id' => $requestId,
            'lanes' => [$lane->value],
            'connection_id' => self::CONNECTION_ID,
            'application_id' => self::APPLICATION_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
        ];
    }

    /** @param array<string, mixed> $data */
    private function signedJson(string $method, string $uri, array $data): TestResponse
    {
        $action = match (true) {
            str_ends_with($uri, '/queue/claim') => 'queue.claim',
            str_ends_with($uri, '/status') => 'queue.acknowledge',
            default => throw new \LogicException('Unsupported signed queue test endpoint: '.$uri),
        };
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->json($method, $uri, $data, $this->serviceHeaders($method, $uri, $body, $action));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCommand(string $action = 'CITY_TIER_SYNC', array $attributes = []): DiscordQueue
    {
        $queueAction = DiscordQueueAction::from($action);
        $lane = $attributes['lane'] ?? $queueAction->allowedLanes()[0];

        return DiscordQueue::query()->create(array_merge([
            'action' => $action,
            'payload' => ['message' => 'Test queue command'],
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => Carbon::now(),
            'lane' => $lane,
            'priority' => 50,
            'connection_id' => self::CONNECTION_ID,
            'application_id' => self::APPLICATION_ID,
            'connection_generation' => 7,
            'guild_id' => self::GUILD_ID,
            'dedupe_scope' => self::CONNECTION_ID.':7',
        ], $attributes));
    }

    /** @return array<string, string> */
    private function serviceHeaders(string $method, string $target, string $body, string $action): array
    {
        $document = [
            'contract' => 'relay-proof',
            'contract_version' => 2,
            'issuer' => 'discord-relay',
            'audience' => 'nexus',
            'key_scope' => 'discord-relay->nexus',
            'connection_id' => self::CONNECTION_ID,
            'app_id' => self::APPLICATION_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
            'key_id' => 'relay-current',
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->addSeconds(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => (string) Str::uuid(),
            'proof' => [
                'type' => 'service',
                'action' => $action,
                'nonce' => (string) Str::uuid(),
            ],
            'method' => $method,
            'normalized_path_query' => $target,
            'body_sha256' => hash('sha256', $body),
        ];
        $signature = sodium_crypto_sign_detached(
            "NEXUS-DISCORD-RELAY-PROOF-V2\n".CanonicalJson::encode($document),
            $this->relaySecretKey,
        );
        $document['signature'] = [
            'algorithm' => 'ed25519',
            'value' => bin2hex($signature),
        ];
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'Authorization' => 'Bearer discord-test-token',
            'Accept' => 'application/json',
            'X-Nexus-Discord-Relay-Payload' => $this->base64Url($json),
            'X-Nexus-Discord-Relay-Signature' => $document['signature']['value'],
            'X-Nexus-Discord-Relay-Timestamp' => (string) now()->timestamp,
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
