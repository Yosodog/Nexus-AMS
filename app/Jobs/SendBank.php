<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Notifications\WithdrawalSentNotification;
use App\Services\BankService;
use App\Services\OffshoreFulfillmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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

            if ($transaction->requires_admin_approval || ! $transaction->is_pending) {
                return null;
            }

            if ($transaction->bank_processing_at) {
                return null;
            }

            $transaction->bank_processing_at = now();
            $transaction->save();

            return $transaction;
        });

        if (! $transaction) {
            return;
        }

        // Attempt to top up the main bank before issuing the withdrawal mutation.
        $result = $fulfillmentService->coverShortfall($transaction);

        $transaction->recordOffshoreFulfillment($result);

        if ($result->shouldSendWithdrawal()) {
            // Safe to proceed—the bank has either enough stock or was topped up successfully.
            try {
                $bankRecord = $this->bankService->sendWithdraw();
            } catch (\Throwable $exception) {
                DB::transaction(function () use ($transaction) {
                    $lockedTransaction = Transaction::query()
                        ->lockForUpdate()
                        ->find($transaction->id);

                    if (! $lockedTransaction
                        || $lockedTransaction->sent_at
                        || $lockedTransaction->bank_record_id) {
                        return;
                    }

                    $lockedTransaction->markPendingAdminReview(
                        'Automatic bank send failed. Manual review is required before retrying this withdrawal.'
                    );
                });

                report($exception);

                return;
            }

            DB::transaction(function () use ($transaction, $bankRecord) {
                $lockedTransaction = Transaction::query()
                    ->lockForUpdate()
                    ->find($transaction->id);

                if (! $lockedTransaction || $lockedTransaction->sent_at || $lockedTransaction->bank_record_id) {
                    return;
                }

                $lockedTransaction->setSent($bankRecord);
            });

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
        $transaction->markPendingAdminReview('Offshore fulfillment failed: '.$result->message);
    }
}
