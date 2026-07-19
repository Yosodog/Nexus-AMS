<?php

namespace Tests\Integration;

use App\Jobs\UpdateNationJob;
use App\Services\SubscriptionEventProcessor;
use App\Services\SubscriptionStreamConsumer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SubscriptionStreamConsumerTest extends TestCase
{
    private string $stream;

    private string $group;

    private string $deadLetterFile;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->stream = "test:nexus:subscriptions:{$suffix}";
        $this->group = "test-nexus-{$suffix}";
        $this->deadLetterFile = sys_get_temp_dir()."/nexus-subscription-dead-letter-{$suffix}.jsonl";

        config()->set('database.redis.subscriptions.url', env('SUBS_REDIS_TEST_URL', 'redis://127.0.0.1:6379/15'));
        config()->set('database.redis.options.prefix', 'must-not-prefix-subscriptions:');
        config()->set('database.redis.subscriptions.prefix', '');
        config()->set('subscriptions.redis.connection', 'subscriptions');
        config()->set('subscriptions.redis.stream', $this->stream);
        config()->set('subscriptions.redis.group', $this->group);
        config()->set('subscriptions.redis.consumer', "consumer-{$suffix}");
        config()->set('subscriptions.redis.block_ms', 10);
        config()->set('subscriptions.redis.claim_idle_ms', 1);
        config()->set('subscriptions.redis.max_deliveries', 1);
        config()->set('subscriptions.redis.dead_letter_file', $this->deadLetterFile);

        Redis::purge('subscriptions');
        Redis::connection('subscriptions')->client()->rawCommand('DEL', $this->stream);
    }

    protected function tearDown(): void
    {
        Redis::connection('subscriptions')->client()->rawCommand('DEL', $this->stream);
        Redis::purge('subscriptions');

        if (is_file($this->deadLetterFile)) {
            unlink($this->deadLetterFile);
        }

        parent::tearDown();
    }

    public function test_command_dispatches_and_removes_a_valid_stream_message(): void
    {
        Queue::fake();
        Log::spy();
        $consumer = app(SubscriptionStreamConsumer::class);
        $consumer->ensureConsumerGroup();
        $this->assertNull(Redis::connection('subscriptions')->client()->getOption(\Redis::OPT_PREFIX));
        $this->publish(['id' => 4242]);

        $this->artisan('subs:consume-stream --once')->assertSuccessful();

        Queue::assertPushed(UpdateNationJob::class, fn (UpdateNationJob $job): bool => $job->nationsData === [['id' => 4242]]);
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'Processed subscription stream message.'
                && $context['message_id'] === 'message-4242'
                && $context['model'] === 'nation'
                && $context['event'] === 'update'
                && $context['source'] === 'single'
                && $context['record_count'] === 1
                && $context['representative_ids'] === [4242]
                && ! array_key_exists('payload', $context)
        )->once();
        $this->assertSame(0, (int) $this->raw('XLEN', $this->stream));
        $this->assertSame(0, (int) $this->raw('XPENDING', $this->stream, $this->group)[0]);
    }

    public function test_it_reclaims_a_message_abandoned_by_another_consumer(): void
    {
        $processor = Mockery::mock(SubscriptionEventProcessor::class);
        $processor->shouldReceive('process')->once()->with('nation', 'update', ['id' => 4242]);

        $consumer = new SubscriptionStreamConsumer($processor);
        $consumer->ensureConsumerGroup();
        $this->publish(['id' => 4242]);

        $this->raw('XREADGROUP', 'GROUP', $this->group, 'abandoned-consumer', 'COUNT', 1, 'STREAMS', $this->stream, '>');
        usleep(2000);

        $this->assertSame(1, $consumer->consumeOnce());
        $this->assertSame(0, (int) $this->raw('XPENDING', $this->stream, $this->group)[0]);
        $this->assertSame(0, (int) $this->raw('XLEN', $this->stream));
    }

    public function test_it_dead_letters_a_message_after_the_maximum_delivery_count(): void
    {
        config()->set('subscriptions.redis.max_deliveries', 5);
        $processor = Mockery::mock(SubscriptionEventProcessor::class);
        $processor->shouldReceive('process')->times(5)->andThrow(new RuntimeException('Temporary processor failure.'));

        $consumer = new SubscriptionStreamConsumer($processor);
        $consumer->ensureConsumerGroup();
        $this->publish(['id' => 4242]);

        for ($delivery = 1; $delivery <= 5; $delivery++) {
            $this->assertSame(1, $consumer->consumeOnce());

            if ($delivery < 5) {
                $this->assertSame(1, (int) $this->raw('XLEN', $this->stream));
                $this->assertSame(1, (int) $this->raw('XPENDING', $this->stream, $this->group)[0]);
                usleep(2000);
            }
        }

        $this->assertSame(0, (int) $this->raw('XLEN', $this->stream));
        $this->assertFileExists($this->deadLetterFile);
        $this->assertStringContainsString('Temporary processor failure.', file_get_contents($this->deadLetterFile));
    }

    public function test_it_dead_letters_and_removes_an_unsupported_message(): void
    {
        $consumer = app(SubscriptionStreamConsumer::class);
        $consumer->ensureConsumerGroup();
        $this->publish(['id' => 4242], ['schema_version' => '99']);

        $this->assertSame(1, $consumer->consumeOnce());
        $this->assertSame(0, (int) $this->raw('XLEN', $this->stream));
        $this->assertSame(0, (int) $this->raw('XPENDING', $this->stream, $this->group)[0]);
        $this->assertStringContainsString('Unsupported subscription schema version', file_get_contents($this->deadLetterFile));
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @param  array<string, string>  $overrides
     */
    private function publish(array $payload, array $overrides = []): string
    {
        $fields = array_merge([
            'message_id' => 'message-4242',
            'schema_version' => '1',
            'model' => 'nation',
            'event' => 'update',
            'source' => 'single',
            'received_at' => now()->toIso8601String(),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ], $overrides);
        $arguments = ['XADD', $this->stream, '*'];

        foreach ($fields as $field => $value) {
            $arguments[] = $field;
            $arguments[] = $value;
        }

        return (string) $this->raw(...$arguments);
    }

    private function raw(string ...$arguments): mixed
    {
        return Redis::connection('subscriptions')->client()->rawCommand(...$arguments);
    }
}
