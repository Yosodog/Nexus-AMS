<?php

namespace App\Jobs;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Exceptions\DefiniteMutationFailureException;
use App\Models\Transaction;
use App\Notifications\WithdrawalSentNotification;
use App\Services\AuditLogger;
use App\Services\BankService;
use App\Services\OffshoreFulfillmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendBank implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    protected BankService $bankService;

    protected Transaction $transaction;

    public int $uniqueFor = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(BankService $bankService, Transaction $transaction)
    {
        $this->bankService = $bankService;
        $this->transaction = $transaction;
    }

    public function uniqueId(): string
    {
        return 'withdrawal-'.$this->transaction->id;
    }

    /**
     * Execute the job.
     */
    public function handle(OffshoreFulfillmentService $fulfillmentService): void
    {
        $transaction = DB::transaction(function () {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->find($this->transaction->id);

            if (! $transaction) {
                return null;
            }

            if ($transaction->sent_at || $transaction->bank_record_id) {
                return null;
            }

            if ($transaction->refunded_at || $transaction->denied_at) {
                return null;
            }

            if (in_array($transaction->bank_attempt_status, [
                Transaction::BANK_ATTEMPT_PREPARING,
                Transaction::BANK_ATTEMPT_SENDING,
            ], true)) {
                $transaction->markBankNeedsReconciliation(
                    'A previous withdrawal attempt did not finish with a definitive result. Verify the correlation ID against Politics & War bank records before resolving it.'
                );

                return $transaction;
            }

            if ($transaction->requiresBankReconciliation()) {
                return null;
            }

            if ($transaction->requires_admin_approval || ! $transaction->is_pending) {
                return null;
            }

            if ($transaction->bank_processing_at) {
                return null;
            }

            $transaction->bank_processing_at = now();
            $transaction->beginBankPreparation();

            return $transaction;
        });

        if (! $transaction) {
            return;
        }

        if ($transaction->requiresBankReconciliation()) {
            $this->auditReconciliationRequired($transaction, 'interrupted_attempt');

            return;
        }

        try {
            // Attempt to top up the main bank before issuing the withdrawal mutation.
            $result = $fulfillmentService->coverShortfall($transaction);
            $transaction->recordOffshoreFulfillment($result);
        } catch (Throwable $exception) {
            $this->markNeedsReconciliation($transaction, $exception);
            report($exception);

            return;
        }

        if ($result->shouldSendWithdrawal()) {
            $transaction = DB::transaction(function () use ($transaction) {
                $lockedTransaction = Transaction::query()
                    ->lockForUpdate()
                    ->find($transaction->id);

                if (! $lockedTransaction
                    || $lockedTransaction->sent_at
                    || $lockedTransaction->bank_record_id
                    || $lockedTransaction->refunded_at
                    || $lockedTransaction->denied_at
                    || $lockedTransaction->requires_admin_approval
                    || $lockedTransaction->requiresBankReconciliation()
                    || ! $lockedTransaction->is_pending) {
                    return null;
                }

                $lockedTransaction->beginBankAttempt();

                return $lockedTransaction;
            });

            if (! $transaction) {
                return;
            }

            $this->bankService->note = $transaction->bankNoteWithCorrelation($this->bankService->note);

            // Safe to proceed—the bank has either enough stock or was topped up successfully.
            try {
                $bankRecord = $this->bankService->sendWithdraw();
            } catch (AmbiguousMutationOutcomeException $exception) {
                $this->markNeedsReconciliation($transaction, $exception);
                report($exception);

                return;
            } catch (DefiniteMutationFailureException $exception) {
                $this->markDefiniteFailure($transaction);
                report($exception);

                return;
            } catch (Throwable $exception) {
                $this->markNeedsReconciliation($transaction, $exception);
                report($exception);

                return;
            }

            $markedSent = false;

            DB::transaction(function () use ($transaction, $bankRecord, &$markedSent) {
                $lockedTransaction = Transaction::query()
                    ->lockForUpdate()
                    ->find($transaction->id);

                if (! $lockedTransaction
                    || $lockedTransaction->sent_at
                    || $lockedTransaction->bank_record_id
                    || $lockedTransaction->refunded_at
                    || $lockedTransaction->denied_at
                    || $lockedTransaction->requires_admin_approval
                    || ! $lockedTransaction->is_pending) {
                    return;
                }

                $lockedTransaction->setSent($bankRecord);
                $markedSent = true;
            });

            if (! $markedSent) {
                return;
            }

            $sentTransaction = Transaction::query()
                ->with(['nation', 'fromAccount'])
                ->find($transaction->id);

            if ($sentTransaction && $sentTransaction->nation) {
                $sentTransaction->nation->notify(new WithdrawalSentNotification(
                    nationId: $sentTransaction->nation_id,
                    transaction: $sentTransaction,
                    accountName: $sentTransaction->fromAccount?->name,
                ));
            }

            return;
        }

        // Something prevented us from fulfilling automatically. Escalate to admins.
        $this->markPreparationFailure(
            $transaction,
            'Offshore fulfillment failed: '.$result->message,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $transaction = Transaction::query()->find($this->transaction->id);

        if (! $transaction || ! in_array($transaction->bank_attempt_status, [
            Transaction::BANK_ATTEMPT_PREPARING,
            Transaction::BANK_ATTEMPT_SENDING,
        ], true)) {
            return;
        }

        $this->markNeedsReconciliation(
            $transaction,
            $exception ?? new \RuntimeException('The queued bank send ended without a definitive result.')
        );
    }

    private function markDefiniteFailure(Transaction $transaction): void
    {
        $failedTransaction = DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction
                || $lockedTransaction->sent_at
                || $lockedTransaction->bank_record_id
                || $lockedTransaction->refunded_at
                || $lockedTransaction->denied_at
                || $lockedTransaction->requiresBankReconciliation()) {
                return null;
            }

            $lockedTransaction->markDefiniteBankFailure(
                'The bank send was rejected before it could succeed. Manual review and approval are required before retrying this withdrawal.'
            );

            return $lockedTransaction;
        });

        if (! $failedTransaction) {
            return;
        }

        app(AuditLogger::class)->record(
            category: 'finance',
            action: 'withdrawal_send_failed_definitely',
            outcome: 'failure',
            severity: 'warning',
            subject: $failedTransaction,
            context: [
                'data' => [
                    'correlation_id' => $failedTransaction->bank_correlation_id,
                    'attempt_count' => $failedTransaction->bank_attempt_count,
                ],
            ],
            message: 'Withdrawal bank send failed before a confirmed upstream mutation.',
            actorOverride: ['type' => 'system'],
        );
    }

    private function markPreparationFailure(Transaction $transaction, string $reason): void
    {
        DB::transaction(function () use ($transaction, $reason): void {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction
                || $lockedTransaction->sent_at
                || $lockedTransaction->bank_record_id
                || $lockedTransaction->refunded_at
                || $lockedTransaction->denied_at
                || $lockedTransaction->requiresBankReconciliation()) {
                return;
            }

            $lockedTransaction->markDefiniteBankFailure($reason);
        });
    }

    private function markNeedsReconciliation(Transaction $transaction, Throwable $exception): void
    {
        $reconciliationTransaction = DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction
                || $lockedTransaction->sent_at
                || $lockedTransaction->bank_record_id
                || $lockedTransaction->refunded_at
                || $lockedTransaction->denied_at) {
                return null;
            }

            if (! $lockedTransaction->requiresBankReconciliation()) {
                $lockedTransaction->markBankNeedsReconciliation(
                    'The bank send may have succeeded, but no definitive response was received. Do not retry or refund until the correlation ID is verified against Politics & War bank records.'
                );
            }

            return $lockedTransaction;
        });

        if (! $reconciliationTransaction) {
            return;
        }

        $this->auditReconciliationRequired(
            $reconciliationTransaction,
            $exception::class,
        );
    }

    private function auditReconciliationRequired(Transaction $transaction, string $reason): void
    {
        app(AuditLogger::class)->record(
            category: 'finance',
            action: 'withdrawal_reconciliation_required',
            outcome: 'pending',
            severity: 'critical',
            subject: $transaction,
            context: [
                'data' => [
                    'correlation_id' => $transaction->bank_correlation_id,
                    'attempt_count' => $transaction->bank_attempt_count,
                    'reason' => $reason,
                ],
            ],
            message: 'Withdrawal outcome is ambiguous and requires evidence-based reconciliation.',
            actorOverride: ['type' => 'system'],
        );
    }
}
