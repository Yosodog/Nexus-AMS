<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RuntimeCapabilities;
use App\Services\TenantEvents\TenantEventStreamConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('nexus:consume-tenant-events {--once : Process one read cycle and exit}')]
#[Description('Consume signed routed events for this hosted Nexus tenant')]
class ConsumeTenantEvents extends Command
{
    private bool $shouldKeepRunning = true;

    public function handle(
        RuntimeCapabilities $capabilities,
        TenantEventStreamConsumer $consumer,
    ): int {
        if (! $capabilities->consumesTenantEvents()) {
            $this->components->info('Tenant events are not enabled for this runtime.');

            return self::SUCCESS;
        }

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
                    $this->components->info('Tenant event consumer started.');
                }

                $consumer->consumeOnce();
            } catch (Throwable $exception) {
                Log::error('Tenant event consumer connection failed.', [
                    'failure_code' => 'consumer_unavailable',
                    'exception_class' => $exception::class,
                ]);

                $consumer->resetConnection();
                $consumerGroupReady = false;

                if ($this->option('once')) {
                    $this->components->error('Tenant event consumer is unavailable.');

                    return self::FAILURE;
                }

                if ($this->shouldKeepRunning) {
                    usleep($this->retryDelayMilliseconds() * 1_000);
                }
            }
        } while ($this->shouldKeepRunning && ! $this->option('once'));

        return self::SUCCESS;
    }

    private function retryDelayMilliseconds(): int
    {
        $value = config('nexus.tenant_events.retry_delay_ms');

        return is_int($value) && $value >= 100 && $value <= 60_000
            ? $value
            : 2_000;
    }
}
