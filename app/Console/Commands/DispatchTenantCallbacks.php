<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantCallbackStatus;
use App\Jobs\DeliverTenantCallback;
use App\Models\TenantCallbackDelivery;
use App\Services\RuntimeCapabilities;
use App\Services\TenantCallbacks\TenantCallbackDeliveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('nexus:dispatch-tenant-callbacks {--limit=100 : Maximum callbacks to dispatch}')]
#[Description('Dispatch due tenant control callbacks from the durable outbox')]
final class DispatchTenantCallbacks extends Command
{
    public function handle(
        RuntimeCapabilities $capabilities,
        TenantCallbackDeliveryService $deliveryService,
    ): int {
        if (! $capabilities->sendsTenantCallbacks()) {
            $this->components->info('Tenant callbacks are not enabled for this runtime.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            $this->components->error('The callback dispatch limit must be between 1 and 500.');

            return self::INVALID;
        }

        $staleBefore = now()->subSeconds($deliveryService->leaseSeconds());
        $deliveryIds = TenantCallbackDelivery::query()
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->where('status', TenantCallbackStatus::Pending->value)
                    ->orWhere(function (Builder $retryable): void {
                        $retryable->where('status', TenantCallbackStatus::Retryable->value)
                            ->where(function (Builder $due): void {
                                $due->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', now());
                            });
                    })
                    ->orWhere(function (Builder $delivering) use ($staleBefore): void {
                        $delivering->where('status', TenantCallbackStatus::Delivering->value)
                            ->where(function (Builder $stale) use ($staleBefore): void {
                                $stale->whereNull('last_attempted_at')
                                    ->orWhere('last_attempted_at', '<=', $staleBefore);
                            });
                    });
            })
            ->oldest('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($deliveryIds as $deliveryId) {
            DeliverTenantCallback::dispatch((int) $deliveryId);
        }

        $this->components->info("Dispatched {$deliveryIds->count()} tenant callback(s).");

        return self::SUCCESS;
    }
}
