<?php

namespace App\Observers;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class StaffWorkQueueCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        if ($this->affectsProjection($model)) {
            $this->flush();
        }
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function restored(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        Cache::forget((string) config('pending_requests.cache_key', 'pending_requests.counts'));
        Cache::forget((string) config('pending_requests.projection_cache_key', 'pending_requests.work_queue.v1'));
    }

    private function affectsProjection(Model $model): bool
    {
        if (! $model instanceof Transaction) {
            return true;
        }

        $isPendingWithdrawal = static fn (mixed $type, mixed $pending): bool => $type === 'withdrawal'
            && filter_var($pending, FILTER_VALIDATE_BOOLEAN);

        return $isPendingWithdrawal($model->transaction_type, $model->is_pending)
            || $isPendingWithdrawal($model->getOriginal('transaction_type'), $model->getOriginal('is_pending'));
    }
}
