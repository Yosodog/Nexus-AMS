<?php

declare(strict_types=1);

namespace App\Services\TenantCallbacks;

use App\Contracts\TenantCallbackTransport;
use App\Enums\TenantCallbackStatus;
use App\Exceptions\TenantCallbackTransportException;
use App\Models\TenantCallbackDelivery;
use App\Services\RuntimeCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TenantCallbackDeliveryService
{
    public function __construct(
        private RuntimeCapabilities $capabilities,
        private TenantCallbackTransport $transport,
    ) {}

    public function deliver(int $deliveryId): void
    {
        if (! $this->capabilities->sendsTenantCallbacks()) {
            return;
        }

        $delivery = $this->claim($deliveryId);

        if ($delivery === null) {
            return;
        }

        try {
            $this->transport->send($delivery);
        } catch (TenantCallbackTransportException $exception) {
            $this->recordFailure($deliveryId, $exception);

            if ($exception->retryable) {
                throw $exception;
            }

            return;
        } catch (Throwable $exception) {
            Log::warning('Tenant callback transport raised an unexpected exception.', [
                'delivery_id' => $deliveryId,
                'exception' => $exception::class,
            ]);
            $safeException = new TenantCallbackTransportException(
                failureCode: 'unexpected_transport_failure',
                retryable: true,
            );
            $this->recordFailure($deliveryId, $safeException);

            throw $safeException;
        }

        $this->markDelivered($deliveryId);
    }

    public function markExhausted(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = TenantCallbackDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($delivery === null || $delivery->isTerminal()) {
                return;
            }

            $delivery->forceFill([
                'status' => TenantCallbackStatus::Exhausted,
                'last_failure_code' => 'retry_exhausted',
                'next_attempt_at' => null,
            ])->save();
        });
    }

    public function leaseSeconds(): int
    {
        $lease = config('nexus.control.callback_lease_seconds');

        return is_int($lease) && $lease >= 30 && $lease <= 600
            ? $lease
            : 90;
    }

    private function claim(int $deliveryId): ?TenantCallbackDelivery
    {
        return DB::transaction(function () use ($deliveryId): ?TenantCallbackDelivery {
            $delivery = TenantCallbackDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($delivery === null || $delivery->isTerminal()) {
                return null;
            }

            if ($delivery->status === TenantCallbackStatus::Retryable
                && $delivery->next_attempt_at?->isFuture()) {
                return null;
            }

            if ($delivery->status === TenantCallbackStatus::Delivering
                && $delivery->last_attempted_at?->isAfter(now()->subSeconds($this->leaseSeconds()))) {
                return null;
            }

            $delivery->forceFill([
                'status' => TenantCallbackStatus::Delivering,
                'attempt_count' => min($delivery->attempt_count + 1, 65_535),
                'last_attempted_at' => now(),
                'last_response_status' => null,
                'last_failure_code' => null,
                'next_attempt_at' => null,
            ])->save();

            return $delivery->fresh();
        });
    }

    private function recordFailure(
        int $deliveryId,
        TenantCallbackTransportException $exception,
    ): void {
        DB::transaction(function () use ($deliveryId, $exception): void {
            $delivery = TenantCallbackDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($delivery === null || $delivery->isTerminal()) {
                return;
            }

            $delivery->forceFill([
                'status' => $exception->retryable
                    ? TenantCallbackStatus::Retryable
                    : TenantCallbackStatus::Rejected,
                'last_response_status' => $exception->responseStatus,
                'last_failure_code' => $exception->failureCode,
                'next_attempt_at' => $exception->retryable
                    ? now()->addSeconds($this->retryDelay($delivery->attempt_count))
                    : null,
            ])->save();
        });
    }

    private function markDelivered(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = TenantCallbackDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($delivery === null || $delivery->isTerminal()) {
                return;
            }

            $delivery->forceFill([
                'status' => TenantCallbackStatus::Delivered,
                'last_failure_code' => null,
                'next_attempt_at' => null,
                'delivered_at' => now(),
            ])->save();
        });
    }

    private function retryDelay(int $attemptCount): int
    {
        return match (true) {
            $attemptCount <= 1 => 15,
            $attemptCount === 2 => 60,
            $attemptCount === 3 => 300,
            $attemptCount === 4 => 900,
            default => 1_800,
        };
    }
}
