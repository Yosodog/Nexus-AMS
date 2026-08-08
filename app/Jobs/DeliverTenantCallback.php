<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TenantCallbacks\TenantCallbackDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class DeliverTenantCallback implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 6;

    public int $timeout = 45;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $deliveryId)
    {
        $queue = config('nexus.control.callback_queue');
        $this->onQueue(
            is_string($queue) && preg_match('/\A[a-zA-Z0-9:_-]{1,64}\z/D', $queue) === 1
                ? $queue
                : 'default',
        );
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 300, 900, 1_800];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return "tenant-callback-delivery:{$this->deliveryId}";
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['tenant-callback', "delivery:{$this->deliveryId}"];
    }

    public function handle(TenantCallbackDeliveryService $deliveryService): void
    {
        $deliveryService->deliver($this->deliveryId);
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantCallbackDeliveryService::class)->markExhausted($this->deliveryId);
    }
}
