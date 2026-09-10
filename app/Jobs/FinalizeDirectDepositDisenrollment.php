<?php

namespace App\Jobs;

use App\Exceptions\DefiniteMutationFailureException;
use App\Models\DirectDepositEnrollment;
use App\Services\AllianceMembershipService;
use App\Services\TaxBracketService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeDirectDepositDisenrollment implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $enrollmentId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $enrollment = DirectDepositEnrollment::query()->find($this->enrollmentId);

        if (! $enrollment || $enrollment->disenrollment_requested_at === null) {
            return;
        }

        $targetTaxId = (int) $enrollment->previous_tax_id;
        $fallbackTaxId = (int) $enrollment->fallback_tax_id;

        if ($targetTaxId <= 0) {
            $targetTaxId = $fallbackTaxId;
        }

        try {
            $this->assignTaxBracket($enrollment, $targetTaxId);
        } catch (DefiniteMutationFailureException $exception) {
            if ($fallbackTaxId <= 0 || $fallbackTaxId === $targetTaxId) {
                throw $exception;
            }

            Log::warning('Direct Deposit previous tax bracket assignment failed; trying the configured fallback.', [
                'enrollment_id' => $enrollment->id,
                'nation_id' => $enrollment->nation_id,
                'offshore_id' => $enrollment->offshore_id,
                'previous_tax_id' => $targetTaxId,
                'fallback_tax_id' => $fallbackTaxId,
            ]);

            $this->assignTaxBracket($enrollment, $fallbackTaxId);
        }

        $deleted = DB::transaction(function (): bool {
            $lockedEnrollment = DirectDepositEnrollment::query()
                ->whereKey($this->enrollmentId)
                ->lockForUpdate()
                ->first();

            if ($lockedEnrollment?->disenrollment_requested_at !== null) {
                $lockedEnrollment->delete();

                return true;
            }

            return false;
        });

        if ($deleted) {
            app(AllianceMembershipService::class)->clear();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $enrollment = DirectDepositEnrollment::query()->find($this->enrollmentId);

        Log::error('Direct Deposit disenrollment could not be completed.', [
            'enrollment_id' => $this->enrollmentId,
            'nation_id' => $enrollment?->nation_id,
            'offshore_id' => $enrollment?->offshore_id,
            'exception_class' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function assignTaxBracket(DirectDepositEnrollment $enrollment, int $taxId): void
    {
        if ($taxId <= 0) {
            throw new DefiniteMutationFailureException('No usable fallback tax bracket is available.');
        }

        $mutation = new TaxBracketService;
        $mutation->id = $taxId;
        $mutation->target_id = (int) $enrollment->nation_id;
        $mutation->offshore_id = $enrollment->offshore_id;
        $mutation->sendAssign();
    }
}
