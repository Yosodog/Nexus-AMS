<?php

namespace App\Observers;

use App\Models\Application;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\BlockadeReliefRequest;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\RebuildingRequest;
use App\Models\Transaction;
use App\Models\WarAidRequest;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class StaffWorkQueueCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly StaffWorkQueueRegistry $registry) {}

    public function saved(Model $model): void
    {
        if ($this->affectsProjection($model)) {
            $this->flush($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->flush($model);
    }

    public function restored(Model $model): void
    {
        $this->flush($model);
    }

    private function flush(Model $model): void
    {
        Cache::forget((string) config('pending_requests.cache_key', 'pending_requests.counts'));
        $this->registry->flushCache($this->sourceType($model));
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

    private function sourceType(Model $model): string
    {
        return match (true) {
            $model instanceof Application => 'applications',
            $model instanceof AuditResult, $model instanceof AuditResultEvent => 'audit_remediation',
            $model instanceof BlockadeReliefRequest => 'blockade_relief',
            $model instanceof CityGrantRequest => 'city_grants',
            $model instanceof GrantApplication => 'grants',
            $model instanceof Loan => 'loans',
            $model instanceof MemberTransfer => 'member_transfers',
            $model instanceof RebuildingRequest => 'rebuilding',
            $model instanceof Transaction => 'withdrawals',
            $model instanceof WarAidRequest => 'war_aid',
            default => throw new \LogicException('Unmapped Operations source model ['.$model::class.'].')
        };
    }
}
