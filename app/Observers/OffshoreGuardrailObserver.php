<?php

namespace App\Observers;

use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use App\Services\OffshoreService;
use Illuminate\Support\Facades\Log;

class OffshoreGuardrailObserver
{
    public function __construct(private readonly OffshoreService $offshoreService)
    {
    }

    public function created(OffshoreGuardrail $guardrail): void
    {
        $this->flushCache($guardrail);
        $this->log('created', $guardrail);
    }

    public function updated(OffshoreGuardrail $guardrail): void
    {
        $this->flushCache($guardrail);
        $this->log('updated', $guardrail);
    }

    public function deleted(OffshoreGuardrail $guardrail): void
    {
        $this->flushCache($guardrail);
        $this->log('deleted', $guardrail);
    }

    public function forceDeleted(OffshoreGuardrail $guardrail): void
    {
        $this->flushCache($guardrail);
        $this->log('force-deleted', $guardrail);
    }

    protected function flushCache(OffshoreGuardrail $guardrail): void
    {
        $offshore = $this->resolveOffshore($guardrail);

        if ($offshore) {
            $this->offshoreService->clearCaches($offshore);
        }
    }

    protected function resolveOffshore(OffshoreGuardrail $guardrail): ?Offshore
    {
        if ($guardrail->relationLoaded('offshore')) {
            return $guardrail->getRelation('offshore');
        }

        return $guardrail->offshore()->first();
    }

    protected function log(string $action, OffshoreGuardrail $guardrail): void
    {
        Log::info("Offshore guardrail {$action}", [
            'guardrail_id' => $guardrail->id,
            'offshore_id' => $guardrail->offshore_id,
            'resource' => $guardrail->resource,
            'minimum_amount' => $guardrail->minimum_amount,
        ]);
    }
}
