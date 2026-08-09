<?php

namespace Tests\Unit\Services;

use App\Services\SubscriptionEnvelopeAuthenticator;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class SubscriptionEnvelopeAuthenticatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('subscriptions.redis.connection', 'subscriptions');
        config()->set('subscriptions.redis.hmac_secret', str_repeat('s', 32));
        config()->set('subscriptions.redis.max_age_seconds', 300);
        config()->set('subscriptions.redis.future_tolerance_seconds', 30);
        config()->set('subscriptions.redis.replay_ttl_seconds', 600);
        config()->set('subscriptions.redis.replay_prefix', 'test:replay:');
    }

    public function test_it_verifies_the_documented_canonical_hmac_contract(): void
    {
        $fields = $this->signedFields();

        app(SubscriptionEnvelopeAuthenticator::class)->verify($fields);

        $this->addToAssertionCount(1);
    }

    public function test_it_verifies_the_exact_nexus_subs_raw_v1_fixture(): void
    {
        $fixture = $this->subscriptionFixture();
        $envelope = $fixture['envelope'];
        config()->set('subscriptions.redis.hmac_secret', $fixture['hmac_key']);

        $this->assertSame(
            $fixture['canonical_payload'],
            SubscriptionEnvelopeAuthenticator::canonicalPayload($envelope)
        );
        $this->assertSame(
            $envelope['signature'],
            hash_hmac('sha256', $fixture['canonical_payload'], $fixture['hmac_key'])
        );

        app(SubscriptionEnvelopeAuthenticator::class)->verify($envelope, false);
    }

    public function test_it_rejects_tampered_and_stale_envelopes(): void
    {
        $authenticator = app(SubscriptionEnvelopeAuthenticator::class);
        $fields = $this->signedFields();
        $fields['payload'] = '{"id":999}';

        try {
            $authenticator->verify($fields);
            $this->fail('Tampered envelope was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('signature is invalid', $exception->getMessage());
        }

        $fields = $this->signedFields(['received_at' => now()->subMinutes(10)->toIso8601String()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message is stale');
        $authenticator->verify($fields);
    }

    public function test_it_uses_one_atomic_redis_script_to_reserve_message_ids(): void
    {
        $client = Mockery::mock();
        $client->shouldReceive('rawCommand')
            ->once()
            ->withArgs(function (string $command, string $script, int $keyCount, string $key, string $streamId): bool {
                return $command === 'EVAL'
                    && str_contains($script, "redis.call('SET', KEYS[1], ARGV[1])")
                    && str_contains($script, "redis.call('PERSIST', KEYS[1])")
                    && $keyCount === 1
                    && $key === 'test:replay:'.hash('sha256', 'message-4242')
                    && $streamId === '1000-1';
            })
            ->andReturn(1);

        $connection = Mockery::mock();
        $connection->shouldReceive('client')->once()->andReturn($client);
        Redis::shouldReceive('connection')->once()->with('subscriptions')->andReturn($connection);

        app(SubscriptionEnvelopeAuthenticator::class)->reserveMessageId('message-4242', '1000-1');

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_message_id_reserved_by_another_stream_entry(): void
    {
        $client = Mockery::mock();
        $client->shouldReceive('rawCommand')->once()->andReturn(0);

        $connection = Mockery::mock();
        $connection->shouldReceive('client')->once()->andReturn($client);
        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has already been processed');

        app(SubscriptionEnvelopeAuthenticator::class)->reserveMessageId('message-4242', '1000-2');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 'message-4242',
            'schema_version' => '1',
            'model' => 'nation',
            'event' => 'update',
            'source' => 'single',
            'received_at' => now()->toIso8601String(),
            'payload' => '{"id":4242}',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedFields(array $overrides = []): array
    {
        $fields = $this->fields($overrides);
        $fields['signature'] = hash_hmac(
            'sha256',
            SubscriptionEnvelopeAuthenticator::canonicalPayload($fields),
            str_repeat('s', 32)
        );

        return $fields;
    }

    /**
     * @return array{fixture_version: string, hmac_key: string, canonical_payload: string, envelope: array<string, string>}
     */
    private function subscriptionFixture(): array
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Subscriptions/subscription-envelope-v1.json'));
        $this->assertIsString($contents);

        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);

        return $fixture;
    }
}
