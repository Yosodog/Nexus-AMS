<?php

namespace App\Console\Commands;

use App\Services\SubscriptionStreamConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('subs:consume-stream {--once : Process one read cycle and exit}')]
#[Description('Consume Politics & War subscription updates from Redis Streams')]
class ConsumeSubscriptionStream extends Command
{
    private bool $shouldKeepRunning = true;

    public function handle(SubscriptionStreamConsumer $consumer): int
    {
        if (defined('SIGTERM')) {
            $this->trap([SIGTERM, SIGQUIT], function () use ($consumer): void {
                $this->shouldKeepRunning = false;
                $consumer->resetConnection();
            });
        }

        $consumerGroupReady = false;

        do {
            try {
                if (! $consumerGroupReady) {
                    $consumer->ensureConsumerGroup();
                    $consumerGroupReady = true;
                    $this->components->info('Subscription stream consumer started.');
                }

                $consumer->consumeOnce();
            } catch (Throwable $exception) {
                Log::error('Subscription stream consumer connection failed.', [
                    'exception_class' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                $consumer->resetConnection();
                $consumerGroupReady = false;

                if ($this->option('once')) {
                    $this->components->error($exception->getMessage());

                    return self::FAILURE;
                }

                usleep(max((int) config('subscriptions.redis.retry_delay_ms'), 1) * 1000);
            }
        } while ($this->shouldKeepRunning && ! $this->option('once'));

        return self::SUCCESS;
    }
}
